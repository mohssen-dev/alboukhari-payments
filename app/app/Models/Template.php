<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A message template: the Dutch text that is sent, plus an Arabic translation
 * (body_ar) that is only SHOWN to staff so they understand what parents
 * receive — it is never part of the SMS. 'manual' templates are the
 * free-text messages kept automatically from the send page.
 */
class Template extends Model
{
    use SoftDeletes;

    public const ORIGIN_LIBRARY = 'library';
    public const ORIGIN_MANUAL = 'manual';

    /**
     * The templates the automatic reminders send (RemindersDispatcher looks
     * them up by code). Their text can be edited, but they cannot be deleted:
     * the reminder would then fail with "template not found".
     */
    public const REMINDER_FIRST_FRIDAY = 'nl_first_friday';
    public const REMINDER_MID_MONTH = 'nl_mid_month';

    /** 'first_friday' / 'mid_month' when an automatic reminder sends this template. */
    public function reminderKind(): ?string
    {
        return match ($this->code) {
            self::REMINDER_FIRST_FRIDAY => 'first_friday',
            self::REMINDER_MID_MONTH => 'mid_month',
            default => null,
        };
    }

    protected $fillable = ['code', 'name', 'language', 'body', 'body_ar', 'origin', 'default_for'];

    /** The text that is sent: the Dutch body only — never the translation. */
    public function messageBody(): string
    {
        return (string) $this->body;
    }

    public function isManual(): bool
    {
        return $this->origin === self::ORIGIN_MANUAL;
    }

    public function scopeLibrary(Builder $query): Builder
    {
        return $query->where('origin', self::ORIGIN_LIBRARY);
    }

    public function scopeManual(Builder $query): Builder
    {
        return $query->where('origin', self::ORIGIN_MANUAL);
    }
}
