<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Meta WhatsApp Cloud API client.
 *
 * Sends plain-text messages to numbers that opted in.
 * Configured via the Settings → WhatsApp tab:
 *  - whatsapp_phone_number_id
 *  - whatsapp_access_token   (encrypted)
 *
 * Public API mirrors BulkGateClient: send(phone, text, tag) -> array.
 */
class WhatsAppClient
{
    private const BASE = 'https://graph.facebook.com/v20.0';

    public function isEnabled(): bool
    {
        return Setting::get('whatsapp_enabled', '0') === '1';
    }

    public function send(string $phone, string $text, ?string $tag = null): array
    {
        $phoneNumberId = Setting::get('whatsapp_phone_number_id');
        $accessToken   = Setting::get('whatsapp_access_token');

        if (empty($phoneNumberId) || empty($accessToken)) {
            throw new \RuntimeException(__('error.whatsapp_not_configured'));
        }

        $to = ltrim($phone, '+');

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'text',
            'text'              => ['preview_url' => false, 'body' => $text],
        ];

        $url = self::BASE . '/' . urlencode($phoneNumberId) . '/messages';

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->retry(2, 500)
                ->post($url, $payload);

            $body = $response->json() ?? [];

            if ($response->failed()) {
                $err = $body['error']['message'] ?? ('HTTP '.$response->status());
                Log::warning('WhatsApp send failed', ['phone' => $to, 'tag' => $tag, 'error' => $err, 'body' => $body]);
                return [
                    'status'   => 'failed',
                    'error'    => $err,
                    'response' => $body,
                ];
            }

            $messageId = $body['messages'][0]['id'] ?? null;
            return [
                'status'     => 'sent',
                'message_id' => $messageId,
                'response'   => $body,
            ];
        } catch (\Throwable $e) {
            Log::error('WhatsApp send exception', ['phone' => $to, 'tag' => $tag, 'error' => $e->getMessage()]);
            return [
                'status' => 'failed',
                'error'  => $e->getMessage(),
            ];
        }
    }
}
