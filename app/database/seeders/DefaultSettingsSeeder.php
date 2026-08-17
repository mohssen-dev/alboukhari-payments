<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class DefaultSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // ====== General ======
            'default_monthly_fee' => '30.00',
            'currency' => 'EUR',
            'school_year_start_month' => '1',
            'halt_sending' => '0',

            // ====== BulkGate (متطابقة مع السكربت الأصلي) ======
            'bulkgate_app_id' => '',
            'bulkgate_app_token' => '',
            // BulkGate v2 sender types are gSystem/gShort/gText/gMobile/gOwn —
            // the working Apps Script used 'gText' ('text' is not valid).
            'bulkgate_sender_id' => 'gText',
            'bulkgate_sender_id_value' => 'Al Boukhari',     // من السكربت
            'bulkgate_default_country' => 'NL',              // من السكربت
            'bulkgate_max_per_hour' => '2500',               // BG_MAX_PER_HOUR
            'bulkgate_price_per_sms' => '0.08',
            'force_ascii' => '1',                            // FORCE_ASCII = on
            'batch_size' => '150',                           // BATCH_SIZE
            'sleep_between_batch_ms' => '5000',              // SLEEP_BETWEEN_BATCH_MS
            'resume_delay_minutes' => '65',                  // RESUME_DELAY_MINUTES
            'retry_short_minutes' => '3',                    // RETRY_SHORT_MINUTES
            'max_per_tick' => '0',                           // MAX_PER_TICK (0 = unlimited)
            'checkpoint_every' => '25',                      // CHECKPOINT_EVERY

            // ====== Reminders Schedule ======
            'trigger_first_friday_enabled' => '1',
            'trigger_mid_month_enabled' => '1',
            'trigger_hour' => '9',                           // TRIGGER_HOUR
            'trigger_minute' => '5',                         // TRIGGER_MINUTE
            'mid_month_day' => '15',                         // اليوم

            // ====== Templates (من السكربت) ======
            'template_first_friday_nl' => 'Beste ouder van {{Naam}}, Al Boukhari School groet u en herinnert u eraan om de betaling van {{month}} zo snel mogelijk te voldoen.',
            'template_mid_month_nl' => 'Beste familie van student {{Naam}}, betaling voor {{month}} is vertraagd. Graag zo spoedig mogelijk voldoen.',

            // ====== WhatsApp (Meta Cloud API) ======
            'whatsapp_enabled' => '0',
            'whatsapp_phone_number_id' => '',
            'whatsapp_business_account_id' => '',
            'whatsapp_access_token' => '',
            'whatsapp_app_secret' => '',
            'whatsapp_webhook_verify_token' => '',
            'whatsapp_fallback_to_sms' => '1',
            'whatsapp_fallback_minutes' => '10',
            'whatsapp_price_per_conversation' => '0.02',
            'whatsapp_default_language' => 'nl',
        ];

        foreach ($defaults as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value, 'is_encrypted' => false]);
        }
    }
}
