<?php

namespace App\Support;

/**
 * Credits and money as the cost panels print them: credits with one decimal
 * (BulkGate's own precision, e.g. 1.4 or 764.9), money with two decimals —
 * three below one unit, where 0.056 € and 0.06 € are different prices.
 */
final class MoneyFormat
{
    public static function credits(?float $value): string
    {
        if ($value === null) {
            return '—';
        }
        $decimals = abs(round($value, 1) - round($value, 2)) > 0.0001 ? 2 : 1;

        return number_format($value, $decimals);
    }

    public static function eur(?float $value): string
    {
        return $value === null ? '—' : self::amount($value) . ' €';
    }

    public static function usd(?float $value): string
    {
        return $value === null ? '—' : self::amount($value) . ' $';
    }

    /** For figures inside a sentence: kept left-to-right so "6.04 € · 7.00 $" survives Arabic text around it. */
    public static function iso(string $text): string
    {
        return "\u{2068}" . $text . "\u{2069}"; // first-strong isolate … pop directional isolate
    }

    private static function amount(float $value): string
    {
        return number_format($value, abs($value) < 1 && $value != 0.0 ? 3 : 2);
    }
}
