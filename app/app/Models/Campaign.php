<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Campaign extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'status', 'total_recipients', 'sent_count', 'failed_count', 'started_at', 'finished_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('campaign');
    }

    /** قنوات الإرسال. الافتراضي هو السلوك القديم عبر BulkGate. */
    public const CHANNEL_BULKGATE_SMS = 'bulkgate_sms';
    public const CHANNEL_DEVICE_SMS = 'device_sms';
    public const CHANNEL_DEVICE_WHATSAPP = 'device_whatsapp';

    public const DEVICE_CHANNELS = [self::CHANNEL_DEVICE_SMS, self::CHANNEL_DEVICE_WHATSAPP];

    protected $fillable = [
        'type', 'status', 'channel', 'sender_device_id',
        'scheduled_at', 'period_year', 'period_month', 'threshold_amount',
        'template_id', 'body_template', 'tag', 'group_by_family',
        'total_recipients', 'sent_count', 'failed_count', 'skipped_count',
        'estimated_cost', 'actual_cost',
        'started_at', 'finished_at', 'created_by',
    ];

    protected $casts = [
        'group_by_family' => 'boolean',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'threshold_amount' => 'decimal:2',
        'estimated_cost' => 'decimal:4',
        'actual_cost' => 'decimal:4',
    ];

    /** A queued campaign with a future/past scheduled_at not yet fired. */
    public function isScheduled(): bool
    {
        return $this->status === 'queued' && $this->scheduled_at !== null;
    }

    /** حملة تُرسل من هاتف مقترن (SMS أو واتساب) بدل مزوّد BulkGate السحابي. */
    public function isDeviceChannel(): bool
    {
        return in_array($this->channel, self::DEVICE_CHANNELS, true);
    }

    /** القناة كما يفهمها التطبيق: sms | whatsapp (null للحملات السحابية). */
    public function deviceChannelName(): ?string
    {
        return match ($this->channel) {
            self::CHANNEL_DEVICE_SMS => 'sms',
            self::CHANNEL_DEVICE_WHATSAPP => 'whatsapp',
            default => null,
        };
    }

    /** قيمة message_logs.provider المناسبة لهذه الحملة. */
    public function providerName(): string
    {
        return match ($this->channel) {
            self::CHANNEL_DEVICE_SMS => SenderDevice::PROVIDER_SMS,
            self::CHANNEL_DEVICE_WHATSAPP => SenderDevice::PROVIDER_WHATSAPP,
            default => 'bulkgate',
        };
    }

    public function senderDevice(): BelongsTo
    {
        return $this->belongsTo(SenderDevice::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function typeLabel(): string
    {
        $key = "campaigns.type.{$this->type}";
        $translated = __($key);

        return $translated === $key ? $this->type : $translated;
    }
}
