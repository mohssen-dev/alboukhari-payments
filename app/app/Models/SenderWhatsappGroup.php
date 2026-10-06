<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مجموعة واتساب معرَّفة بالاسم على هاتف مرسِل معيّن. لا يوجد API رسمي للمجموعات،
 * فالهاتف يختارها من شاشة "إرسال إلى…" بالاسم الحرفي المخزَّن هنا.
 */
class SenderWhatsappGroup extends Model
{
    /** البادئة التي يميّز بها التطبيق هدف المجموعة عن رقم الهاتف في حقل `to`. */
    public const TARGET_PREFIX = 'group:';

    protected $fillable = ['sender_device_id', 'name', 'verified_at', 'notes'];

    protected $casts = ['verified_at' => 'datetime'];

    public function device(): BelongsTo
    {
        return $this->belongsTo(SenderDevice::class, 'sender_device_id');
    }

    public function target(): string
    {
        return self::TARGET_PREFIX . $this->name;
    }
}
