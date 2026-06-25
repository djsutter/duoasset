<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $earnings_event_id
 * @property string $symbol
 * @property string $alert_type
 * @property int $score
 * @property string $status
 * @property string|null $message
 * @property \Illuminate\Support\Carbon|null $sent_at
 */
class EarningsAlert extends Model
{
    protected $fillable = [
        'earnings_event_id',
        'symbol',
        'alert_type',
        'score',
        'status',
        'message',
        'sent_at',
    ];

    protected $casts = [
        'score' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function earningsEvent(): BelongsTo
    {
        return $this->belongsTo(EarningsEvent::class);
    }
}
