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

    public function handle(): int
    {
        if (HaltService::isHalted()) {
            // Leave due campaigns queued — they fire on the first tick after resume.
            $this->line('Sending is halted; scheduled campaigns deferred.');
            return self::SUCCESS;
        }

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
}
