<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Setting;
use App\Models\Template;
use App\Services\CampaignSender;
use App\Services\RecipientListBuilder;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RemindersDispatcher extends Command
{
    protected $signature = 'reminders:dispatch {--force-type= : أجبر تشغيل first_friday أو mid_month_auto الآن}';
    protected $description = 'يفحص اليوم والوقت ويُطلق التذكيرات الدورية إذا كانت مفعّلة (يستبدل remindersDailyDispatcher).';

    public function handle(): int
    {
        $today = Carbon::today();
        $forced = $this->option('force-type');

        $first = $forced === 'first_friday' || (
            Setting::get('trigger_first_friday_enabled', '1') === '1'
            && $today->dayOfWeek === Carbon::FRIDAY
            && $today->day <= 7
        );

        $mid = $forced === 'mid_month_auto' || (
            Setting::get('trigger_mid_month_enabled', '1') === '1'
            && $today->day === 15
        );

        if ($first) {
            $this->info('Triggering: first_friday');
            $this->launch('first_friday', 'nl_first_friday');
        }
        if ($mid) {
            $this->info('Triggering: mid_month_auto');
            $this->launch('mid_month_auto', 'nl_mid_month');
        }
        if (!$first && !$mid) {
            $this->line('Nothing to trigger today.');
        }
        return self::SUCCESS;
    }

    private function launch(string $type, string $templateCode): void
    {
        $template = Template::where('code', $templateCode)->first();
        if (!$template) {
            $this->error("Template $templateCode not found.");
            return;
        }
        $year = (int) date('Y');
        $month = (int) date('n');

        $campaign = Campaign::create([
            'type' => $type,
            'status' => 'queued',
            'period_year' => $year,
            'period_month' => $month,
            'template_id' => $template->id,
            'body_template' => $template->messageBody(), // Dutch + Arabic translation when enabled
            'group_by_family' => false,
            'tag' => $type . '-auto-' . date('Ymd'),
        ]);

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

        $sender = app(CampaignSender::class);
        $result = $sender->sendCampaign($campaign);
        $this->info("Campaign #{$campaign->id}: " . json_encode($result));
    }
}
