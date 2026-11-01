<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Full raw provider responses permanently archived as JSON.
 *
 * @property int $id
 * @property string $symbol
 * @property string $data_type
 * @property string $period
 * @property array $payload
 * @property \Illuminate\Support\Carbon $retrieved_at
 */
class MarketDataPayload extends Model
{
    protected $fillable = [
        'symbol',
        'data_type',
        'period',
        'payload',
        'retrieved_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'retrieved_at' => 'datetime',
    ];
}
