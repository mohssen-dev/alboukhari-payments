<?php

namespace App\Services;

use App\Models\Student;
use Carbon\Carbon;

/**
 * يحسب حالة شهر معيّن لطالب:
 * - not_due       : الشهر مستقبلي لم يحن بعد ولم يُدفع
 * - not_enrolled  : الشهر قبل تاريخ التحاق الطالب (enrolled_at) أو بعد انسحابه (withdrawn_at)
 * - paid          : مدفوع كاملاً (يشمل الدفع المسبَق لأشهر مستقبلية)
 * - paid_advance  : دفعة كاملة لشهر لم يحن بعد
 * - partial       : دُفع جزء فقط
 * - late          : لم يدفع وقد مرّ منتصف الشهر (15)، أو وُسم legacy_late
 * - unpaid        : لم يدفع وحان وقت الشهر، لم يبلغ منتصفه
 * - legacy_zero   : مستورد من الشيت بقيمة 0 (سُجّل بنكياً سابقاً)
 */
class MonthStatusResolver
{
    /**
     * Half-cent tolerance for float comparisons. Payment amounts accumulate
     * as floats (25.10 + 4.90 = 30.000000000000004), so a strict >= can leave
     * a fully-paid month stuck at 'partial'.
     */
    private const EPS = 0.005;

    /** Cached per PHP request. Reset if tests span midnight. */
    private static ?int $todayYm = null;   // year * 12 + month
    private static ?int $todayDay = null;

    private static function today(): array
    {
        if (self::$todayYm === null) {
            $t = Carbon::today();
            self::$todayYm = ($t->year * 12) + $t->month;
            self::$todayDay = $t->day;
        }
        return [self::$todayYm, self::$todayDay];
    }

    /**
     * Computes statuses for all 12 months of a year in one pass.
     * Much faster than calling resolve() 12 times when rendering a grid.
     *
     * @return array<int, string> keyed by month 1..12
     */
    public static function resolveAll(Student $student, int $year): array
    {
        [$todayYm, $todayDay] = self::today();

        // Pre-group payments and markers into buckets keyed by month — one pass through each collection.
        $paidByMonth = array_fill(1, 12, 0.0);
        $hasLegacyZeroByMonth = array_fill(1, 12, false);
        $hasLegacyLateByMonth = array_fill(1, 12, false);

        if ($student->relationLoaded('payments')) {
            foreach ($student->payments as $p) {
                if ($p->period_year !== $year) continue;
                $m = $p->period_month;
                if ($m < 1 || $m > 12) continue;
                if ($p->method === 'cash' || $p->method === 'bank') {
                    $paidByMonth[$m] += (float) $p->amount;
                } elseif ($p->method === 'legacy_zero') {
                    $hasLegacyZeroByMonth[$m] = true;
                }
            }
        }
        if ($student->relationLoaded('markers')) {
            foreach ($student->markers as $mk) {
                if ($mk->period_year !== $year || $mk->type !== 'legacy_late') continue;
                if ($mk->period_month >= 1 && $mk->period_month <= 12) {
                    $hasLegacyLateByMonth[$mk->period_month] = true;
                }
            }
        }

        // Enrollment window (as year*12+month integer)
        $enrollmentYm = $student->enrolled_at
            ? ($student->enrolled_at->year * 12) + $student->enrolled_at->month
            : null;
        $withdrawalYm = $student->withdrawn_at
            ? ($student->withdrawn_at->year * 12) + $student->withdrawn_at->month
            : null;

        // Bulk-compute all 12 dueAmounts once (already accounts for enrolled/withdrawn windows).
        $dueByMonth = FeeResolver::dueAllMonths($student, $year);

        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $cellYm = ($year * 12) + $m;

            if ($enrollmentYm !== null && $cellYm < $enrollmentYm) { $out[$m] = 'not_enrolled'; continue; }
            if ($withdrawalYm !== null && $cellYm >= $withdrawalYm) { $out[$m] = 'not_enrolled'; continue; }

            $paid = $paidByMonth[$m];
            $due = $dueByMonth[$m];
            $isFuture = $cellYm > $todayYm;
            $legacyZero = $hasLegacyZeroByMonth[$m];

            if ($isFuture) {
                if ($paid > 0) {
                    $out[$m] = ($due > 0 && $paid < $due - self::EPS) ? 'partial' : 'paid_advance';
                    continue;
                }
                $out[$m] = $legacyZero ? 'legacy_zero' : 'not_due';
                continue;
            }

            if ($legacyZero && $paid == 0) { $out[$m] = 'legacy_zero'; continue; }

            // Zero due for an enrolled month = explicit exemption (fee override 0).
            // Without this it would fall through to unpaid → late → dunning SMS.
            if ($due <= self::EPS) { $out[$m] = 'paid'; continue; }

            if ($paid >= $due - self::EPS) { $out[$m] = 'paid'; continue; }
            if ($paid > 0)                 { $out[$m] = 'partial'; continue; }

            // Not paid — is it late? (past mid of next month, or legacy_late marker)
            // Late is defined as: today is on/after the 15th of the following month.
            $nextYm = $cellYm + 1;
            $todayReachedNext = $todayYm > $nextYm || ($todayYm === $nextYm && $todayDay >= 15);
            $out[$m] = ($hasLegacyLateByMonth[$m] || $todayReachedNext) ? 'late' : 'unpaid';
        }

        return $out;
    }

    /** Kept for backward compatibility. Prefer resolveAll for grids. */
    public static function resolve(Student $student, int $year, int $month): string
    {
        return self::resolveAll($student, $year)[$month];
    }

    public static function colorClass(string $status): string
    {
        return match ($status) {
            'paid' => 'bg-green-100 text-green-900',
            'paid_advance' => 'bg-emerald-100 text-emerald-900',
            'partial' => 'bg-yellow-100 text-yellow-900',
            'unpaid' => 'bg-red-100 text-red-900',
            'late' => 'bg-red-200 text-red-950 font-bold',
            'legacy_zero' => 'bg-blue-100 text-blue-900',
            'not_due' => 'bg-gray-50 text-gray-400',
            'not_enrolled' => 'bg-gray-100 text-gray-400 italic',
            default => '',
        };
    }

    public static function label(string $status): string
    {
        return match ($status) {
            'paid' => __('status.paid'),
            'paid_advance' => __('status.paid_advance'),
            'partial' => __('status.partial'),
            'unpaid' => __('status.unpaid'),
            'late' => __('status.late'),
            'legacy_zero' => __('status.legacy_zero'),
            'not_due' => __('status.not_due'),
            'not_enrolled' => __('status.not_enrolled'),
            default => $status,
        };
    }
}
