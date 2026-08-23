<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['key', 'value', 'is_encrypted'];
    protected $casts = ['is_encrypted' => 'boolean'];

    public static function get(string $key, mixed $default = null): mixed
    {
        // Cache the STORED value (still encrypted), never the plaintext.
        //
        // CACHE_STORE is the `database` driver, so whatever is cached here is
        // written to the `cache` table in MySQL — and therefore ends up in
        // every nightly db:backup dump. Caching the decrypted token defeated
        // the entire point of encrypting it at rest: the BulkGate API token
        // was sitting in production's cache table in clear text. Decrypt after
        // reading instead, so only ciphertext is ever cached.
        $cached = Cache::remember("setting:$key", 60, function () use ($key) {
            $row = static::find($key);
            return $row ? ['v' => $row->value, 'e' => (bool) $row->is_encrypted] : null;
        });

        if ($cached === null) {
            return $default;
        }

        // Tolerate an entry left by an older release.
        //
        // A previous version cached the resolved STRING here. After deploying
        // this version those stale entries were still in the cache store, and
        // reading $cached['e'] on a string threw "Cannot access offset of type
        // string on string" — every page 500'd until the cache was flushed.
        // Anything not in the current shape is discarded and re-read, so a
        // deploy can never poison the cache this way again.
        if (! is_array($cached) || ! array_key_exists('v', $cached) || ! array_key_exists('e', $cached)) {
            Cache::forget("setting:$key");
            $row = static::find($key);
            if (! $row) {
                return $default;
            }
            $cached = ['v' => $row->value, 'e' => (bool) $row->is_encrypted];
        }

        if (! $cached['e']) {
            return $cached['v'] ?? $default;
        }

        try {
            $value = Crypt::decryptString($cached['v']);
        } catch (\Throwable) {
            // Unreadable ciphertext (e.g. APP_KEY rotated) must not take a page
            // down — fall back to the caller's default.
            return $default;
        }

        return $value === null ? $default : $value;
    }

    public static function put(string $key, mixed $value, bool $encrypt = false): void
    {
        $storedValue = $encrypt ? Crypt::encryptString((string) $value) : (string) $value;
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $storedValue, 'is_encrypted' => $encrypt]
        );
        Cache::forget("setting:$key");
    }
}
