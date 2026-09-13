<?php

namespace App\Services;

use App\Models\Family;
use App\Models\Student;
use App\Support\TemplateVariables;

/**
 * Fills a template's placeholders (English names — see TemplateVariables;
 * the old Arabic names still work) for one student or a whole family.
 */
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

        return TemplateVariables::fill($template, self::period($year, $month) + [
            'student_name' => $student->name,
            'due' => number_format($due, 2),
            'paid' => number_format($paid, 2),
            'balance' => number_format($balance, 2),
            // The family placeholders still mean something for one child, so a
            // family template sent per student never leaks a raw {{…}}.
            'children_names' => $student->name,
            'unpaid_names' => $balance > 0 ? $student->name : '—',
            'children_count' => 1,
            'family_total' => number_format($due, 2),
            'family_paid' => number_format($paid, 2),
            'family_balance' => number_format($balance, 2),
            'children_details' => sprintf('%s: %s€', $student->name, number_format($due, 0)),
        ]);
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
            $totalDue += $due;
            $totalPaid += $paid;

            if ($due - $paid > 0) {
                $unpaidNames[] = $student->name;
            }
            $detailsLines[] = sprintf('%s: %s€', $student->name, number_format($due, 0));
        }

        // An overpaying family must never see a negative figure in an SMS.
        $familyOutstanding = max(0.0, $totalDue - $totalPaid);

        return TemplateVariables::fill($template, self::period($year, $month) + [
            // Joined with "&" / "," so the same value reads right in a Dutch
            // line and in its Arabic translation (it used to join with "و").
            'student_name' => implode(' & ', $names),
            'due' => number_format($totalDue, 2),
            'paid' => number_format($totalPaid, 2),
            'balance' => number_format($familyOutstanding, 2),
            'children_names' => implode(', ', $names),
            'unpaid_names' => $unpaidNames ? implode(', ', $unpaidNames) : '—',
            'children_count' => count($students),
            'family_total' => number_format($totalDue, 2),
            'family_paid' => number_format($totalPaid, 2),
            'family_balance' => number_format($familyOutstanding, 2),
            'children_details' => implode("\n", $detailsLines),
        ]);
    }

    private static function period(int $year, int $month): array
    {
        return [
            'month_nl' => self::MONTHS_NL[$month] ?? '',
            'month_ar' => self::MONTHS_AR[$month] ?? '',
            'month_en' => self::MONTHS_EN[$month] ?? '',
            'year' => $year,
        ];
    }
}
