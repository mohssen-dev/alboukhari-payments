<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageLog extends Model
{
    protected $fillable = [
        'campaign_id', 'student_id', 'family_id', 'type', 'provider',
        'phone', 'body', 'segments', 'status', 'cost', 'provider_response',
        'tag', 'sent_by',
    ];

    protected $casts = [
        'provider_response' => 'array',
        'cost' => 'decimal:4',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
