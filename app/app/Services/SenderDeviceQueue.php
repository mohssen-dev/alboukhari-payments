<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\MessageLog;
use App\Models\SenderDevice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * طابور الهاتف المرسِل: حجز الرسائل، استقبال النتائج، إغلاق الحملات.
 *
 * يعمل على نفس جدول campaign_recipients الذي يستخدمه BulkGate، بنفس الحالات
 * (pending → sending → sent/failed/skipped)، فصفحة الحملة في الويب تعرض تقدّم
 * الهاتف دون أي تغيير.
 */
class SenderDeviceQueue
{
    /**
     * يحجز حتى $limit رسالة معلّقة من حملات هذا الهاتف ويعيدها بصيغة التطبيق.
     *
     * @param  array<string>|null  $channels  'sms' و/أو 'whatsapp' (null = كلاهما)
     */
    public function claim(SenderDevice $device, int $limit = 50, ?array $channels = null): array
    {
        if (HaltService::isHalted()) {
            return [];
        }

        $this->requeueStale($device);

        $channelValues = collect($channels ?: ['sms', 'whatsapp'])
            ->map(fn ($c) => $c === 'whatsapp' ? Campaign::CHANNEL_DEVICE_WHATSAPP : Campaign::CHANNEL_DEVICE_SMS)
            ->unique()
            ->values()
            ->all();

        return DB::transaction(function () use ($device, $limit, $channelValues) {
            $campaignIds = Campaign::query()
                ->where('sender_device_id', $device->id)
                ->whereIn('channel', $channelValues)
                ->whereIn('status', ['queued', 'running'])
                ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()))
                ->pluck('id');

            if ($campaignIds->isEmpty()) {
                return [];
            }

            $rows = CampaignRecipient::query()
                ->whereIn('campaign_id', $campaignIds)
                ->where('status', 'pending')
                ->orderBy('campaign_id')
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                return [];
            }

            $now = now();
            CampaignRecipient::whereIn('id', $rows->pluck('id'))->update([
                'status' => 'sending',
                'claimed_at' => $now,
                'sender_device_id' => $device->id,
                'attempts' => DB::raw('attempts + 1'),
            ]);

            // الحملة تصبح "جارية" مع أول حجز.
            Campaign::whereIn('id', $rows->pluck('campaign_id')->unique())
                ->where('status', 'queued')
                ->update(['status' => 'running', 'started_at' => $now]);

            $rows->load(['campaign', 'student', 'family.students', 'whatsappGroup']);

            return $rows->map(fn (CampaignRecipient $r) => $this->toJob($r))->values()->all();
        });
    }

    /**
     * يستقبل النتائج النهائية من الهاتف. idempotent: تكرار نفس النتيجة لا يغيّر شيئاً.
     *
     * @param  array<int, array{id:int, status:string, error?:?string, attempts?:?int, sent_at?:?string}>  $results
     * @return array{accepted:int, duplicate:int, unknown:int}
     */
    public function report(SenderDevice $device, array $results): array
    {
        $stats = ['accepted' => 0, 'duplicate' => 0, 'unknown' => 0, 'upgraded' => 0];
        $touchedCampaigns = [];

        foreach ($results as $res) {
            $recipient = CampaignRecipient::with('campaign')
                ->where('id', (int) ($res['id'] ?? 0))
                ->where('sender_device_id', $device->id)
                ->first();

            if (! $recipient || ! $recipient->campaign) {
                $stats['unknown']++;
                continue;
            }

            $status = in_array($res['status'] ?? '', CampaignRecipient::FINAL_STATUSES, true) ? $res['status'] : 'failed';

            if ($recipient->isFinal()) {
                // الحالة الوحيدة التي نقبل فيها تغيير نتيجة نهائية: إعادة محاولة يدوية من
                // الهاتف نجحت بعد فشل/تخطٍّ سابق. أي تكرار آخر يُتجاهل (idempotent).
                $upgrade = $status === 'sent' && in_array($recipient->status, ['failed', 'skipped'], true);
                if (! $upgrade || $recipient->campaign->status === 'canceled') {
                    $stats['duplicate']++;
                    continue;
                }
                $recipient->campaign->decrement($recipient->status === 'failed' ? 'failed_count' : 'skipped_count');
                $stats['upgraded']++;
            }

            $error = isset($res['error']) ? mb_substr((string) $res['error'], 0, 500) : null;
            $sentAt = $status === 'sent' ? $this->parseTime($res['sent_at'] ?? null) : null;

            $recipient->update([
                'status' => $status,
                'last_error' => $status === 'sent' ? null : $error,
                'sent_at' => $sentAt,
                'provider_status' => strtoupper($status),
                'attempts' => max((int) $recipient->attempts, (int) ($res['attempts'] ?? 0)),
            ]);

            $campaign = $recipient->campaign;
            MessageLog::create([
                'campaign_id' => $campaign->id,
                'student_id' => $recipient->student_id,
                'family_id' => $recipient->family_id,
                'type' => $campaign->type,
                'provider' => $campaign->providerName(),
                'phone' => $recipient->deviceTarget(),
                'body' => $recipient->body_personalized,
                'segments' => $recipient->segments,
                'status' => match ($status) {
                    'sent' => 'SENT',
                    'skipped' => 'SKIPPED: ' . ($error ?? ''),
                    default => 'ERROR: ' . ($error ?? 'unknown'),
                },
                'cost' => 0,
                'provider_response' => ['sender_device_id' => $device->id, 'reported_at' => now()->toIso8601String()],
                'tag' => $campaign->tag,
            ]);

            $campaign->increment(match ($status) {
                'sent' => 'sent_count',
                'skipped' => 'skipped_count',
                default => 'failed_count',
            });

            $touchedCampaigns[$campaign->id] = $campaign;
            $stats['accepted']++;
        }

        foreach ($touchedCampaigns as $campaign) {
            $this->finalizeIfDone($campaign->fresh());
        }

        return $stats;
    }

    /** كم رسالة لا تزال بانتظار هذا الهاتف (لعرضها في التطبيق والويب). */
    public function pendingCount(SenderDevice $device): int
    {
        return CampaignRecipient::query()
            ->where('status', 'pending')
            ->whereIn('campaign_id', Campaign::where('sender_device_id', $device->id)
                ->whereIn('channel', Campaign::DEVICE_CHANNELS)
                ->whereIn('status', ['queued', 'running'])
                ->select('id'))
            ->count();
    }

    /**
     * A message the phone claimed is the phone's until it reports it — even
     * for hours: outside the sending window at night, with the screen locked,
     * or paused, it still holds it in its own queue and sends it later.
     *
     * This used to take claims back after 30 minutes and count each take-back
     * as an attempt, so after three nights a message the phone was about to
     * send was marked failed. Only a claim with no report for a whole day is
     * offered again — the phone has lost it (app data cleared). Offering it
     * again is harmless: the phone ignores a job it already holds (same id).
     */
    public const STALE_CLAIM_HOURS = 24;

    public function requeueStale(SenderDevice $device): int
    {
        return CampaignRecipient::query()
            ->where('sender_device_id', $device->id)
            ->where('status', 'sending')
            ->where('claimed_at', '<', now()->subHours(self::STALE_CLAIM_HOURS))
            ->update(['status' => 'pending', 'claimed_at' => null]);
    }

    /**
     * The phone is unpaired, disabled or deleted: everything it claimed but
     * never reported goes back to the queue, so the campaign can be handed to
     * another phone.
     */
    public function releaseClaims(SenderDevice $device): int
    {
        return CampaignRecipient::query()
            ->where('sender_device_id', $device->id)
            ->where('status', 'sending')
            ->update(['status' => 'pending', 'claimed_at' => null, 'sender_device_id' => null]);
    }

    /** نفس قاعدة CampaignSender: لا pending/sending → completed (أو failed إن لم تنجح أي رسالة). */
    public function finalizeIfDone(Campaign $campaign): void
    {
        $remaining = CampaignRecipient::where('campaign_id', $campaign->id)
            ->whereIn('status', ['pending', 'sending'])->count();
        if ($remaining > 0) {
            return;
        }

        $sent = CampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'sent')->count();
        $failed = CampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'failed')->count();

        $campaign->update([
            'status' => ($sent === 0 && $failed > 0) ? 'failed' : 'completed',
            'finished_at' => now(),
        ]);
    }

    private function toJob(CampaignRecipient $r): array
    {
        $name = $r->student?->name
            ?? ($r->family ? $r->family->displayName() : '');

        return [
            'id' => $r->id,
            'campaign_id' => $r->campaign_id,
            'channel' => $r->campaign->deviceChannelName(),
            'to' => $r->deviceTarget(),
            'name' => $name,
            'body' => $r->body_personalized,
            'segments' => (int) $r->segments,
            'tag' => $r->campaign->tag,
        ];
    }

    private function parseTime(?string $iso): Carbon
    {
        if ($iso) {
            try {
                return Carbon::parse($iso);
            } catch (\Throwable) {
                // نستخدم وقت الخادم
            }
        }

        return now();
    }
}
