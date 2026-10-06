<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * هاتف Android مقترن يشغّل تطبيق "مرسل البخاري" ويرسل الرسائل من شريحته/واتسابه.
 */
class SenderDevice extends Model
{
    /** قيم message_logs.provider للرسائل المرسَلة من الهاتف. */
    public const PROVIDER_SMS = 'device_sms';
    public const PROVIDER_WHATSAPP = 'device_whatsapp';

    /** يُعدّ الهاتف "متصلاً" إن ظهر خلال هذه المدة. */
    public const ONLINE_WINDOW_SECONDS = 120;

    protected $fillable = [
        'name', 'token_hash', 'model', 'manufacturer', 'android_version', 'sdk_int',
        'app_version', 'capabilities', 'last_heartbeat', 'last_seen_at', 'is_active', 'paired_by',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'last_heartbeat' => 'array',
        'last_seen_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected $hidden = ['token_hash'];

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function whatsappGroups(): HasMany
    {
        return $this->hasMany(SenderWhatsappGroup::class);
    }

    public function pairedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paired_by');
    }

    public function isOnline(): bool
    {
        return $this->is_active
            && $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(self::ONLINE_WINDOW_SECONDS));
    }

    /** يولّد توكناً جديداً ويخزّن تجزئته؛ يعيد النص الصريح مرة واحدة فقط. */
    public function issueToken(): string
    {
        $plain = bin2hex(random_bytes(32));
        $this->token_hash = self::hashToken($plain);

        return $plain;
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function findByToken(?string $plain): ?self
    {
        if (! $plain) {
            return null;
        }

        return static::where('token_hash', self::hashToken($plain))->first();
    }
}
