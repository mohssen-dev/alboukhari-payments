<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Encrypted settings must never be cached in plaintext.
 *
 * Production runs CACHE_STORE=database, so anything cached is written to the
 * `cache` table in MySQL and lands in every nightly backup dump. Setting::get()
 * used to cache the DECRYPTED value, which put the live BulkGate API token in
 * clear text inside the database — and inside every backup of it.
 */
class SettingSecretCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_encrypted_setting_is_never_cached_in_plaintext(): void
    {
        $secret = 'super-secret-token-value-123';
        Setting::put('bulkgate_app_token', $secret, true);

        // Populate the cache the way a page render would.
        $this->assertSame($secret, Setting::get('bulkgate_app_token'));

        $cached = Cache::get('setting:bulkgate_app_token');
        $this->assertNotNull($cached, 'the value should still be cached (for speed)');

        $serialized = is_scalar($cached) ? (string) $cached : json_encode($cached);
        $this->assertStringNotContainsString($secret, $serialized,
            'SECURITY: the decrypted secret was written into the cache store.');
    }

    public function test_encrypted_setting_still_reads_back_correctly_from_cache(): void
    {
        $secret = 'another-secret-456';
        Setting::put('whatsapp_access_token', $secret, true);

        $first = Setting::get('whatsapp_access_token');   // populates cache
        $second = Setting::get('whatsapp_access_token');  // served from cache

        $this->assertSame($secret, $first);
        $this->assertSame($secret, $second, 'a cached encrypted setting must decrypt on every read');
    }

    public function test_plain_setting_round_trips(): void
    {
        Setting::put('default_monthly_fee', '30.00');
        $this->assertSame('30.00', Setting::get('default_monthly_fee'));
        $this->assertSame('30.00', Setting::get('default_monthly_fee'));
    }

    public function test_missing_setting_returns_the_default(): void
    {
        $this->assertSame('fallback', Setting::get('does_not_exist', 'fallback'));
    }

    public function test_updating_a_setting_invalidates_the_cache(): void
    {
        Setting::put('currency', 'EUR');
        $this->assertSame('EUR', Setting::get('currency'));

        Setting::put('currency', 'USD');
        $this->assertSame('USD', Setting::get('currency'), 'put() must forget the cached value');
    }

    public function test_unreadable_ciphertext_falls_back_instead_of_crashing(): void
    {
        // Simulates an APP_KEY rotation leaving old ciphertext undecryptable.
        Setting::updateOrCreate(
            ['key' => 'bulkgate_app_token'],
            ['value' => 'not-valid-ciphertext', 'is_encrypted' => true]
        );
        Cache::forget('setting:bulkgate_app_token');

        $this->assertSame('', Setting::get('bulkgate_app_token', ''),
            'A corrupt secret must degrade to the default, not take the page down.');
    }
}
