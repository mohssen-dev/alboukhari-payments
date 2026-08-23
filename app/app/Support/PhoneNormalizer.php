<?php

namespace App\Support;

/**
 * تطبيع أرقام الهواتف الهولندية إلى صيغة E.164.
 * يعالج: 0031, +0031, 031, +031, 06xxxxxxxx, 0xxxxxxxxx, 6xxxxxxxx,
 * notation علمي مثل 6.83e+8، اللواحق .0، الفواصل والشرطات.
 */
class PhoneNormalizer
{
    public static function normalize(string|int|float|null $raw): ?string
    {
        if ($raw === null) return null;

        $s = trim((string) $raw);
        if ($s === '') return null;

        // معالجة الأرقام بصيغة علمية (6.82239333E8) أو لاحقة .0
        if (preg_match('/[eE]\d|\.0$|\.\d+[eE]/', $s)) {
            $n = (float) $s;
            if ($n > 0) {
                $s = sprintf('%.0f', $n);
            }
        }

        // إزالة الرموز الشائعة
        $s = preg_replace('/[()\-\s\.]/', '', $s);

        // أخذ الجزء قبل الـ /
        if (str_contains($s, '/')) {
            $s = explode('/', $s)[0];
        }

        // معالجة بداية NL الشائعة
        if (str_starts_with($s, '+0031')) $s = '+31' . substr($s, 5);
        if (str_starts_with($s, '0031'))  $s = '31' . substr($s, 4);
        if (str_starts_with($s, '+031'))  $s = '+31' . substr($s, 4);
        if (str_starts_with($s, '031'))   $s = '31' . substr($s, 3);

        if (str_starts_with($s, '+31')) return $s;
        if (str_starts_with($s, '31')) return '+' . $s;

        // وطني 06xxxxxxxx
        if (str_starts_with($s, '06') && strlen($s) >= 10) {
            return '+316' . substr($s, 2);
        }

        // وطني 0xxxxxxxxx (غير 06)
        if (str_starts_with($s, '0') && strlen($s) >= 10) {
            return '+31' . substr($s, 1);
        }

        // جوال هولندي عارٍ 6xxxxxxxx
        if (preg_match('/^6\d{8}$/', $s)) {
            return '+31' . $s;
        }

        // إذا بدأ بـ + نتركه
        if (str_starts_with($s, '+')) return $s;

        // غير ذلك: نعيد ما تم تنظيفه
        return $s ?: null;
    }

    public static function isValid(?string $e164): bool
    {
        if (!$e164) return false;
        return (bool) preg_match('/^\+\d{8,15}$/', $e164);
    }
    /**
     * True only when the number can actually receive an SMS.
     *
     * A Dutch landline like +31703603984 (070 = The Hague) passes every
     * "looks like a phone number" check, so a mistyped or partially-entered
     * number can silently become a campaign recipient the provider bills for
     * and no parent ever receives. Every number currently on file is a mobile,
     * so this only ever excludes a genuinely wrong entry.
     *
     * Anything the library cannot classify is allowed through: we only exclude
     * numbers positively identified as fixed lines.
     */
    public static function isSmsCapable(?string $e164): bool
    {
        if (! self::isValid($e164)) return false;

        try {
            $util = \libphonenumber\PhoneNumberUtil::getInstance();
            $parsed = $util->parse($e164, "NL");
            if (! $util->isValidNumber($parsed)) return false;

            $type = $util->getNumberType($parsed);
            $name = is_object($type) ? $type->name : (string) $type;

            return $name !== "FIXED_LINE";
        } catch (\Throwable) {
            // Unparseable by the library but structurally fine — do not block.
            return true;
        }
    }
}
