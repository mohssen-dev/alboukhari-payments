<?php

namespace App\Services;

use App\Models\Setting;

/**
 * إعدادات وتيرة الإرسال من الهاتف. يعدّلها المدير من الويب (تبويب "الهاتف المرسِل")
 * وتُرسل للتطبيق مع كل مزامنة، فالهاتف لا يقرّر شيئاً بنفسه.
 *
 * القيم الافتراضية محافظة لأن الهاتف شخصي وليس مخصّصاً للإرسال:
 * فواصل أطول وسقف يومي أقل يحميان رقم واتساب من الحظر.
 */
class SenderDeviceConfig
{
    public const DEFAULTS = [
        'sender_min_delay_sec' => 10,
        'sender_max_delay_sec' => 25,
        'sender_batch_size' => 15,
        'sender_batch_pause_sec' => 180,
        'sender_daily_cap_whatsapp' => 100,
        'sender_daily_cap_sms' => 300,
        'sender_window_start' => '09:00',
        'sender_window_end' => '21:00',
        'sender_whatsapp_mode' => 'auto',      // auto | assisted
        'sender_poll_interval_sec' => 20,
        'sender_max_attempts' => 3,
        'sender_claim_stale_minutes' => 30,    // رسالة محجوزة بلا تقرير بعد هذه المدة تعود للطابور
    ];

    public const KEYS = [
        'sender_min_delay_sec', 'sender_max_delay_sec', 'sender_batch_size', 'sender_batch_pause_sec',
        'sender_daily_cap_whatsapp', 'sender_daily_cap_sms', 'sender_window_start', 'sender_window_end',
        'sender_whatsapp_mode', 'sender_poll_interval_sec', 'sender_max_attempts', 'sender_claim_stale_minutes',
    ];

    /** كل الإعدادات بمفاتيحها الكاملة (للويب). */
    public static function all(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $out[$key] = Setting::get($key, $default);
        }

        return $out;
    }

    /** الحمولة التي يستقبلها التطبيق (المفاتيح بلا بادئة sender_، والأرقام كأعداد). */
    public static function forDevice(): array
    {
        $out = [];
        foreach (self::all() as $key => $value) {
            $short = substr($key, strlen('sender_'));
            if ($short === 'claim_stale_minutes') {
                continue; // شأن الخادم وحده
            }
            $out[$short] = is_numeric(self::DEFAULTS[$key]) ? (int) $value : (string) $value;
        }

        return $out;
    }

    public static function maxAttempts(): int
    {
        return max(1, (int) Setting::get('sender_max_attempts', self::DEFAULTS['sender_max_attempts']));
    }

    public static function claimStaleMinutes(): int
    {
        return max(5, (int) Setting::get('sender_claim_stale_minutes', self::DEFAULTS['sender_claim_stale_minutes']));
    }
}
