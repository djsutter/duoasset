<?php

namespace App\Models;

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
    ];

    protected $casts = [
        'bar_date' => 'date',
        'open' => 'decimal:6',
        'high' => 'decimal:6',
        'low' => 'decimal:6',
        'close' => 'decimal:6',
        'adj_close' => 'decimal:6',
        'volume' => 'integer',
    ];
}
