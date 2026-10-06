<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رمز اقتران قصير العمر (8 أحرف، 10 دقائق، استخدام واحد) يُعرض في الويب/CLI
 * ويُدخله المستخدم في التطبيق أو يمسحه كـ QR بصيغة {"u": APP_URL, "c": CODE}.
 */
class SenderDevicePairingCode extends Model
{
    public const DEFAULT_TTL_MINUTES = 10;

    /** أحرف لا تلتبس ببعضها (بلا 0/O، 1/I). */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    protected $fillable = ['code', 'expires_at', 'used_at', 'sender_device_id', 'created_by'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public static function generate(?int $createdBy = null, int $ttlMinutes = self::DEFAULT_TTL_MINUTES): self
    {
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (static::where('code', $code)->exists());

        return static::create([
            'code' => $code,
            'expires_at' => now()->addMinutes($ttlMinutes),
            'created_by' => $createdBy,
        ]);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /** الحمولة التي يُرمَّز بها QR في الويب. */
    public function qrPayload(): string
    {
        return json_encode(['u' => rtrim(config('app.url'), '/'), 'c' => $this->code], JSON_UNESCAPED_SLASHES);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(SenderDevice::class, 'sender_device_id');
    }
}
