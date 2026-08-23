<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Setting;
use App\Services\CampaignSender;
use App\Services\HaltService;
use App\Services\RecipientListBuilder;
use Illuminate\Console\Command;

class DispatchScheduledCampaigns extends Command
{
    protected $signature = 'campaigns:dispatch-scheduled';
    protected $description = 'يطلق الحملات المجدولة التي حان وقتها (يُشغَّل كل دقيقة عبر الـ scheduler).';

    /** A 'running' campaign untouched for this long is treated as interrupted. */
    private const STALE_RUNNING_MINUTES = 15;

    public function handle(): int
    {
        if (HaltService::isHalted()) {
            // Leave due campaigns queued — they fire on the first tick after resume.
            $this->line('Sending is halted; scheduled campaigns deferred.');
            return self::SUCCESS;
        }

        // Resume campaigns that stopped part-way BEFORE starting new ones —
        // half-messaged parents come first.
        //
        // A campaign is set to 'paused' when the hourly quota runs out or the
        // provider rate-limits, and to 'running' while a batch is in flight.
        // Nothing ever moved either state forward again: a quota-paused
        // campaign was dead permanently, so some parents got the message and
        // the rest never did. Resuming here is safe because sendCampaign()
        // only picks up recipients still 'pending' and requeues ones stranded
        // at 'sending', and the hourly quota still gates every send.
        $this->resumeInterrupted();

        $due = Campaign::query()
            ->where('status', 'queued')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get();

        if ($due->isEmpty()) {
            $this->line('No scheduled campaigns due.');
            return self::SUCCESS;
        }

        foreach ($due as $campaign) {
            // Atomic claim — if a slow previous tick is still working on this
            // campaign, the UPDATE matches 0 rows and we skip it.
            $claimed = Campaign::where('id', $campaign->id)
                ->where('status', 'queued')
                ->update(['status' => 'running', 'started_at' => now()]);
            if (!$claimed) {
                continue;
            }

            $this->info("Firing scheduled campaign #{$campaign->id} ({$campaign->type})…");

            try {
                // Recipients are built NOW, not at scheduling time, so students
                // who paid meanwhile drop off dunning lists automatically.
                $builder = new RecipientListBuilder();
                $r = $builder->build($campaign);

                $campaign->update([
                    'total_recipients' => $r['stats']['total_recipients'],
                    'estimated_cost' => (float) Setting::get('bulkgate_price_per_sms', '0.08') * $r['stats']['total_segments'],
                ]);

                foreach ($r['recipients'] as $rec) {
                    CampaignRecipient::firstOrCreate(
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
                }

                $result = app(CampaignSender::class)->sendCampaign($campaign);
                $this->info("Campaign #{$campaign->id}: " . json_encode($result));
            } catch (\Throwable $e) {
                report($e);
                $campaign->update(['status' => 'failed']);
                $this->error("Campaign #{$campaign->id} failed: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Pick campaigns back up after a quota pause or an interrupted batch.
     *
     * 'paused'  — the hourly quota or a provider rate-limit stopped the batch.
     *             Retrying costs nothing when the quota is still exhausted:
     *             sendCampaign() takes the quota per message and pauses again.
     * 'running' — a batch that has not been touched for STALE_RUNNING_MINUTES
     *             means the process died (cron timeout, deploy, fatal). Its
     *             stranded recipients are requeued inside sendCampaign().
     */
    private function resumeInterrupted(): void
    {
        $interrupted = Campaign::query()
            ->where(function ($q) {
                $q->where('status', 'paused')
                  ->orWhere(function ($w) {
                      $w->where('status', 'running')
                        ->where('updated_at', '<', now()->subMinutes(self::STALE_RUNNING_MINUTES));
                  });
            })
            ->whereHas('recipients', fn ($q) => $q->whereIn('status', ['pending', 'sending']))
            ->orderBy('id')
            ->get();

        foreach ($interrupted as $campaign) {
            $this->info("Resuming interrupted campaign #{$campaign->id} (was {$campaign->status})…");
            try {
                $result = app(CampaignSender::class)->sendCampaign($campaign);
                $this->info("  → " . json_encode($result));
            } catch (\Throwable $e) {
                report($e);
                $this->error("  → resume failed: {$e->getMessage()}");
            }
        }
    }
}
