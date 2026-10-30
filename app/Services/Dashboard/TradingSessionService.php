<?php

namespace App\Services\Dashboard;

use Carbon\CarbonImmutable;

/**
 * Regional trading-session calendar (Monday–Friday, 09:30–16:00 local time).
 * Exchange holidays are intentionally not modelled.
 */
class TradingSessionService
{
    public const DEFAULT_TIMEZONE = 'America/New_York';

    public const OPEN_HOUR = 9;

    public const OPEN_MINUTE = 30;

    public const CLOSE_HOUR = 16;

    public const CLOSE_MINUTE = 0;

    /**
     * Whether the regional market is currently open in the given timezone.
     */
    public function isTradingHours(string $timezone): bool
    {
        $now = $this->now($timezone);

        if ($now->isWeekend()) {
            return false;
        }

        return $now->greaterThanOrEqualTo($this->openAt($now))
            && $now->lessThan($this->closeAt($now));
    }

    /**
     * Cache TTL (seconds): $activeTtl during the session, otherwise until the next market open.
     */
    public function getTradingHoursTtl(string $timezone, int $activeTtl = 900): int
    {
        if ($this->isTradingHours($timezone)) {
            return $activeTtl;
        }

        return $this->secondsUntilNextMarketOpen($timezone);
    }

    /**
     * Seconds from now until the next regional market open (always at least 1).
     */
    public function secondsUntilNextMarketOpen(string $timezone): int
    {
        $now = $this->now($timezone);
        $candidate = $this->openAt($now);

        if ($now->greaterThanOrEqualTo($candidate) || $now->isWeekend()) {
            $candidate = $this->openAt($now->addDay());
        }

        while ($candidate->isWeekend()) {
            $candidate = $this->openAt($candidate->addDay());
        }

        return max(1, (int) $now->diffInSeconds($candidate, true));
    }

    private function now(string $timezone): CarbonImmutable
    {
        if (! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $timezone = self::DEFAULT_TIMEZONE;
        }

        return CarbonImmutable::now($timezone);
    }

    private function openAt(CarbonImmutable $day): CarbonImmutable
    {
        return $day->setTime(self::OPEN_HOUR, self::OPEN_MINUTE, 0);
    }

    private function closeAt(CarbonImmutable $day): CarbonImmutable
    {
        return $day->setTime(self::CLOSE_HOUR, self::CLOSE_MINUTE, 0);
    }
}
