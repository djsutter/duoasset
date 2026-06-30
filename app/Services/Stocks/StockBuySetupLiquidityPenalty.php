<?php

namespace App\Services\Stocks;

/**
 * Applies a continuous sleepy-volume penalty based on daily turnover:
 * average daily volume divided by the number of shares available to trade.
 *
 * We intentionally keep only the cap-bucket max penalties configurable.
 * The turnover model itself stays simple and stable:
 *   - >= 0.25% daily turnover: no liquidity penalty
 *   - <= 0.01% daily turnover: full bucket penalty
 *   - between those values: linear partial penalty
 */
class StockBuySetupLiquidityPenalty
{
    private const NO_PENALTY_TURNOVER_PCT = 0.25;

    private const FULL_PENALTY_TURNOVER_PCT = 0.01;

    /**
     * @param  array<int, array<string, mixed>>  $bars  Ascending OHLCV bars.
     * @return array{average_volume: ?int, shares_basis: ?int, shares_basis_type: string, turnover_pct: ?float, max_penalty_pct: float, penalty_pct: float, penalty_points: int, adjusted_score: int}
     */
    public function apply(int $rawScore, string $marketCapCategory, ?int $floatShares, ?int $sharesOutstanding, array $bars): array
    {
        $avgVolume = $this->averageVolume($bars);
        $sharesBasis = $floatShares && $floatShares > 0 ? $floatShares : ($sharesOutstanding && $sharesOutstanding > 0 ? $sharesOutstanding : null);
        $basisType = $floatShares && $floatShares > 0 ? 'float_shares' : (($sharesOutstanding && $sharesOutstanding > 0) ? 'shares_outstanding' : 'unknown');
        $maxPenaltyPct = $this->maxPenaltyPct($marketCapCategory);

        if ($rawScore <= 0 || $avgVolume === null || $sharesBasis === null || $maxPenaltyPct <= 0) {
            return [
                'average_volume' => $avgVolume,
                'shares_basis' => $sharesBasis,
                'shares_basis_type' => $basisType,
                'turnover_pct' => ($avgVolume !== null && $sharesBasis !== null && $sharesBasis > 0) ? round(($avgVolume / $sharesBasis) * 100, 6) : null,
                'max_penalty_pct' => $maxPenaltyPct,
                'penalty_pct' => 0.0,
                'penalty_points' => 0,
                'adjusted_score' => max(0, min(100, $rawScore)),
            ];
        }

        $turnoverPct = ($avgVolume / $sharesBasis) * 100;
        $sleepiness = $this->sleepiness($turnoverPct);
        $penaltyPct = round($sleepiness * $maxPenaltyPct, 4);
        $adjustedScore = (int) round($rawScore * ((100 - $penaltyPct) / 100));
        $adjustedScore = max(0, min(100, $adjustedScore));

        return [
            'average_volume' => $avgVolume,
            'shares_basis' => $sharesBasis,
            'shares_basis_type' => $basisType,
            'turnover_pct' => round($turnoverPct, 6),
            'max_penalty_pct' => $maxPenaltyPct,
            'penalty_pct' => $penaltyPct,
            'penalty_points' => max(0, $rawScore - $adjustedScore),
            'adjusted_score' => $adjustedScore,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $bars
     */
    private function averageVolume(array $bars): ?int
    {
        $recent = array_slice($bars, -50);
        $volumes = [];
        foreach ($recent as $bar) {
            if (isset($bar['volume']) && is_numeric($bar['volume'])) {
                $volumes[] = (int) $bar['volume'];
            }
        }

        if (empty($volumes)) {
            return null;
        }

        return (int) round(array_sum($volumes) / count($volumes));
    }

    private function maxPenaltyPct(string $marketCapCategory): float
    {
        $penalties = (array) config('market_data.buy_setup_scanner.sleepy_volume_penalties', []);
        $category = strtolower(trim($marketCapCategory));

        if ($category === 'mega') {
            $category = 'large';
        } elseif ($category === 'mid') {
            $category = 'medium';
        } elseif ($category === 'unknown') {
            return 0.0;
        }

        return max(0.0, min(100.0, (float) ($penalties[$category] ?? 0)));
    }

    private function sleepiness(float $turnoverPct): float
    {
        if ($turnoverPct >= self::NO_PENALTY_TURNOVER_PCT) {
            return 0.0;
        }

        if ($turnoverPct <= self::FULL_PENALTY_TURNOVER_PCT) {
            return 1.0;
        }

        $range = self::NO_PENALTY_TURNOVER_PCT - self::FULL_PENALTY_TURNOVER_PCT;

        return max(0.0, min(1.0, (self::NO_PENALTY_TURNOVER_PCT - $turnoverPct) / $range));
    }
}
