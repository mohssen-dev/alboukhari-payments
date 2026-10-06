<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\SenderDevice;
use App\Support\PhoneNormalizer;
use App\Support\SmsCounter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ينشئ حملة اختبار موجّهة لهاتف مرسِل (بديل مؤقت لمحدّد القناة في صفحة الإرسال).
 *
 *   php artisan sender:test-campaign 1 +31612345678 --channel=sms --body="Test {{n}}"
 *   php artisan sender:test-campaign 1 +31612345678,+31687654321 --channel=whatsapp
 */
class SenderTestCampaign extends Command
{
    protected $signature = 'sender:test-campaign
        {device : معرّف الهاتف (sender:devices)}
        {phones : رقم أو أرقام مفصولة بفاصلة}
        {--channel=sms : sms أو whatsapp}
        {--body= : نص الرسالة ({{n}} يُستبدل برقم تسلسلي)}';

    protected $description = 'ينشئ حملة اختبار (نوع specific_students) موجّهة لهاتف مرسِل.';

    public function handle(): int
    {
        $device = SenderDevice::find((int) $this->argument('device'));
        if (! $device) {
            $this->error('Device not found. Run: php artisan sender:devices');

            return self::FAILURE;
        }

        $channel = $this->option('channel') === 'whatsapp' ? Campaign::CHANNEL_DEVICE_WHATSAPP : Campaign::CHANNEL_DEVICE_SMS;
        $body = $this->option('body') ?: 'Test from Al Boukhari payments — {{n}} — ' . now()->format('H:i:s');

        $phones = collect(explode(',', (string) $this->argument('phones')))
            ->map(fn ($p) => PhoneNormalizer::normalize(trim($p)))
            ->filter(fn ($p) => PhoneNormalizer::isValid($p))
            ->values();

        if ($phones->isEmpty()) {
            $this->error('No valid phone numbers.');

            return self::FAILURE;
        }

        $campaign = DB::transaction(function () use ($device, $channel, $body, $phones) {
            $campaign = Campaign::create([
                'type' => 'specific_students',
                'status' => 'queued',
                'channel' => $channel,
                'sender_device_id' => $device->id,
                'period_year' => (int) date('Y'),
                'period_month' => (int) date('n'),
                'body_template' => $body,
                'tag' => 'DEVICE_TEST',
                'total_recipients' => $phones->count(),
            ]);

            foreach ($phones as $i => $phone) {
                $text = str_replace('{{n}}', (string) ($i + 1), $body);
                CampaignRecipient::create([
                    'campaign_id' => $campaign->id,
                    'phone_e164' => $phone,
                    'body_personalized' => $text,
                    'status' => 'pending',
                    'segments' => SmsCounter::count($text, false)['segments'],
                    'idempotency_key' => hash('sha1', "{$campaign->id}|test|{$phone}|{$i}"),
                ]);
            }

            return $campaign;
        });

        $this->info("Campaign #{$campaign->id} created ({$channel}) for device #{$device->id} \"{$device->name}\" with {$phones->count()} recipient(s).");
        $this->line('The phone will pick it up on its next sync (standby mode must be on).');

        return self::SUCCESS;
    }
}
