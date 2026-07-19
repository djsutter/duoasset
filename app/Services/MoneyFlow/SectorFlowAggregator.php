<?php

namespace App\Services\MoneyFlow;

/**
 * Combines the per-ETF EtfMetrics of one sector into a single SectorFlowResult:
 * weighted-average metrics/scores per timeframe, issuer breadth, composite
 * strength, confidence from issuer agreement, and the constituents breakdown.
 *
 * ETF weights come from config so imperfect sector equivalents can be
 * down-weighted. Only valid ETFs with data for a timeframe contribute to that
 * timeframe; invalid ETFs are still recorded in constituents with their error.
 */
class SectorFlowAggregator
{
    /** @var array<int, int> valid-ETF-count => confidence score */
    private array $confidenceLevels;

    public function __construct(private readonly SectorFlowScorer $scorer)
    {
        $this->confidenceLevels = (array) config('market_data.moneyflow.confidence.levels', []);
    }

    /**
     * @param  array<int, EtfMetrics>  $etfMetrics
     */
    public function aggregate(string $sector, string $label, array $etfMetrics): SectorFlowResult
    {
        $valid = array_values(array_filter($etfMetrics, static fn (EtfMetrics $m) => $m->valid));
        $etfCount = count($valid);

        $constituents = [];
        foreach ($etfMetrics as $metric) {
            $constituents[$metric->symbol] = $metric->toConstituent();
        }

        $periods = [];
        $timeframeScores = [];
        foreach (['hourly', 'daily', 'weekly', 'monthly'] as $timeframe) {
            $periods[$timeframe] = $this->aggregatePeriod($valid, $timeframe);
            $timeframeScores[$timeframe] = $periods[$timeframe]->score;
        }

        $strength = $this->scorer->compositeStrength($timeframeScores);

        return new SectorFlowResult(
            sector: $sector,
            label: $label,
            periods: $periods,
            strength: $strength,
            etfCount: $etfCount,
            confidenceScore: $this->confidence($etfCount),
            dataQualityScore: $this->meanDataQuality($valid),
            constituents: $constituents,
        );
    }

    /**
     * @param  array<int, EtfMetrics>  $valid
     */
    private function aggregatePeriod(array $valid, string $timeframe): SectorPeriodMetrics
    {
        $scores = [];
        $changes = [];
        $rs = [];
        $rv = [];
        $rdv = [];
        $outperformers = 0;
        $withData = 0;

        foreach ($valid as $metric) {
            $period = $metric->period($timeframe);
            if (! $period->hasData) {
                continue;
            }
            $withData++;
            $w = $metric->weight;

            if ($period->score !== null) {
                $scores[] = [$period->score, $w];
            }
            if ($period->changePct !== null) {
                $changes[] = [$period->changePct, $w];
            }
            if ($period->relativeStrength !== null) {
                $rs[] = [$period->relativeStrength, $w];
                if ($period->outperforms) {
                    $outperformers++;
                }
            }
            if ($period->relativeVolume !== null) {
                $rv[] = [$period->relativeVolume, $w];
            }
            if ($period->relativeDollarVolume !== null) {
                $rdv[] = [$period->relativeDollarVolume, $w];
            }
        }

        if ($withData === 0) {
            return SectorPeriodMetrics::empty();
        }

        // Breadth denominator: ETFs with a relative-strength reading.
        $rsCount = count($rs);
        $breadth = $rsCount > 0 ? ($outperformers / $rsCount) * 100.0 : null;

        return new SectorPeriodMetrics(
            hasData: true,
            changePct: $this->weightedMean($changes),
            relativeStrength: $this->weightedMean($rs),
            relativeVolume: $this->weightedMean($rv),
            relativeDollarVolume: $this->weightedMean($rdv),
            score: $this->weightedMean($scores),
            issuerBreadth: $breadth,
        );
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $pairs  [value, weight]
     */
    private function weightedMean(array $pairs): ?float
    {
        $sum = 0.0;
        $weightSum = 0.0;
        foreach ($pairs as [$value, $weight]) {
            if ($weight <= 0.0) {
                continue;
            }
            $sum += $value * $weight;
            $weightSum += $weight;
        }

        return $weightSum > 0.0 ? $sum / $weightSum : null;
    }

    private function confidence(int $etfCount): float
    {
        if ($etfCount >= 5) {
            return 100.0;
        }
        if (isset($this->confidenceLevels[$etfCount])) {
            return (float) $this->confidenceLevels[$etfCount];
        }

        // Below the configured floors (1-2 ETFs): scale down proportionally.
        return round(($etfCount / 5) * 100.0, 2);
    }

    /**
     * @param  array<int, EtfMetrics>  $valid
     */
    private function meanDataQuality(array $valid): float
    {
        if ($valid === []) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($valid as $metric) {
            $sum += $metric->dataQualityScore;
        }

        return $sum / count($valid);
    }
}
