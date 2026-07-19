<?php

namespace App\Services\MoneyFlow;

use App\Enums\SectorFlowDirection;
use Carbon\CarbonInterface;

/**
 * The complete, structured persist payload for one sector capture: the
 * aggregated SectorFlowResult plus the cross-sectional standing (rank/
 * percentile) and temporal fields (velocity/acceleration/direction) layered
 * on by SectorMoneyFlowService. Converts to the sector_flow_snapshots columns.
 */
final class SectorFlowSnapshotData
{
    /**
     * @param  array<string, float|null>  $velocities  keyed by timeframe
     * @param  array<string, float|null>  $accelerations  keyed by timeframe
     */
    public function __construct(
        public readonly SectorFlowResult $result,
        public readonly string $snapshotDate,
        public readonly CarbonInterface $capturedAt,
        public readonly string $interval,
        public readonly string $capturedSlot,
        public readonly ?int $rank,
        public readonly ?float $percentileRank,
        public readonly array $velocities,
        public readonly array $accelerations,
        public readonly ?float $compositeVelocity,
        public readonly ?float $compositeAcceleration,
        public readonly SectorFlowDirection $direction,
    ) {}

    /**
     * @return array{sector: string, snapshot_date: string, captured_slot: string}
     */
    public function identity(): array
    {
        return [
            'sector' => $this->result->sector,
            'snapshot_date' => $this->snapshotDate,
            'captured_slot' => $this->capturedSlot,
        ];
    }

    /**
     * All non-identity columns for updateOrCreate.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $values = [
            'label' => $this->result->label,
            'captured_at' => $this->capturedAt,
            'interval' => $this->interval,
            'strength' => self::r2($this->result->strength),
            'rank' => $this->rank,
            'percentile_rank' => self::r2($this->percentileRank),
            'velocity' => self::r6($this->compositeVelocity),
            'acceleration' => self::r6($this->compositeAcceleration),
            'direction' => $this->direction->value,
            'confidence_score' => self::r2($this->result->confidenceScore),
            'data_quality_score' => self::r2($this->result->dataQualityScore),
            'etf_count' => $this->result->etfCount,
            'constituents' => $this->result->constituents,
        ];

        foreach (['hourly', 'daily', 'weekly', 'monthly'] as $tf) {
            $period = $this->result->period($tf);
            $values["{$tf}_change_pct"] = self::r4($period->changePct);
            $values["{$tf}_relative_strength"] = self::r4($period->relativeStrength);
            $values["{$tf}_relative_volume"] = self::r4($period->relativeVolume);
            $values["{$tf}_relative_dollar_volume"] = self::r4($period->relativeDollarVolume);
            $values["{$tf}_score"] = self::r2($period->score);
            $values["{$tf}_velocity"] = self::r6($this->velocities[$tf] ?? null);
            $values["{$tf}_acceleration"] = self::r6($this->accelerations[$tf] ?? null);
            $values["issuer_breadth_{$tf}"] = self::r2($period->issuerBreadth);
        }

        return $values;
    }

    private static function r2(?float $v): ?float
    {
        return $v === null ? null : round($v, 2);
    }

    private static function r4(?float $v): ?float
    {
        return $v === null ? null : round($v, 4);
    }

    private static function r6(?float $v): ?float
    {
        return $v === null ? null : round($v, 6);
    }
}
