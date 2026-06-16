<?php

namespace Database\Factories;

use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use App\Models\WatchlistAlertRule;
use App\Models\WatchlistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WatchlistAlertRule>
 */
class WatchlistAlertRuleFactory extends Factory
{
    protected $model = WatchlistAlertRule::class;

    public function definition(): array
    {
        return [
            'watchlist_item_id' => WatchlistItem::factory(),
            'type' => AlertRuleType::PriceAbove->value,
            'severity' => AlertSeverity::Info->value,
            'is_active' => true,
            'currency' => null, // resolved from stock by the model's saving hook
            'target_price' => null,
            'parameters' => null,
            'last_triggered_at' => null,
        ];
    }
}
