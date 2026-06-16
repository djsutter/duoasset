<?php

namespace Database\Factories;

use App\Models\Industry;
use App\Models\Sector;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Industry>
 */
class IndustryFactory extends Factory
{
    protected $model = Industry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'sector_id' => Sector::factory(),
            'name' => ucwords($name),
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 999999),
            'sort_order' => 0,
        ];
    }
}
