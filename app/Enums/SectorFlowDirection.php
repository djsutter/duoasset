<?php

namespace App\Enums;

/**
 * Trend classification for a sector's money-flow snapshot, derived from the
 * composite strength/velocity/acceleration by SectorFlowDirectionClassifier.
 *
 * Ordered strongest-trend to weakest. "capitulation" is intentionally NOT
 * included in Phase 1 (no tested threshold for it yet).
 */
enum SectorFlowDirection: string
{
    case Accelerating = 'accelerating';
    case Improving = 'improving';
    case Stable = 'stable';
    case Cooling = 'cooling';
    case Weakening = 'weakening';

    public function label(): string
    {
        return match ($this) {
            self::Accelerating => 'Accelerating',
            self::Improving => 'Improving',
            self::Stable => 'Stable',
            self::Cooling => 'Cooling',
            self::Weakening => 'Weakening',
        };
    }

    /**
     * Flows strengthening (rank/attention rising).
     */
    public function isPositive(): bool
    {
        return $this === self::Accelerating || $this === self::Improving;
    }

    /**
     * Flows weakening (rank/attention falling).
     */
    public function isNegative(): bool
    {
        return $this === self::Cooling || $this === self::Weakening;
    }
}
