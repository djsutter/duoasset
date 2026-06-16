<?php

namespace Database\Factories;

use App\Enums\AlertSeverity;
use App\Models\WatchlistAlertEvent;
use App\Models\WatchlistAlertRule;
use App\Models\WatchlistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WatchlistAlertEvent>
 */
class WatchlistAlertEventFactory extends Factory
{
    protected $model = WatchlistAlertEvent::class;

    public function definition(): array
    {
        return [
            'watchlist_alert_rule_id' => WatchlistAlertRule::factory(),
            'watchlist_item_id' => WatchlistItem::factory(),
            'severity' => AlertSeverity::Info->value,
            'triggered_at' => now(),
            'currency' => null,
            'observed_price' => null,
            'context' => null,
            'message' => null,
            'notifications' => null,
        ];
    }
}
