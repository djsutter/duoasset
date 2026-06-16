<?php

namespace Database\Factories;

use App\Enums\MoatLevel;
use App\Models\Stock;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WatchlistItem>
 */
class WatchlistItemFactory extends Factory
{
    protected $model = WatchlistItem::class;

    public function definition(): array
    {
        return [
            'watchlist_id' => Watchlist::factory(),
            'stock_id' => Stock::factory(),
            'thesis' => null,
            'moat_level' => $this->faker->randomElement(MoatLevel::cases())->value,
            // currency will be auto-filled from the linked stock by the model's
            // saving hook. Tests may override.
            'currency' => null,
            'target_price' => null,
            'stop_price' => null,
            'notes' => null,
        ];
    }
}
