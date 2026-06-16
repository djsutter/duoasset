<?php

namespace App\Models;

use App\Casts\FiatMoneyCast;
use App\Enums\MoatLevel;
use App\Types\FiatMoney;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $watchlist_id
 * @property int $stock_id
 * @property string|null $thesis
 * @property MoatLevel $moat_level
 * @property string $currency
 * @property FiatMoney|null $target_price
 * @property FiatMoney|null $stop_price
 * @property string|null $notes
 */
class WatchlistItem extends Model
{
    /** @use HasFactory<\Database\Factories\WatchlistItemFactory> */
    use HasFactory;

    protected $fillable = [
        'watchlist_id',
        'stock_id',
        'thesis',
        'moat_level',
        'currency',
        'target_price',
        'stop_price',
        'notes',
    ];

    protected $casts = [
        'moat_level' => MoatLevel::class,
        'target_price' => FiatMoneyCast::class.':currency',
        'stop_price' => FiatMoneyCast::class.':currency',
    ];

    protected static function booted(): void
    {
        static::saving(function (WatchlistItem $item) {
            // Currency is derived from the linked stock so users only choose
            // currency once (when creating the stock).
            if (empty($item->currency) && $item->stock_id) {
                $stock = $item->stock ?? Stock::find($item->stock_id);
                if ($stock) {
                    $item->currency = $stock->currency->value;
                }
            }
        });
    }

    public function watchlist(): BelongsTo
    {
        return $this->belongsTo(Watchlist::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function alertRules(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WatchlistAlertRule::class);
    }

    public function alertEvents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WatchlistAlertEvent::class);
    }
}
