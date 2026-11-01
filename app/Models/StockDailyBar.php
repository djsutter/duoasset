<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * One daily OHLCV bar per (symbol, bar_date). Persisted by the buy-setup
 * scanner so each weekly run only needs to fetch incremental days from FMP.
 *
 * @property string $symbol
 * @property \Illuminate\Support\Carbon $bar_date
 * @property string|null $open
 * @property string|null $high
 * @property string|null $low
 * @property string|null $close
 * @property string|null $adj_close
 * @property int|null $volume
 * @property \Illuminate\Support\Carbon|null $retrieved_at
 */
class StockDailyBar extends Model
{
    protected $fillable = [
        'symbol',
        'bar_date',
        'open',
        'high',
        'low',
        'close',
        'adj_close',
        'volume',
        'retrieved_at',
    ];

    protected $casts = [
        'open' => 'decimal:6',
        'high' => 'decimal:6',
        'low' => 'decimal:6',
        'close' => 'decimal:6',
        'adj_close' => 'decimal:6',
        'volume' => 'integer',
        'retrieved_at' => 'datetime',
    ];

    /**
     * Canonical Y-m-d date string representation for driver-agnostic consistency (SQLite & MySQL).
     */
    protected function barDate(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value !== null ? Carbon::parse($value) : null,
            set: fn ($value) => $value !== null ? Carbon::parse($value)->toDateString() : null,
        );
    }
}
