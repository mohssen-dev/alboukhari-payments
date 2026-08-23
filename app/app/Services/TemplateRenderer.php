<?php

namespace App\Services;

use App\Models\Family;
use App\Models\Student;

class TemplateRenderer
{
    private const MONTHS_NL = [
        1 => 'januari', 2 => 'februari', 3 => 'maart', 4 => 'april',
        5 => 'mei', 6 => 'juni', 7 => 'juli', 8 => 'augustus',
        9 => 'september', 10 => 'oktober', 11 => 'november', 12 => 'december',
    ];

    private const MONTHS_AR = [
        1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
        5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
        9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
    ];

    private const MONTHS_EN = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];

    /**
     * يرسم القالب لطالب لشهر معيّن.
     */
    public static function renderForStudent(string $template, Student $student, int $year, int $month): string
    {
        $due = FeeResolver::dueAmount($student, $year, $month);
        $paid = FeeResolver::paidAmount($student, $year, $month);
        // Clamped: a parent who paid in advance must never receive an SMS
        // quoting a negative amount owed.
        $balance = max(0.0, $due - $paid);

        $vars = [
            'Naam' => $student->name,
            'name' => $student->name,
            'اسم' => $student->name,
            'month' => self::MONTHS_EN[$month] ?? '',
            'month_nl' => self::MONTHS_NL[$month] ?? '',
            'month_ar' => self::MONTHS_AR[$month] ?? '',
            'الشهر' => self::MONTHS_AR[$month] ?? '',
            'year' => $year,
            'السنة' => $year,
            'due' => number_format($due, 2),
            'paid' => number_format($paid, 2),
            'balance' => number_format($balance, 2),
            'المستحق' => number_format($due, 2),
            'المدفوع' => number_format($paid, 2),
            'المتبقي' => number_format($balance, 2),
        ];

        return self::replace($template, $vars);
    }

    /**
     * يرسم القالب لعائلة (إخوة معاً).
     */
    public static function renderForFamily(string $template, Family $family, int $year, int $month, bool $onlyUnpaid = false): string
    {
        // Only siblings this school actually messages about. Previously EVERY
        // child on the family record was named in the SMS and had their fee
        // added to the family total — including ones deliberately excluded
        // (hidden, blocked, studying in person, suspended), so a parent was
        // billed for a child the office had already taken out of messaging.
        $students = $family->students->filter(fn (Student $s) => $s->skipReason() === null)->values();
        if ($students->isEmpty()) {
            // Nothing messageable left — fall back to the full list rather than
            // rendering an empty message.
            $students = $family->students;
        }

        $names = $students->pluck('name')->all();
        $unpaidNames = [];
        $totalDue = 0;
        $totalPaid = 0;
        $detailsLines = [];

        foreach ($students as $student) {
            $due = FeeResolver::dueAmount($student, $year, $month);
            $paid = FeeResolver::paidAmount($student, $year, $month);
            $bal = $due - $paid;
            $totalDue += $due;
            $totalPaid += $paid;

            if ($bal > 0) {
                $unpaidNames[] = $student->name;
            }
            $detailsLines[] = sprintf('%s: %s€', $student->name, number_format($due, 0));
        }

        // An overpaying family must never see a negative figure in an SMS.
        $familyOutstanding = max(0.0, $totalDue - $totalPaid);

        $vars = [
            'Naam' => implode(' و ', $names),
            'name' => implode(' و ', $names),
            'children_names' => implode('، ', $names),
            'أسماء_الأبناء' => implode('، ', $names),
            'unpaid_names' => implode('، ', $unpaidNames),
            'أسماء_غير_المدفوعين' => implode('، ', $unpaidNames) ?: '—',
            'children_count' => count($students),
            'عدد_الأبناء' => count($students),
            'family_total' => number_format($totalDue, 2),
            'family_paid' => number_format($totalPaid, 2),
            'family_balance' => number_format($familyOutstanding, 2),
            'المبلغ_العائلي' => number_format($totalDue, 2),
            'المتبقي_العائلي' => number_format($familyOutstanding, 2),
            'تفاصيل_الأبناء' => implode("\n", $detailsLines),
            'month' => self::MONTHS_EN[$month] ?? '',
            'month_nl' => self::MONTHS_NL[$month] ?? '',
            'month_ar' => self::MONTHS_AR[$month] ?? '',
            'الشهر' => self::MONTHS_AR[$month] ?? '',
            'year' => $year,
            'السنة' => $year,
        ];

        return self::replace($template, $vars);
    }

    private static function replace(string $template, array $vars): string
    {
        $out = $template;
        foreach ($vars as $key => $val) {
            $out = preg_replace('/\{\{\s*' . preg_quote($key, '/') . '\s*\}\}/u', (string) $val, $out);
        }
        return $out;
    }
}
