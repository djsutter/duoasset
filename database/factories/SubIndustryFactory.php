<?php

namespace Database\Factories;

use App\Models\Industry;
use App\Models\SubIndustry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SubIndustry>
 */
class SubIndustryFactory extends Factory
{
    protected $model = SubIndustry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'industry_id' => Industry::factory(),
            'name' => ucwords($name),
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 999999),
            'sort_order' => 0,
        ];
    }
}
