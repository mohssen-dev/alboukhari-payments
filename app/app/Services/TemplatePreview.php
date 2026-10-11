<?php

namespace App\Services;

use App\Models\Family;
use App\Models\Setting;
use App\Models\Student;
use App\Support\SmsCounter;
use App\Support\TemplateVariables;

/**
 * A template's text exactly as a real parent receives it this month — with
 * its SMS count and cost — and the Arabic translation filled in for the same
 * child (shown to staff, never sent). Used by the template editors.
 */
class TemplatePreview
{
    /**
     * The full preview for the editor: counter, cost quote, translation.
     *
     * @return array{counter: array, translation: ?string, who: ?string, month: string, quote: array, unknown: list<string>}|null
     */
    public static function sample(string $body, string $bodyAr = ''): ?array
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }

        $translation = trim($bodyAr);
        $both = $body . "\n" . $translation;
        [$render, $who] = self::renderer($both, self::subjects());
        $counter = self::count($render($body));
        $month = (int) date('n');

        return [
            'counter' => $counter,
            'translation' => $translation !== '' ? $render($translation) : null,
            'who' => $who,
            'month' => (MonthNames::full()[$month] ?? '') . ' ' . date('Y'),
            'quote' => app(BulkGatePricing::class)->quote((int) $counter['segments']),
            'unknown' => TemplateVariables::unknownIn($both),
        ];
    }

    /**
     * Just the SMS count of a text as sent — for listing many templates.
     * Pass subjects() once for the whole list.
     */
    public static function counter(string $body, array $subjects): array
    {
        [$render] = self::renderer($body, $subjects);

        return self::count($render(trim($body)));
    }

    /** Sample children to fill variables: any child, and one from a family of two or more. */
    public static function subjects(): array
    {
        return [
            'student' => Student::orderBy('id')->first(),
            'family_student' => Student::whereIn('family_id', Family::has('students', '>=', 2)->select('id'))->orderBy('id')->first(),
        ];
    }

    /** @return array{0: \Closure(string): string, 1: ?string} the renderer for $text's variables, and whom it renders for */
    private static function renderer(string $text, array $subjects): array
    {
        $year = (int) date('Y');
        $month = (int) date('n');
        $usesFamily = (bool) array_intersect(TemplateVariables::usedIn($text), array_keys(TemplateVariables::FAMILY));

        $student = ($usesFamily ? $subjects['family_student'] : null) ?? $subjects['student'];
        if (!$student) {
            return [fn (string $t) => $t, null];
        }
        if ($usesFamily && $student->family) {
            return [fn (string $t) => TemplateRenderer::renderForFamily($t, $student->family, $year, $month), $student->family->displayName()];
        }

        return [fn (string $t) => TemplateRenderer::renderForStudent($t, $student, $year, $month), $student->name];
    }

    private static function count(string $text): array
    {
        return SmsCounter::count($text, Setting::get('force_ascii', '1') === '1');
    }
}
