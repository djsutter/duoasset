<?php

namespace App\Models;

use App\Casts\FiatMoneyCast;
use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use App\Types\FiatMoney;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $watchlist_item_id
 * @property AlertRuleType $type
 * @property AlertSeverity $severity
 * @property bool $is_active
 * @property string|null $currency
 * @property FiatMoney|null $target_price
 * @property array|null $parameters
 * @property \Illuminate\Support\Carbon|null $last_triggered_at
 */
class WatchlistAlertRule extends Model
{
    /** @use HasFactory<\Database\Factories\WatchlistAlertRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'watchlist_item_id',
        'type',
        'severity',
        'is_active',
        'currency',
        'target_price',
        'parameters',
        'last_triggered_at',
    ];

    protected $casts = [
        'type' => AlertRuleType::class,
        'severity' => AlertSeverity::class,
        'is_active' => 'boolean',
        'target_price' => FiatMoneyCast::class.':currency',
        'parameters' => 'array',
        'last_triggered_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (WatchlistAlertRule $rule) {
            // Currency falls back to the watchlist item's stock currency.
            if (empty($rule->currency) && $rule->watchlist_item_id) {
                $item = $rule->watchlistItem ?? WatchlistItem::with('stock')->find($rule->watchlist_item_id);
                if ($item && $item->stock) {
                    $rule->currency = $item->stock->currency->value;
                }
            }
        });
    }

    public function watchlistItem(): BelongsTo
    {
        return $this->belongsTo(WatchlistItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(WatchlistAlertEvent::class);
    }
}
