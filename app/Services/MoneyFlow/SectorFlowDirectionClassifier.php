<?php

namespace App\Services\MoneyFlow;

use App\Enums\SectorFlowDirection;

/**
 * Pure, testable classifier mapping composite (strength, velocity,
 * acceleration) to a SectorFlowDirection. No I/O; thresholds come from
 * config('market_data.moneyflow.direction').
 *
 * Semantics:
 *   - Insufficient history (velocity null) => Stable.
 *   - Rising & speeding up, from a non-weak base => Accelerating.
 *   - Rising            => Improving.
 *   - Falling & speeding down => Weakening.
 *   - Falling           => Cooling.
 *   - Otherwise         => Stable.
 */
class SectorFlowDirectionClassifier
{
    private float $strongStrength;

    private float $weakStrength;

    private float $velocityBand;

    private float $accelerationBand;

    public function __construct()
    {
        $cfg = (array) config('market_data.moneyflow.direction');

        $this->strongStrength = (float) ($cfg['strong_strength'] ?? 60);
        $this->weakStrength = (float) ($cfg['weak_strength'] ?? 40);
        $this->velocityBand = (float) ($cfg['velocity_band'] ?? 0.5);
        $this->accelerationBand = (float) ($cfg['acceleration_band'] ?? 0.5);
    }

    public function classify(?float $strength, ?float $velocity, ?float $acceleration): SectorFlowDirection
    {
        // Not enough prior snapshots to establish a trend yet.
        if ($velocity === null) {
            return SectorFlowDirection::Stable;
        }

        $accel = $acceleration ?? 0.0;
        $strengthValue = $strength ?? 0.0;

        if ($velocity > $this->velocityBand) {
            // Rising and speeding up, but only "accelerating" if it is not a
            // deep laggard bouncing off the floor.
            if ($accel > $this->accelerationBand && $strengthValue >= $this->weakStrength) {
                return SectorFlowDirection::Accelerating;
            }

            return SectorFlowDirection::Improving;
        }

        if ($velocity < -$this->velocityBand) {
            if ($accel < -$this->accelerationBand) {
                return SectorFlowDirection::Weakening;
            }

            return SectorFlowDirection::Cooling;
        }

        return SectorFlowDirection::Stable;
    }

    public function strongStrengthThreshold(): float
    {
        return $this->strongStrength;
    }
}
