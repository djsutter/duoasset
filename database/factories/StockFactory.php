<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\Exchange;
use App\Models\Industry;
use App\Models\Sector;
use App\Models\Stock;
use App\Models\SubIndustry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Stock>
 */
class StockFactory extends Factory
{
    protected $model = Stock::class;

    public function definition(): array
    {
        $sector = Sector::factory();
        $industry = Industry::factory()->state(fn () => ['sector_id' => $sector]);
        $sub = SubIndustry::factory()->state(fn () => ['industry_id' => $industry]);

        return [
            'symbol' => strtoupper($this->faker->unique()->lexify('????')),
            'exchange' => $this->faker->randomElement(Exchange::cases())->value,
            'currency' => $this->faker->randomElement(Currency::cases())->value,
            'company_name' => $this->faker->company(),
            'sector_id' => Sector::factory(),
            'industry_id' => function (array $attrs) {
                return Industry::factory()->create(['sector_id' => $attrs['sector_id']])->id;
            },
            'sub_industry_id' => function (array $attrs) {
                return SubIndustry::factory()->create(['industry_id' => $attrs['industry_id']])->id;
            },
        ];
    }
}
