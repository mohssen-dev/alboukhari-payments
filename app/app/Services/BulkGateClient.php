<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\AsciiSanitizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * مزوّد BulkGate Transactional API.
 * متوافق تماماً مع منطق السكربت الأصلي.
 */
class BulkGateClient
{
    private const URL = 'https://portal.bulkgate.com/api/2.0/advanced/transactional';
    private const INFO_URL = 'https://portal.bulkgate.com/api/2.0/advanced/info';

    /**
     * Account info (credit balance) — no SMS is sent.
     * Returns ['credit' => float, 'currency' => string, 'checked_at' => string].
     * Throws on missing credentials or API failure — callers decide how to degrade.
     */
    public function info(): array
    {
        $appId = Setting::get('bulkgate_app_id') ?: env('BULKGATE_APP_ID');
        $appToken = Setting::get('bulkgate_app_token') ?: env('BULKGATE_APP_TOKEN');

        if (empty($appId) || empty($appToken)) {
            throw new \RuntimeException(__('error.bulkgate_not_configured'));
        }

        $response = Http::timeout(8)->post(self::INFO_URL, [
            'application_id' => $appId,
            'application_token' => $appToken,
        ]);

        $data = $response->json();
        if (!$response->successful() || !isset($data['data']['credit'])) {
            $err = $data['error'] ?? ('HTTP ' . $response->status());
            throw new \RuntimeException('BulkGate info failed: ' . $err);
        }

        return [
            'credit' => (float) $data['data']['credit'],
            'currency' => (string) ($data['data']['currency'] ?? 'credits'),
            'checked_at' => now()->format('Y-m-d H:i'),
        ];
    }

    public function send(string|array $phones, string $text, ?string $tag = null): array
    {
        $appId = Setting::get('bulkgate_app_id') ?: env('BULKGATE_APP_ID');
        $appToken = Setting::get('bulkgate_app_token') ?: env('BULKGATE_APP_TOKEN');
        $senderId = Setting::get('bulkgate_sender_id', 'gText');
        $senderValue = Setting::get('bulkgate_sender_id_value', 'Al Boukhari');
        $country = Setting::get('bulkgate_default_country', 'NL');
        $forceAscii = Setting::get('force_ascii', '1') === '1';

        if (empty($appId) || empty($appToken)) {
            throw new \RuntimeException(__('error.bulkgate_not_configured'));
        }

        $numbers = is_array($phones) ? array_values($phones) : [$phones];
        $anyHasPlus = false;
        foreach ($numbers as $n) {
            if (str_starts_with((string) $n, '+')) { $anyHasPlus = true; break; }
        }

        $textToSend = $forceAscii ? AsciiSanitizer::sanitize($text) : $text;
        $unicode = !$forceAscii && (bool) preg_match('/[^\x00-\x7F]/', $textToSend);

        $payload = [
            'application_id' => $appId,
            'application_token' => $appToken,
            'number' => $numbers,
            'text' => $textToSend,
            'country' => $anyHasPlus ? null : ($country ?: null),
            'channel' => [
                'sms' => [
                    'sender_id' => $senderId,
                    'sender_id_value' => $senderValue,
                    'unicode' => $unicode,
                ],
            ],
        ];
        if ($tag) {
            $payload['tag'] = $tag;
        }

        $response = Http::timeout(20)->post(self::URL, $payload);
        $code = $response->status();
        $body = $response->body();

        if ($code === 429 || $code === 503) {
            throw new \RuntimeException('REMOTE_RATE_LIMIT');
        }
        if (preg_match('/an_hourly_transaction_messages_quota_has_been_exhausted/i', $body)) {
            throw new \RuntimeException('REMOTE_HOURLY_QUOTA_EXHAUSTED');
        }

        $statusNote = 'ERR ' . $code;
        $data = json_decode($body, true);
        if ($code >= 200 && $code < 300 && isset($data['data'])) {
            $responses = $data['data']['response'] ?? [];
            if (is_array($responses) && count($responses) > 0) {
                $st = strtolower((string) ($responses[0]['status'] ?? ''));
                $statusNote = in_array($st, ['accepted', 'sent', 'scheduled'], true)
                    ? strtoupper($st)
                    : 'API ' . ($st ?: 'OK');
            } else {
                $statusNote = 'OK';
            }
        } elseif (isset($data['error']) || isset($data['type'])) {
            $statusNote = 'ERR ' . ($data['type'] ?? '') . ' ' . ($data['error'] ?? '');
        }

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException(trim($statusNote));
        }

        return [
            'status' => $statusNote,
            'http_code' => $code,
            'body' => $data ?? $body,
            'sent_text' => $textToSend,
            'unicode' => $unicode,
        ];
    }
}
