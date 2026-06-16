<?php

namespace App\Models;

use App\Casts\FiatMoneyCast;
use App\Enums\AlertSeverity;
use App\Types\FiatMoney;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $watchlist_alert_rule_id
 * @property int $watchlist_item_id
 * @property AlertSeverity $severity
 * @property \Illuminate\Support\Carbon $triggered_at
 * @property \Illuminate\Support\Carbon|null $seen_at
 * @property string|null $currency
 * @property FiatMoney|null $observed_price
 * @property array|null $context
 * @property string|null $message
 * @property array|null $notifications
 */
class WatchlistAlertEvent extends Model
{
    /** @use HasFactory<\Database\Factories\WatchlistAlertEventFactory> */
    use HasFactory;

    protected $fillable = [
        'watchlist_alert_rule_id',
        'watchlist_item_id',
        'severity',
        'triggered_at',
        'seen_at',
        'currency',
        'observed_price',
        'context',
        'message',
        'notifications',
    ];

    protected $casts = [
        'severity' => AlertSeverity::class,
        'triggered_at' => 'datetime',
        'seen_at' => 'datetime',
        'observed_price' => FiatMoneyCast::class.':currency',
        'context' => 'array',
        'notifications' => 'array',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(WatchlistAlertRule::class, 'watchlist_alert_rule_id');
    }

    public function watchlistItem(): BelongsTo
    {
        return $this->belongsTo(WatchlistItem::class);
    }

    public function isSeen(): bool
    {
        return $this->seen_at !== null;
    }

    public function markSeen(): void
    {
        if (! $this->seen_at) {
            $this->seen_at = now();
            $this->save();
        }
    }

    public function recordNotification(string $channel): void
    {
        $history = $this->notifications ?? [];
        $history[$channel] = now()->toIso8601String();
        $this->notifications = $history;
        $this->save();
    }
}
