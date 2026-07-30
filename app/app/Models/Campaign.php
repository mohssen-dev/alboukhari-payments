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

    protected $fillable = [
        'type', 'status', 'period_year', 'period_month', 'threshold_amount',
        'template_id', 'body_template', 'tag', 'group_by_family',
        'total_recipients', 'sent_count', 'failed_count', 'skipped_count',
        'estimated_cost', 'actual_cost',
        'started_at', 'finished_at', 'created_by',
    ];

    protected $casts = [
        'group_by_family' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'threshold_amount' => 'decimal:2',
        'estimated_cost' => 'decimal:4',
        'actual_cost' => 'decimal:4',
    ];

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
        return match ($this->type) {
            'send_all' => 'إرسال جماعي',
            'unpaid_by_month' => 'لمن لم يدفع شهر',
            'late_mid_month' => 'للمتأخرين',
            'paid_less_than' => 'لمن دفع أقل من',
            'balance_above' => 'لمن متبقي عليه أكثر من',
            'specific_students' => 'لطلاب محددين',
            'first_friday' => 'أول جمعة (تلقائي)',
            'mid_month_auto' => '15 من الشهر (تلقائي)',
            default => $this->type,
        };
    }
}
