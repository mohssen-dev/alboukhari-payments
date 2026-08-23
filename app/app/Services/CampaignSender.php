<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\MessageLog;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

class CampaignSender
{
    /** A recipient is abandoned after this many failed attempts. */
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private BulkGateClient $client,
    ) {}

    /**
     * يرسل الـ campaign دفعة واحدة (للحملات الصغيرة < 500).
     * للحملات الكبيرة، الأفضل استخدام Queue Job.
     */
    public function sendCampaign(Campaign $campaign): array
    {
        if (HaltService::isHalted()) {
            return ['status' => 'halted', 'sent' => 0];
        }

        $campaign->update(['status' => 'running', 'started_at' => $campaign->started_at ?? now()]);
        $sent = 0;
        $failed = 0;

        // Reclaim recipients stranded mid-flight by a previous run.
        //
        // A recipient is flipped to 'sending' right before the API call, so if
        // the process is killed there (cron timeout, PHP fatal, deploy) it
        // stays 'sending' forever: never retried, and never counted as
        // remaining — which also let the campaign be stamped 'completed' while
        // those parents were never contacted. Anything left 'sending' from an
        // earlier run is put back in the queue, bounded by attempts so a number
        // that reliably kills the worker cannot loop.
        CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'sending')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->update(['status' => 'pending']);

        CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'sending')
            ->where('attempts', '>=', self::MAX_ATTEMPTS)
            ->update(['status' => 'failed', 'last_error' => 'abandoned after ' . self::MAX_ATTEMPTS . ' attempts']);

        $pending = CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->get();

        foreach ($pending as $recipient) {
            if (HaltService::isHalted()) {
                break;
            }
            if (!HaltService::tryTakeQuota(1)) {
                $campaign->update(['status' => 'paused']);
                return ['status' => 'quota_exceeded', 'sent' => $sent, 'failed' => $failed];
            }

            $recipient->update(['status' => 'sending', 'attempts' => $recipient->attempts + 1]);

            try {
                $result = $this->client->send(
                    $recipient->phone_e164,
                    $recipient->body_personalized,
                    $campaign->tag
                );

                $price = (float) Setting::get('bulkgate_price_per_sms', '0.08');
                $cost = $price * $recipient->segments;

                $recipient->update([
                    'status' => 'sent',
                    'provider_status' => $result['status'],
                    'cost' => $cost,
                    'sent_at' => now(),
                ]);

                MessageLog::create([
                    'campaign_id' => $campaign->id,
                    'student_id' => $recipient->student_id,
                    'family_id' => $recipient->family_id,
                    'type' => $campaign->type,
                    'provider' => 'bulkgate',
                    'phone' => $recipient->phone_e164,
                    'body' => $recipient->body_personalized,
                    'segments' => $recipient->segments,
                    'status' => $result['status'],
                    'cost' => $cost,
                    'provider_response' => $result['body'] ?? null,
                    'tag' => $campaign->tag,
                ]);

                $sent++;
                $campaign->increment('sent_count');
                $campaign->increment('actual_cost', $cost);

            } catch (\Throwable $e) {
                $em = $e->getMessage();
                $recipient->update([
                    'status' => 'failed',
                    'last_error' => $em,
                ]);
                MessageLog::create([
                    'campaign_id' => $campaign->id,
                    'student_id' => $recipient->student_id,
                    'family_id' => $recipient->family_id,
                    'type' => $campaign->type,
                    'provider' => 'bulkgate',
                    'phone' => $recipient->phone_e164,
                    'body' => $recipient->body_personalized,
                    'segments' => $recipient->segments,
                    'status' => 'ERROR: ' . $em,
                    'tag' => $campaign->tag,
                ]);
                $failed++;
                $campaign->increment('failed_count');

                // إذا كان rate limit أو quota، نوقف فوراً
                if (str_contains($em, 'RATE_LIMIT') || str_contains($em, 'QUOTA')) {
                    $campaign->update(['status' => 'paused']);
                    return ['status' => 'quota_exceeded', 'sent' => $sent, 'failed' => $failed];
                }
            }
        }

        // Final state. 'sending' counts as unfinished too: counting only
        // 'pending' meant a campaign whose last recipients died mid-call was
        // stamped 'completed' even though nobody had been reached.
        $remaining = CampaignRecipient::where('campaign_id', $campaign->id)
            ->whereIn('status', ['pending', 'sending'])
            ->count();

        if ($remaining === 0) {
            $totalFailed = CampaignRecipient::where('campaign_id', $campaign->id)
                ->where('status', 'failed')->count();
            $totalSent = CampaignRecipient::where('campaign_id', $campaign->id)
                ->where('status', 'sent')->count();

            // Every single message failing is not a completed campaign.
            $campaign->update([
                'status' => ($totalSent === 0 && $totalFailed > 0) ? 'failed' : 'completed',
                'finished_at' => now(),
            ]);
        }

        return ['status' => 'done', 'sent' => $sent, 'failed' => $failed, 'remaining' => $remaining];
    }

    /**
     * يرسل رسالة فردية فوراً (لا campaign).
     */
    public function sendSingle(int $studentId, string $body, ?string $tag = null): array
    {
        $student = \App\Models\Student::findOrFail($studentId);
        if (HaltService::isHalted()) {
            throw new \RuntimeException(__('error.send_halted_by_admin'));
        }
        if (!$student->canReceiveMessages()) {
            throw new \RuntimeException(__('error.student_cannot_receive') . ' ' . $student->skipReason());
        }
        if (!HaltService::tryTakeQuota(1)) {
            throw new \RuntimeException(__('error.hourly_quota_exceeded'));
        }

        $count = \App\Support\SmsCounter::count($body, Setting::get('force_ascii','1') === '1');
        $price = (float) Setting::get('bulkgate_price_per_sms', '0.08');
        $cost = $price * $count['segments'];

        try {
            $result = $this->client->send($student->phone_primary_e164, $body, $tag);

            MessageLog::create([
                'student_id' => $student->id,
                'family_id' => $student->family_id,
                'type' => 'manual_single',
                'provider' => 'bulkgate',
                'phone' => $student->phone_primary_e164,
                'body' => $body,
                'segments' => $count['segments'],
                'status' => $result['status'],
                'cost' => $cost,
                'provider_response' => $result['body'] ?? null,
                'tag' => $tag,
            ]);

            return ['status' => 'sent', 'provider_status' => $result['status'], 'cost' => $cost, 'segments' => $count['segments']];
        } catch (\Throwable $e) {
            MessageLog::create([
                'student_id' => $student->id,
                'family_id' => $student->family_id,
                'type' => 'manual_single',
                'provider' => 'bulkgate',
                'phone' => $student->phone_primary_e164,
                'body' => $body,
                'segments' => $count['segments'],
                'status' => 'ERROR: ' . $e->getMessage(),
                'tag' => $tag,
            ]);
            throw $e;
        }
    }
}
