<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Payment extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['student_id', 'period_year', 'period_month', 'amount', 'paid_at', 'method', 'note'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('payment');
    }

    protected $fillable = [
        'student_id',
        'period_year',
        'period_month',
        'amount',
        'paid_at',
        'method',
        'note',
        'source',
    ];

    protected $casts = [
        'paid_at' => 'date',
        'amount' => 'decimal:2',
        'period_year' => 'integer',
        'period_month' => 'integer',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function methodIcon(): string
    {
        return match ($this->method) {
            'cash' => '💵',
            'bank' => '🏦',
            'legacy_zero' => '🔵',
            default => '',
        };
    }

    public function methodLabel(): string
    {
        return match ($this->method) {
            'cash' => 'نقد',
            'bank' => 'بنكي',
            'legacy_zero' => 'مستورد من الشيت',
            default => $this->method,
        };
    }
}
