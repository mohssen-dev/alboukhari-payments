<?php

namespace App\Support;

/**
 * The placeholders a message template may use — English names only.
 *
 * Templates used to mix Arabic names ({{المبلغ_العائلي}}) with English ones
 * ({{month}}). The Arabic ones broke easily — a different letter form or a
 * stray character and the placeholder went out to parents verbatim — and
 * could not be typed on every keyboard. The canonical names are English;
 * the old names still render (ALIASES) so a campaign scheduled before the
 * rename keeps working.
 */
final class TemplateVariables
{
    public const PATTERN = '/\{\{\s*([^{}]+?)\s*\}\}/u';

    /** name => translation key describing it */
    public const STUDENT = [
        'student_name' => 'tplvar.student_name',
        'month_nl' => 'tplvar.month_nl',
        'month_ar' => 'tplvar.month_ar',
        'month_en' => 'tplvar.month_en',
        'year' => 'tplvar.year',
        'due' => 'tplvar.due',
        'paid' => 'tplvar.paid',
        'balance' => 'tplvar.balance',
    ];

    public const FAMILY = [
        'children_names' => 'tplvar.children_names',
        'unpaid_names' => 'tplvar.unpaid_names',
        'children_count' => 'tplvar.children_count',
        'family_total' => 'tplvar.family_total',
        'family_paid' => 'tplvar.family_paid',
        'family_balance' => 'tplvar.family_balance',
        'children_details' => 'tplvar.children_details',
    ];

    /** Old names (Arabic, Dutch, early English) → canonical English name. */
    public const ALIASES = [
        'Naam' => 'student_name',
        'name' => 'student_name',
        'اسم' => 'student_name',
        'month' => 'month_en',
        'الشهر' => 'month_ar',
        'السنة' => 'year',
        'المستحق' => 'due',
        'المدفوع' => 'paid',
        'المتبقي' => 'balance',
        'أسماء_الأبناء' => 'children_names',
        'أسماء_غير_المدفوعين' => 'unpaid_names',
        'عدد_الأبناء' => 'children_count',
        'المبلغ_العائلي' => 'family_total',
        'المتبقي_العائلي' => 'family_balance',
        'تفاصيل_الأبناء' => 'children_details',
    ];

    /** The canonical name for a placeholder, or null when it is not recognised. */
    public static function canonical(string $name): ?string
    {
        $name = trim($name);
        if (isset(self::STUDENT[$name]) || isset(self::FAMILY[$name])) {
            return $name;
        }

        return self::ALIASES[$name] ?? null;
    }

    /** @return string[] placeholders in $text that are not recognised */
    public static function unknownIn(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $m);

        return array_values(array_unique(array_filter($m[1], fn ($n) => self::canonical($n) === null)));
    }

    /** @return string[] canonical names used in $text */
    public static function usedIn(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $m);

        return array_values(array_unique(array_filter(array_map([self::class, 'canonical'], $m[1]))));
    }

    /** Rewrite old placeholder names to the canonical English ones. */
    public static function normalize(string $text): string
    {
        return preg_replace_callback(self::PATTERN, function ($m) {
            $canonical = self::canonical($m[1]);

            return $canonical ? '{{' . $canonical . '}}' : $m[0];
        }, $text);
    }

    /** ['a', 'b'] → "{{a}}, {{b}}" for messages. */
    public static function display(array $names): string
    {
        return implode(', ', array_map(fn ($n) => '{{' . $n . '}}', $names));
    }

    /** Replace every recognised placeholder with its value; unknown ones are left as written. */
    public static function fill(string $text, array $values): string
    {
        return preg_replace_callback(self::PATTERN, function ($m) use ($values) {
            $canonical = self::canonical($m[1]);

            return ($canonical !== null && array_key_exists($canonical, $values)) ? (string) $values[$canonical] : $m[0];
        }, $text);
    }
}
