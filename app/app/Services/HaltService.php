<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class HaltService
{
    public static function isHalted(): bool
    {
        return Setting::get('halt_sending', '0') === '1';
    }

    public static function halt(): void
    {
        Setting::put('halt_sending', '1');
    }

    public static function resume(): void
    {
        Setting::put('halt_sending', '0');
    }

    /**
     * Hourly quota counter — atomic via Cache::increment (rolls back if over max).
     * The read-modify-write pattern that used to be here allowed two concurrent
     * senders to each read `used=100`, both pass the check, both write `101` —
     * silently over-shooting the hourly cap.
     */
    public static function tryTakeQuota(int $count = 1): bool
    {
        $hourKey = 'bg:hour:' . date('YmdH');
        $max = (int) Setting::get('bulkgate_max_per_hour', 2500);

        // Ensure the key exists so increment() works on all cache drivers.
        Cache::add($hourKey, 0, now()->addHours(2));
        $newTotal = Cache::increment($hourKey, $count);

        if ($newTotal > $max) {
            // Roll back the increment; caller will halt/pause the batch.
            Cache::decrement($hourKey, $count);
            return false;
        }
        return true;
    }

    public static function hourUsed(): int
    {
        return (int) Cache::get('bg:hour:' . date('YmdH'), 0);
    }
}
