<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Student extends Model
{
    use SoftDeletes, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'family_id', 'phone_primary_e164', 'allow_sms', 'is_hidden', 'is_blocked_messages', 'is_in_person', 'default_fee_amount', 'withdrawn_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('student');
    }


    protected $fillable = [
        'external_id',
        'name',
        'family_id',
        'phone_primary_raw',
        'phone_primary_e164',
        'phone_secondary_raw',
        'phone_secondary_e164',
        'allow_sms',
        'allow_whatsapp',
        'included_in_send_all',
        'is_hidden',
        'is_blocked_messages',
        'is_in_person',
        'excluded_from_send_all',
        'default_fee_amount',
        'notes',
        'enrolled_at',
        'withdrawn_at',
    ];

    protected $casts = [
        'allow_sms' => 'boolean',
        'allow_whatsapp' => 'boolean',
        'included_in_send_all' => 'boolean',
        'is_hidden' => 'boolean',
        'is_blocked_messages' => 'boolean',
        'is_in_person' => 'boolean',
        'excluded_from_send_all' => 'boolean',
        'default_fee_amount' => 'decimal:2',
        'enrolled_at' => 'date',
        'withdrawn_at' => 'date',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function feeOverrides(): HasMany
    {
        return $this->hasMany(StudentMonthlyFeeOverride::class);
    }

    public function surcharges(): HasMany
    {
        return $this->hasMany(StudentSurcharge::class);
    }

    public function markers(): HasMany
    {
        return $this->hasMany(StudentMonthlyMarker::class);
    }

    public function suspensions(): HasMany
    {
        return $this->hasMany(StudentSuspension::class);
    }

    public function activeSuspension(): ?StudentSuspension
    {
        if ($this->relationLoaded('suspensions')) {
            $now = now();
            return $this->suspensions
                ->filter(function (StudentSuspension $s) use ($now) {
                    if ($s->starts_at->gt($now)) return false;
                    if ($s->ends_at !== null && $s->ends_at->lt($now)) return false;
                    return true;
                })
                ->sortByDesc('id')
                ->first();
        }

        return $this->suspensions()
            ->where('starts_at', '<=', now())
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->latest()
            ->first();
    }

    public function siblings()
    {
        if (!$this->family_id) {
            return collect();
        }
        return Student::where('family_id', $this->family_id)
            ->where('id', '!=', $this->id)
            ->get();
    }

    public function isCurrentlySuspended(): bool
    {
        return $this->activeSuspension() !== null;
    }

    public function canReceiveMessages(): bool
    {
        if ($this->is_blocked_messages || $this->is_in_person || $this->is_hidden) {
            return false;
        }
        if ($this->family && $this->family->is_blocked_messages) {
            return false;
        }
        if ($this->isCurrentlySuspended()) {
            return false;
        }
        return $this->allow_sms && !empty($this->phone_primary_e164);
    }

    /**
     * Why this student is excluded from messaging, in the viewer's language.
     * These strings surface in the grid, the student panel and the campaign
     * preview, so they must be translated — they used to be hardcoded Arabic
     * and were shown verbatim to Dutch and English users.
     */
    public function skipReason(): ?string
    {
        if ($this->is_hidden) return __('skip.hidden');
        if ($this->is_blocked_messages) return __('skip.blocked');
        if ($this->is_in_person) return __('skip.in_person');
        if ($this->family && $this->family->is_blocked_messages) return __('skip.family_blocked');
        if ($this->isCurrentlySuspended()) {
            $end = $this->activeSuspension()?->ends_at;
            return $end
                ? __('skip.suspended_until', ['date' => $end->format('Y-m-d')])
                : __('skip.suspended');
        }
        if (!$this->allow_sms) return __('skip.sms_disabled');
        if (empty($this->phone_primary_e164)) return __('skip.no_phone');
        return null;
    }

    public function statusBadge(): string
    {
        if ($this->is_hidden) return '🙈';
        if ($this->is_blocked_messages) return '🚫';
        if ($this->is_in_person) return '🏠';
        if ($this->isCurrentlySuspended()) return '⏸️';
        if ($this->excluded_from_send_all) return '🚷';
        return '';
    }

    public function scopeVisible(Builder $q): Builder
    {
        return $q->where('is_hidden', false);
    }

    public function scopeCanMessage(Builder $q): Builder
    {
        return $q
            ->where('allow_sms', true)
            ->where('is_blocked_messages', false)
            ->where('is_in_person', false)
            ->where('is_hidden', false)
            ->whereNotNull('phone_primary_e164');
    }
}
