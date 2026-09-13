<?php

namespace App\Support;

/**
 * What an SMS text is actually sent as — shared by the sender (BulkGateClient)
 * and the counter (SmsCounter) so the cost shown is the cost paid.
 *
 * force_ascii keeps Dutch messages in cheap GSM-7 (160 characters per SMS) by
 * transliterating accents and DROPPING every other non-ASCII character. That
 * silently deleted any Arabic text, so a message containing Arabic is sent
 * untouched as Unicode (70 characters per SMS); everything else is sanitised
 * exactly as before.
 */
final class SmsText
{
    /** @return array{text: string, unicode: bool} */
    public static function prepare(string $text, bool $forceAscii): array
    {
        $hasArabic = (bool) preg_match('/\p{Arabic}/u', $text);

        if ($forceAscii && !$hasArabic) {
            return ['text' => AsciiSanitizer::sanitize($text), 'unicode' => false];
        }

        $clean = trim(str_replace(["\r\n", "\r"], "\n", $text));

        return ['text' => $clean, 'unicode' => (bool) preg_match('/[^\x00-\x7F]/', $clean)];
    }
}
