<?php

namespace Database\Factories;

use App\Models\SectorFlowSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SectorFlowSnapshot>
 */
class SectorFlowSnapshotFactory extends Factory
{
    protected $model = SectorFlowSnapshot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sector = $this->faker->randomElement([
            'technology', 'financials', 'healthcare', 'communication_services',
            'consumer_discretionary', 'consumer_staples', 'industrials',
            'energy', 'utilities', 'real_estate', 'materials',
        ]);

        $strength = $this->faker->randomFloat(2, 0, 100);
        $daily = $this->faker->randomFloat(4, -5, 5);
        $weekly = $this->faker->randomFloat(4, -12, 12);
        $monthly = $this->faker->randomFloat(4, -20, 20);

        return [
            'sector' => $sector,
            'label' => (string) ($this->sectorLabel($sector)),
            'snapshot_date' => $this->faker->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'captured_at' => now(),

            'daily_change_pct' => $daily,
            'weekly_change_pct' => $weekly,
            'monthly_change_pct' => $monthly,

            'daily_relative_strength' => $this->faker->randomFloat(4, -3, 3),
            'weekly_relative_strength' => $this->faker->randomFloat(4, -6, 6),
            'monthly_relative_strength' => $this->faker->randomFloat(4, -10, 10),

            'daily_relative_volume' => $this->faker->randomFloat(4, 0.4, 3),
            'weekly_relative_volume' => $this->faker->randomFloat(4, 0.4, 3),
            'monthly_relative_volume' => $this->faker->randomFloat(4, 0.4, 3),

            'daily_relative_dollar_volume' => $this->faker->randomFloat(4, 0.4, 3),
            'weekly_relative_dollar_volume' => $this->faker->randomFloat(4, 0.4, 3),
            'monthly_relative_dollar_volume' => $this->faker->randomFloat(4, 0.4, 3),

            'daily_score' => $this->faker->randomFloat(2, 0, 100),
            'weekly_score' => $this->faker->randomFloat(2, 0, 100),
            'monthly_score' => $this->faker->randomFloat(2, 0, 100),
            'strength' => $strength,

            'rank' => $this->faker->numberBetween(1, 11),
            'percentile_rank' => $this->faker->randomFloat(2, 0, 100),

            'daily_velocity' => $this->faker->randomFloat(6, -10, 10),
            'weekly_velocity' => $this->faker->randomFloat(6, -10, 10),
            'monthly_velocity' => $this->faker->randomFloat(6, -10, 10),
            'velocity' => $this->faker->randomFloat(6, -10, 10),

            'daily_acceleration' => $this->faker->randomFloat(6, -5, 5),
            'weekly_acceleration' => $this->faker->randomFloat(6, -5, 5),
            'monthly_acceleration' => $this->faker->randomFloat(6, -5, 5),
            'acceleration' => $this->faker->randomFloat(6, -5, 5),

            'issuer_breadth_daily' => $this->faker->randomFloat(2, 0, 100),
            'issuer_breadth_weekly' => $this->faker->randomFloat(2, 0, 100),
            'issuer_breadth_monthly' => $this->faker->randomFloat(2, 0, 100),

            'direction' => $this->faker->randomElement([
                'accelerating', 'improving', 'stable', 'cooling', 'weakening',
            ]),
            'confidence_score' => $this->faker->randomFloat(2, 0, 100),
            'data_quality_score' => $this->faker->randomFloat(2, 0, 100),

            'etf_count' => $this->faker->numberBetween(3, 5),
            'constituents' => [
                'XLK' => [
                    'issuer' => 'spdr',
                    'weight' => 1.0,
                    'current_price' => $this->faker->randomFloat(2, 20, 300),
                    'daily_change_pct' => $daily,
                    'weekly_change_pct' => $weekly,
                    'monthly_change_pct' => $monthly,
                    'data_quality_score' => 100,
                    'error' => null,
                ],
            ],
        ];
    }

    /**
     * Pin the snapshot to a specific sector and date (idempotency tests).
     * Named to avoid clobbering the base Factory::for() relationship helper.
     */
    public function forSectorDate(string $sector, string $snapshotDate): static
    {
        return $this->state(fn () => [
            'sector' => $sector,
            'label' => $this->sectorLabel($sector),
            'snapshot_date' => $snapshotDate,
        ]);
    }

    private function sectorLabel(string $sector): string
    {
        return (string) config("market_data.sector_etfs.$sector.label", ucfirst($sector));
    }
}
