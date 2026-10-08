<?php

namespace Tests\Unit\Services\Stocks;

use App\Services\Stocks\StockFundamentalsAnalyzer;
use App\Services\Stocks\ThresholdInterpolationScorer;
use PHPUnit\Framework\TestCase;

class StockFundamentalsAnalyzerTest extends TestCase
{
    private StockFundamentalsAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new StockFundamentalsAnalyzer;
    }

    public function test_increasing_cash_burn_cannot_earn_fcf_expansion_points_from_revenue_growth(): void
    {
        [$income, $cash] = $this->quarters(10_000_000, 40_000_000, -20_000_000, -30_000_000);
        $result = $this->analyzer->fcfMarginExpansion($income, $cash);

        // -200% -> -75% is real ratio expansion, but the company is burning
        // $10M more cash. Only the scoring input is gated.
        $this->assertSame(12_500.0, $result['raw_fcf_margin_expansion_bps']);
        $this->assertSame(0.0, $result['fcf_margin_expansion_bps']);
        $this->assertSame(-30_000_000.0, $result['current_ttm_fcf']);
        $this->assertSame(-20_000_000.0, $result['prior_ttm_fcf']);
        $this->assertSame(-0.75, $result['current_ttm_fcf_margin']);
        $this->assertSame(-2.0, $result['prior_ttm_fcf_margin']);
    }

    public function test_fcf_quality_gate_prevents_full_weight_from_threshold_interpolation(): void
    {
        [$income, $cash] = $this->quarters(10_000_000, 40_000_000, -20_000_000, -30_000_000);
        $result = $this->analyzer->fcfMarginExpansion($income, $cash);
        $interpolator = new ThresholdInterpolationScorer;

        $this->assertSame(100.0, $interpolator->score($result['raw_fcf_margin_expansion_bps'], 250, 500, 1000, 1500));
        $this->assertSame(0.0, $interpolator->score($result['fcf_margin_expansion_bps'], 250, 500, 1000, 1500));
    }

    public function test_reducing_cash_burn_still_earns_positive_expansion(): void
    {
        [$income, $cash] = $this->quarters(10_000_000, 40_000_000, -20_000_000, -15_000_000);
        $result = $this->analyzer->fcfMarginExpansion($income, $cash);

        $this->assertSame(16_250.0, $result['raw_fcf_margin_expansion_bps']);
        $this->assertSame(16_250.0, $result['fcf_margin_expansion_bps']);
    }

    public function test_turning_cash_flow_positive_is_rewarded(): void
    {
        [$income, $cash] = $this->quarters(10_000_000, 40_000_000, -20_000_000, 2_000_000);
        $result = $this->analyzer->fcfMarginExpansion($income, $cash);

        $this->assertSame(20_500.0, $result['fcf_margin_expansion_bps']);
        $this->assertSame($result['raw_fcf_margin_expansion_bps'], $result['fcf_margin_expansion_bps']);
    }

    public function test_deepening_operating_losses_do_not_score_despite_margin_improvement(): void
    {
        [$income] = $this->quarters(10_000_000, 40_000_000, -20_000_000, -30_000_000);
        $result = $this->analyzer->operatingMarginExpansion($income);

        $this->assertSame(12_500.0, $result['raw_operating_margin_expansion_bps']);
        $this->assertSame(0.0, $result['operating_margin_expansion_bps']);
    }

    public function test_reducing_operating_losses_still_scores(): void
    {
        [$income] = $this->quarters(10_000_000, 40_000_000, -20_000_000, -15_000_000);
        $result = $this->analyzer->operatingMarginExpansion($income);

        $this->assertSame(16_250.0, $result['operating_margin_expansion_bps']);
    }

    public function test_a_missing_recent_cash_flow_quarter_is_not_replaced_with_stale_data(): void
    {
        [$income, $cash] = $this->quarters(10_000_000, 40_000_000, -20_000_000, -30_000_000);
        // Without a strict latest-eight requirement, this would silently
        // use an older ninth record and present a stale TTM as current.
        $cash[7]['free_cash_flow'] = null;
        $cash[] = [
            'date' => '2024-06-30', 'free_cash_flow' => -5_000_000,
            'reported_currency' => 'USD',
        ];
        $income[] = [
            'date' => '2024-06-30', 'revenue' => 2_500_000,
            'operating_income' => -5_000_000, 'reported_currency' => 'USD',
        ];

        $result = $this->analyzer->fcfMarginExpansion($income, $cash);
        $this->assertNull($result['fcf_margin_expansion_bps']);
    }

    public function test_missing_recent_operating_income_is_not_replaced_with_stale_data(): void
    {
        [$income] = $this->quarters(10_000_000, 40_000_000, -20_000_000, -30_000_000);
        $income[7]['operating_income'] = null;
        $income[] = [
            'date' => '2024-06-30', 'revenue' => 2_500_000,
            'operating_income' => -5_000_000, 'reported_currency' => 'USD',
        ];

        $this->assertNull($this->analyzer->operatingMarginExpansion($income)['operating_margin_expansion_bps']);
    }

    public function test_fcf_and_income_currencies_must_match(): void
    {
        [$income, $cash] = $this->quarters(10_000_000, 40_000_000, -20_000_000, -30_000_000);
        $income[7]['reported_currency'] = 'CAD';
        $this->assertNull($this->analyzer->fcfMarginExpansion($income, $cash)['fcf_margin_expansion_bps']);
    }

    /**
     * Build eight quarters in ascending order (prior TTM, then current TTM).
     * The date matching / sorting code is exercised by the public methods.
     *
     * @return array{array<int,array<string,mixed>>,array<int,array<string,mixed>>}
     */
    private function quarters(float $priorRevenue, float $currentRevenue, float $priorFlow, float $currentFlow): array
    {
        $dates = [
            '2024-09-30', '2024-12-31', '2025-03-31', '2025-06-30',
            '2025-09-30', '2025-12-31', '2026-03-31', '2026-06-30',
        ];
        $income = $cash = [];
        foreach ($dates as $index => $date) {
            $current = $index >= 4;
            $revenue = ($current ? $currentRevenue : $priorRevenue) / 4;
            $flow = ($current ? $currentFlow : $priorFlow) / 4;
            $income[] = [
                'date' => $date, 'revenue' => $revenue,
                'operating_income' => $flow, 'reported_currency' => 'USD',
            ];
            $cash[] = [
                'date' => $date, 'free_cash_flow' => $flow,
                'reported_currency' => 'USD',
            ];
        }

        return [$income, $cash];
    }
}
