<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class SettingsController extends Controller
{
    private const KEYS = [
        // General
        'default_monthly_fee', 'currency', 'school_year_start_month',
        // BulkGate
        'bulkgate_app_id', 'bulkgate_app_token', 'bulkgate_sender_id',
        'bulkgate_sender_id_value', 'bulkgate_default_country',
        'bulkgate_max_per_hour', 'bulkgate_price_per_sms', 'force_ascii',
        'batch_size', 'sleep_between_batch_ms', 'resume_delay_minutes',
        'retry_short_minutes', 'max_per_tick', 'checkpoint_every',
        // Reminders
        'trigger_first_friday_enabled', 'trigger_mid_month_enabled',
        'trigger_hour', 'trigger_minute', 'mid_month_day',
        'template_first_friday_nl', 'template_mid_month_nl',
        // WhatsApp
        'whatsapp_enabled', 'whatsapp_phone_number_id', 'whatsapp_business_account_id',
        'whatsapp_access_token', 'whatsapp_app_secret', 'whatsapp_webhook_verify_token',
        'whatsapp_fallback_to_sms', 'whatsapp_fallback_minutes',
        'whatsapp_price_per_conversation', 'whatsapp_default_language',
    ];

    private const ENCRYPTED_KEYS = ['bulkgate_app_token', 'whatsapp_access_token', 'whatsapp_app_secret'];

    private const BOOLEAN_KEYS = [
        'force_ascii', 'trigger_first_friday_enabled', 'trigger_mid_month_enabled',
        'whatsapp_enabled', 'whatsapp_fallback_to_sms',
    ];

    public function edit(Request $request)
    {
        $tab = $request->query('tab', 'general');

        $settings = [];
        foreach (self::KEYS as $key) {
            $settings[$key] = Setting::get($key, '');
        }

        // Mask encrypted values
        foreach (self::ENCRYPTED_KEYS as $key) {
            $settings[$key . '_masked'] = !empty(Setting::get($key)) ? '••••••••••' : '';
        }

        // BulkGate credit balance — cached 5 min so the tab stays fast and we
        // don't hammer the API. Failures are NOT cached (next visit retries)
        // and degrade to null → the blade shows a retry state, never a 500.
        $bgCredit = \Illuminate\Support\Facades\Cache::get('bulkgate:credit');
        if ($bgCredit === null) {
            try {
                $bgCredit = app(\App\Services\BulkGateClient::class)->info();
                \Illuminate\Support\Facades\Cache::put('bulkgate:credit', $bgCredit, now()->addMinutes(5));
            } catch (\Throwable $e) {
                $bgCredit = null; // not configured or API down — blade handles it
            }
        }

        return view('settings', compact('settings', 'tab', 'bgCredit'));
    }

    /** Force-refresh the cached BulkGate credit (button in the BulkGate tab). */
    public function refreshCredit()
    {
        \Illuminate\Support\Facades\Cache::forget('bulkgate:credit');
        return redirect()->route('settings', ['tab' => 'bulkgate']);
    }

    /**
     * Reveal one encrypted secret to the admin (👁 button next to the field).
     * Admin-only via route middleware; every reveal is written to the
     * activity log so there's an audit trail of who looked when.
     */
    public function revealSecret(Request $request)
    {
        $key = (string) $request->input('key');
        if (! in_array($key, self::ENCRYPTED_KEYS, true)) {
            abort(422);
        }

        activity('settings')
            ->causedBy($request->user())
            ->log("revealed secret: {$key}");

        return response()->json(['value' => (string) Setting::get($key, '')]);
    }

    public function update(Request $request)
    {
        $tab = $request->input('tab', 'general');
        $data = $request->all();

        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $data) && !in_array($key, self::BOOLEAN_KEYS, true)) {
                continue;
            }

            if (in_array($key, self::BOOLEAN_KEYS, true)) {
                Setting::put($key, $request->has($key) ? '1' : '0');
                continue;
            }

            $value = $data[$key];
            if (in_array($key, self::ENCRYPTED_KEYS, true)) {
                // Skip if masked value sent (means: no change)
                if ($value === '••••••••••' || $value === '' || $value === null) continue;
                Setting::put($key, $value, true);
                continue;
            }

            Setting::put($key, $value);
        }

        return redirect()->route('settings', ['tab' => $tab])
            ->with('flash', __('common.flash_saved'))
            ->with('flash_type', 'success');
    }

    public function triggerReminders(Request $request)
    {
        $type = $request->input('type');
        if (!in_array($type, ['first_friday', 'mid_month_auto'], true)) {
            abort(400);
        }
        Artisan::call('reminders:dispatch', ['--force-type' => $type]);
        return redirect()->route('settings', ['tab' => 'reminders'])
            ->with('flash', __('Reminder triggered:') . ' ' . $type)
            ->with('flash_type', 'success');
    }

    public function testBulkGate(Request $request)
    {
        try {
            $client = app(\App\Services\BulkGateClient::class);
            $phone = $request->input('test_phone');
            if (!$phone) {
                return back()->with('flash', __('flash.test_phone_required'))->with('flash_type', 'error');
            }
            $body = __('flash.test_message_prefix') . ' ' . now()->format('H:i:s');
            $result = $client->send($phone, $body, 'TEST');
            return back()->with('flash', __('flash.test_sent', ['phone' => $phone]) . ' (' . $result['status'] . ')')->with('flash_type', 'success');
        } catch (\Throwable $e) {
            return back()->with('flash', __('flash.send_error') . ' ' . $e->getMessage())->with('flash_type', 'error');
        }
    }

    public function testWhatsApp(Request $request)
    {
        try {
            $client = app(\App\Services\WhatsAppClient::class);
            $phone = $request->input('test_phone');
            if (!$phone) {
                return back()->with('flash', __('flash.test_phone_required'))->with('flash_type', 'error');
            }
            $body = __('flash.test_message_prefix') . ' WhatsApp — ' . now()->format('H:i:s');
            $result = $client->send($phone, $body, 'TEST');
            $ok = ($result['status'] ?? 'failed') === 'sent';
            $msg = $ok
                ? __('flash.test_sent', ['phone' => $phone])
                : (__('flash.send_error') . ' ' . ($result['status'] ?? 'unknown') . ($result['error'] ?? '' ? ' — '.$result['error'] : ''));
            return back()->with('flash', $msg)->with('flash_type', $ok ? 'success' : 'error');
        } catch (\Throwable $e) {
            return back()->with('flash', __('flash.send_error') . ' ' . $e->getMessage())->with('flash_type', 'error');
        }
    }
}
