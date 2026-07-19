<?php

namespace App\Services\MoneyFlow;

/**
 * One ETF's computed metrics across all timeframes, plus data-quality info.
 *
 * `valid` is false when the ETF could not be evaluated at all (e.g. no daily
 * bars); such ETFs are excluded from the sector aggregation and recorded in
 * the constituents JSON with their `error` so failures are explainable.
 */
final class EtfMetrics
{
    /**
     * @param  array<string, PeriodMetrics>  $periods  keyed by timeframe
     */
    public function __construct(
        public readonly string $symbol,
        public readonly string $issuer,
        public readonly float $weight,
        public readonly bool $valid,
        public readonly ?string $error,
        public readonly ?float $currentPrice,
        public readonly float $dataQualityScore,
        public readonly array $periods,
    ) {}

    public function period(string $timeframe): PeriodMetrics
    {
        return $this->periods[$timeframe] ?? PeriodMetrics::empty();
    }

    public static function invalid(string $symbol, string $issuer, float $weight, string $error): self
    {
        return new self(
            symbol: $symbol,
            issuer: $issuer,
            weight: $weight,
            valid: false,
            error: $error,
            currentPrice: null,
            dataQualityScore: 0.0,
            periods: [],
        );
    }

    /**
     * Per-ETF entry for the snapshot's constituents JSON.
     *
     * @return array<string, mixed>
     */
    public function toConstituent(): array
    {
        $out = [
            'issuer' => $this->issuer,
            'weight' => $this->weight,
            'valid' => $this->valid,
            'current_price' => $this->currentPrice,
            'data_quality_score' => round($this->dataQualityScore, 2),
            'error' => $this->error,
        ];

        foreach ($this->periods as $timeframe => $period) {
            if (! $period->hasData) {
                continue;
            }
            $out[$timeframe] = [
                'change_pct' => self::r($period->changePct),
                'relative_strength' => self::r($period->relativeStrength),
                'relative_volume' => self::r($period->relativeVolume),
                'relative_dollar_volume' => self::r($period->relativeDollarVolume),
                'score' => self::r($period->score, 2),
                'outperforms' => $period->outperforms,
            ];
        }

        return $out;
    }

    private static function r(?float $v, int $precision = 4): ?float
    {
        return $v === null ? null : round($v, $precision);
    }
}
