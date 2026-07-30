<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Student;

class FeeResolver
{
    private static ?float $defaultMonthlyFeeCached = null;

    public static function defaultMonthlyFee(): float
    {
        if (self::$defaultMonthlyFeeCached === null) {
            self::$defaultMonthlyFeeCached = (float) Setting::get('default_monthly_fee', 30.00);
        }
        return self::$defaultMonthlyFeeCached;
    }

    /**
     * يحسب الرسم المطبَّق لطالب لشهر:
     * 1) إن وُجد override لطالب × شهر => يستخدمه
     * 2) وإلا إن وُجد رسم خاص بالطالب => يستخدمه
     * 3) وإلا => الرسم الافتراضي العام
     */
    public static function resolve(Student $student, int $year, int $month): float
    {
        if ($student->relationLoaded('feeOverrides')) {
            $override = $student->feeOverrides
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->first();
        } else {
            $override = $student->feeOverrides()
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->first();
        }
        if ($override) return (float) $override->amount;

        if ($student->default_fee_amount !== null) {
            return (float) $student->default_fee_amount;
        }

        return self::defaultMonthlyFee();
    }

    public static function surchargesFor(Student $student, int $year, int $month): float
    {
        if ($student->relationLoaded('surcharges')) {
            return (float) $student->surcharges
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->sum('amount');
        }
        return (float) $student->surcharges()
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->sum('amount');
    }

    /**
     * Returns true when this month is before the student's enrollment
     * or on/after their withdrawal — i.e. they owe nothing for that month.
     */
    public static function isOutsideEnrollment(Student $student, int $year, int $month): bool
    {
        $mStart = ($year * 12) + $month;
        if ($student->enrolled_at) {
            $eStart = ($student->enrolled_at->year * 12) + $student->enrolled_at->month;
            if ($mStart < $eStart) return true;
        }
        if ($student->withdrawn_at) {
            $wStart = ($student->withdrawn_at->year * 12) + $student->withdrawn_at->month;
            if ($mStart >= $wStart) return true;
        }
        return false;
    }

    public static function dueAmount(Student $student, int $year, int $month): float
    {
        if (self::isOutsideEnrollment($student, $year, $month)) {
            return 0.0;
        }
        return self::resolve($student, $year, $month) + self::surchargesFor($student, $year, $month);
    }

    /**
     * Bulk variant: returns paidAmount for every month 1..12 in one pass.
     * Avoids the O(payments) filter cost repeated per month.
     *
     * @return array<int, float>
     */
    public static function paidAllMonths(Student $student, int $year): array
    {
        $out = array_fill(1, 12, 0.0);
        if (!$student->relationLoaded('payments')) {
            for ($m = 1; $m <= 12; $m++) $out[$m] = self::paidAmount($student, $year, $m);
            return $out;
        }
        foreach ($student->payments as $p) {
            if ($p->period_year !== $year) continue;
            if ($p->method !== 'cash' && $p->method !== 'bank') continue;
            $m = $p->period_month;
            if ($m >= 1 && $m <= 12) $out[$m] += (float) $p->amount;
        }
        return $out;
    }

    /**
     * Bulk variant: returns dueAmount for every month 1..12 in one pass.
     *
     * @return array<int, float>
     */
    public static function dueAllMonths(Student $student, int $year): array
    {
        // Base fee once — same for all months unless overridden.
        $baseFee = $student->default_fee_amount !== null
            ? (float) $student->default_fee_amount
            : self::defaultMonthlyFee();

        // Bucket overrides by month.
        $overrides = array_fill(1, 12, null);
        if ($student->relationLoaded('feeOverrides')) {
            foreach ($student->feeOverrides as $o) {
                if ($o->period_year !== $year) continue;
                if ($o->period_month >= 1 && $o->period_month <= 12) {
                    $overrides[$o->period_month] = (float) $o->amount;
                }
            }
        }

        // Bucket surcharges by month.
        $surcharges = array_fill(1, 12, 0.0);
        if ($student->relationLoaded('surcharges')) {
            foreach ($student->surcharges as $s) {
                if ($s->period_year !== $year) continue;
                if ($s->period_month >= 1 && $s->period_month <= 12) {
                    $surcharges[$s->period_month] += (float) $s->amount;
                }
            }
        }

        // Enrollment window
        $enrollmentYm = $student->enrolled_at
            ? ($student->enrolled_at->year * 12) + $student->enrolled_at->month
            : null;
        $withdrawalYm = $student->withdrawn_at
            ? ($student->withdrawn_at->year * 12) + $student->withdrawn_at->month
            : null;

        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $cellYm = ($year * 12) + $m;
            if ($enrollmentYm !== null && $cellYm < $enrollmentYm) { $out[$m] = 0.0; continue; }
            if ($withdrawalYm !== null && $cellYm >= $withdrawalYm) { $out[$m] = 0.0; continue; }
            $fee = $overrides[$m] ?? $baseFee;
            $out[$m] = $fee + $surcharges[$m];
        }
        return $out;
    }

    public static function paidAmount(Student $student, int $year, int $month): float
    {
        if ($student->relationLoaded('payments')) {
            return (float) $student->payments
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->whereIn('method', ['cash', 'bank'])
                ->sum('amount');
        }
        return (float) $student->payments()
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->whereIn('method', ['cash', 'bank'])
            ->sum('amount');
    }

    public static function balance(Student $student, int $year, int $month): float
    {
        return self::dueAmount($student, $year, $month) - self::paidAmount($student, $year, $month);
    }
}
