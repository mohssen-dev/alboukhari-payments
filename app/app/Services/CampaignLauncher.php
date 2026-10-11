<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Starts a campaign that is waiting: "send now" on the campaigns pages, and
 * the scheduler when a scheduled campaign is due.
 *
 * A campaign waits when it was made while sending was halted (⛔), when the
 * hourly quota paused it, or when it is scheduled. A halted one used to wait
 * forever: the scheduler only fires campaigns that have a time, and nothing
 * on screen could start it.
 */
class CampaignLauncher
{
    /** A 'running' campaign untouched for this long is treated as interrupted. */
    public const STALE_RUNNING_MINUTES = 15;

    /**
     * Types whose list is worked out from the campaign's own settings, so it
     * can be rebuilt at launch. 'specific_students' is picked by hand — a
     * rebuild would match EVERY student — so it is never rebuilt.
     */
    public const REBUILDABLE_TYPES = [
        'send_all', 'unpaid_by_month', 'late_mid_month', 'paid_less_than',
        'balance_above', 'first_friday', 'mid_month_auto',
    ];

    /** Can this campaign be sent now from the campaigns pages? */
    public static function canSendNow(Campaign $campaign): bool
    {
        if (in_array($campaign->status, ['queued', 'paused'], true)) {
            return true;
        }

        // A batch whose process died part-way — the scheduler's rule too.
        return $campaign->status === 'running'
            && $campaign->updated_at < now()->subMinutes(self::STALE_RUNNING_MINUTES)
            && $campaign->recipients()->whereIn('status', ['pending', 'sending'])->exists();
    }

    /** Can this campaign still be canceled (nothing more will be sent)? */
    public static function canCancel(Campaign $campaign): bool
    {
        return in_array($campaign->status, ['queued', 'paused'], true);
    }

    /**
     * Send a waiting campaign now. One that never started gets its list
     * rebuilt first, as a scheduled one does when it fires, so families who
     * paid since it was made are left out.
     *
     * @return array{status: string, sent?: int, failed?: int, remaining?: int, dropped: int, added: int}
     */
    public function sendNow(Campaign $campaign): array
    {
        $changes = ['dropped' => 0, 'added' => 0];

        if (HaltService::isHalted()) {
            return ['status' => 'halted'] + $changes;
        }
        if (!self::canSendNow($campaign)) {
            return ['status' => 'not_allowed'] + $changes;
        }

        if ($campaign->status === 'queued') {
            // Claim it first: a double click or a scheduler tick cannot start it twice.
            $claimed = Campaign::where('id', $campaign->id)
                ->where('status', 'queued')
                ->update(['status' => 'running', 'started_at' => now()]);
            if (!$claimed) {
                return ['status' => 'busy'] + $changes;
            }
            $campaign->refresh();

            try {
                $changes = $this->rebuildRecipients($campaign);
            } catch (\Throwable $e) {
                // Put it back as it was, so it can be tried again.
                $campaign->update(['status' => 'queued', 'started_at' => null]);
                throw $e;
            }
        }

        return app(CampaignSender::class)->sendCampaign($campaign) + $changes;
    }

    /**
     * Rebuild a campaign's list from its settings, as it would be built now:
     * new matches are added, recipients still waiting who no longer match
     * (they paid meanwhile) are skipped, and waiting messages are rendered
     * again so the amounts in them are current. Recipients already sent are
     * never touched. Hand-picked types are left as they are.
     *
     * @return array{dropped: int, added: int}
     */
    public function rebuildRecipients(Campaign $campaign): array
    {
        if (!in_array($campaign->type, self::REBUILDABLE_TYPES, true)) {
            return ['dropped' => 0, 'added' => 0];
        }

        $built = (new RecipientListBuilder())->build($campaign);
        $keys = array_column($built['recipients'], 'idempotency_key');

        return DB::transaction(function () use ($campaign, $built, $keys) {
            $dropped = CampaignRecipient::where('campaign_id', $campaign->id)
                ->where('status', 'pending')
                ->whereNotIn('idempotency_key', $keys)
                ->update(['status' => 'skipped', 'last_error' => __('campaign.skipped_no_longer_due')]);

            $added = 0;
            foreach ($built['recipients'] as $rec) {
                $row = CampaignRecipient::firstOrCreate(
                    ['idempotency_key' => $rec['idempotency_key']],
                    [
                        'campaign_id' => $campaign->id,
                        'student_id' => $rec['student_id'] ?? null,
                        'family_id' => $rec['family_id'] ?? null,
                        'phone_e164' => $rec['phone'],
                        'body_personalized' => $rec['body'],
                        'status' => 'pending',
                        'segments' => $rec['segments'],
                    ]
                );
                if ($row->wasRecentlyCreated) {
                    $added++;
                } elseif ($row->status === 'pending') {
                    $row->update([
                        'phone_e164' => $rec['phone'],
                        'body_personalized' => $rec['body'],
                        'segments' => $rec['segments'],
                    ]);
                }
            }

            $campaign->update([
                'total_recipients' => $built['stats']['total_recipients'],
                'estimated_cost' => (float) Setting::get('bulkgate_price_per_sms', '0.08') * $built['stats']['total_segments'],
                'skipped_count' => $campaign->skipped_count + $dropped,
            ]);

            return ['dropped' => $dropped, 'added' => $added];
        });
    }

    /** What happened, in a sentence for the person who pressed the button. */
    public static function resultMessage(array $result): string
    {
        $message = match ($result['status'] ?? '') {
            'done' => __('campaign.send_done', ['sent' => $result['sent'] ?? 0, 'failed' => $result['failed'] ?? 0]),
            'handed_to_device' => __('campaign.send_to_device', ['count' => $result['remaining'] ?? 0]),
            'quota_exceeded' => __('campaign.send_quota', ['sent' => $result['sent'] ?? 0]),
            'halted' => __('campaign.send_halted'),
            'busy' => __('campaign.send_busy'),
            default => __('campaign.send_not_allowed'),
        };

        if (($result['dropped'] ?? 0) > 0) {
            $message .= ' · ' . __('campaign.send_dropped', ['count' => $result['dropped']]);
        }

        return $message;
    }

    /** Toast colour for a result. */
    public static function resultType(array $result): string
    {
        return match ($result['status'] ?? '') {
            'done' => ($result['sent'] ?? 0) === 0 && ($result['failed'] ?? 0) > 0 ? 'error' : 'success',
            'handed_to_device' => 'success',
            'quota_exceeded', 'busy' => 'warning',
            default => 'error',
        };
    }
}
