<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignRecipient extends Model
{
    protected $fillable = [
        'campaign_id', 'student_id', 'family_id',
        'phone_e164', 'body_personalized', 'status',
        'skip_reason', 'provider_message_id', 'provider_status',
        'segments', 'cost', 'attempts', 'last_error', 'sent_at', 'idempotency_key',
        'claimed_at', 'sender_device_id', 'sender_whatsapp_group_id',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'claimed_at' => 'datetime',
        'cost' => 'decimal:4',
    ];

    public const FINAL_STATUSES = ['sent', 'failed', 'skipped'];

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL_STATUSES, true);
    }

    /** الهدف كما يستقبله التطبيق: رقم E.164 أو "group:<اسم المجموعة>". */
    public function deviceTarget(): string
    {
        if ($this->sender_whatsapp_group_id && $this->whatsappGroup) {
            return $this->whatsappGroup->target();
        }

        return $this->phone_e164;
    }

    public function senderDevice(): BelongsTo
    {
        return $this->belongsTo(SenderDevice::class);
    }

    public function whatsappGroup(): BelongsTo
    {
        return $this->belongsTo(SenderWhatsappGroup::class, 'sender_whatsapp_group_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }
}
