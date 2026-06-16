<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Watchlist;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Watchlist>
 */
class WatchlistFactory extends Factory
{
    protected $model = Watchlist::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->unique()->words(2, true),
            'description' => null,
            'sector_label' => null,
            'is_default' => false,
        ];
    }
}
