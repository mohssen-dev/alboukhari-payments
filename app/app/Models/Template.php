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
