<?php

namespace App\Services\MoneyFlow;

use App\Models\SectorFlowSnapshot;
use App\Services\MarketData\MarketDataProvider;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates one Sector Money Flows capture as a single, coordinated
 * whole-market pass — required because ranking, benchmarking and
 * cross-sectional standing are only meaningful across all sectors at once.
 *
 * Responsibilities are delegated:
 *   - SectorEtfMetricsCalculator : one ETF's normalized period metrics
 *   - SectorFlowAggregator       : combine ETFs into one sector result
 *   - SectorFlowScorer           : composite strength / velocity / accel
 *   - SectorFlowDirectionClassifier : direction
 *   - SectorFlowSnapshotRepository  : prior-snapshot lookup + persistence
 *
 * This service fetches the bars, wires those together, computes cross-sectional
 * rank/percentile and (from prior snapshots) velocity/acceleration, then
 * persists every sector transactionally.
 */
class SectorMoneyFlowService
{
    public function __construct(
        private readonly MarketDataProvider $provider,
        private readonly SectorEtfMetricsCalculator $calculator,
        private readonly SectorFlowAggregator $aggregator,
        private readonly SectorFlowScorer $scorer,
        private readonly SectorFlowDirectionClassifier $classifier,
        private readonly SectorFlowSnapshotRepository $repository,
    ) {}

    /**
     * @param  array<int, string>|null  $sectors  subset of sector keys, or null for all
     */
    public function capture(
        string $interval = SectorFlowSnapshot::INTERVAL_EOD,
        ?array $sectors = null,
        ?CarbonImmutable $asOf = null,
    ): SectorMoneyFlowRunSummary {
        $cfg = (array) config('market_data.moneyflow');
        $universe = (array) config('market_data.sector_etfs');

        $timezone = (string) ($cfg['market_timezone'] ?? 'America/New_York');
        $asOf ??= CarbonImmutable::now($timezone);
        $capturedSlot = $interval === SectorFlowSnapshot::INTERVAL_HOURLY
            ? $asOf->setTimezone($timezone)->format('H')
            : SectorFlowSnapshot::SLOT_EOD;

        $minEtfs = (int) ($cfg['confidence']['min_etfs_to_publish'] ?? 3);

        if ($sectors !== null) {
            $universe = array_intersect_key($universe, array_flip($sectors));
        }

        if (method_exists($this->provider, 'clearErrors')) {
            $this->provider->clearErrors();
        }

        // Benchmark bars fetched once for the whole pass.
        $benchmarkSymbol = (string) ($cfg['benchmark_symbol'] ?? 'SPY');
        $benchmarkDaily = $this->fetchDaily($benchmarkSymbol, $asOf, $cfg);
        $benchmarkHourly = $this->fetchHourly($benchmarkSymbol, $asOf, $cfg);

        $latestSession = $this->lastBarDate($benchmarkDaily);

        /** @var array<string, SectorFlowResult> $results */
        $results = [];
        /** @var array<string, string> $skipped */
        $skipped = [];

        foreach ($universe as $sectorKey => $sectorConfig) {
            $label = (string) ($sectorConfig['label'] ?? ucfirst((string) $sectorKey));
            $etfMetrics = [];

            foreach ((array) ($sectorConfig['etfs'] ?? []) as $issuer => $etf) {
                $symbol = strtoupper((string) ($etf['symbol'] ?? ''));
                if ($symbol === '') {
                    continue;
                }
                $weight = (float) ($etf['weight'] ?? 1.0);

                $daily = $this->fetchDaily($symbol, $asOf, $cfg);
                $hourly = $this->fetchHourly($symbol, $asOf, $cfg);
                $latestSession = $this->maxDate($latestSession, $this->lastBarDate($daily));

                $etfMetrics[] = $this->calculator->calculate(
                    $symbol,
                    (string) $issuer,
                    $weight,
                    $daily,
                    $hourly,
                    $benchmarkDaily,
                    $benchmarkHourly,
                );
            }

            $result = $this->aggregator->aggregate((string) $sectorKey, $label, $etfMetrics);

            if ($result->etfCount < $minEtfs) {
                $skipped[(string) $sectorKey] = "only {$result->etfCount} valid ETF(s); need {$minEtfs}";

                continue;
            }
            if ($result->strength === null) {
                $skipped[(string) $sectorKey] = 'insufficient price history for any timeframe';

                continue;
            }

            $results[(string) $sectorKey] = $result;
        }

        $snapshotDate = $latestSession ?? $asOf->toDateString();
        $ranks = $this->rankByStrength($results);

        $summary = new SectorMoneyFlowRunSummary(
            interval: $interval,
            capturedSlot: $capturedSlot,
            snapshotDate: $snapshotDate,
            capturedAt: $asOf,
            publishedSectors: array_keys($results),
            skipped: $skipped,
            providerErrors: method_exists($this->provider, 'lastErrors') ? $this->provider->lastErrors() : [],
        );

        if ($results === []) {
            return $summary;
        }

        DB::transaction(function () use ($results, $ranks, $snapshotDate, $asOf, $interval, $capturedSlot) {
            foreach ($results as $sectorKey => $result) {
                $previous = $this->repository->previous($sectorKey, $interval, $asOf);
                [$velocities, $accelerations] = $this->deriveMotion($result, $previous);

                $data = new SectorFlowSnapshotData(
                    result: $result,
                    snapshotDate: $snapshotDate,
                    capturedAt: $asOf,
                    interval: $interval,
                    capturedSlot: $capturedSlot,
                    rank: $ranks[$sectorKey]['rank'] ?? null,
                    percentileRank: $ranks[$sectorKey]['percentile'] ?? null,
                    velocities: $velocities,
                    accelerations: $accelerations,
                    compositeVelocity: $this->scorer->compositeByTimeframe($velocities),
                    compositeAcceleration: $this->scorer->compositeByTimeframe($accelerations),
                    direction: $this->classifier->classify(
                        $result->strength,
                        $this->scorer->compositeByTimeframe($velocities),
                        $this->scorer->compositeByTimeframe($accelerations),
                    ),
                );

                $this->repository->persist($data);
            }
        });

        return $summary;
    }

    /**
     * Per-timeframe velocity (Δscore) and acceleration (Δvelocity) vs the
     * previous same-interval snapshot. Both null when no prior snapshot; each
     * timeframe independent, so a newly-available timeframe starts null.
     *
     * @return array{0: array<string, float|null>, 1: array<string, float|null>}
     */
    private function deriveMotion(SectorFlowResult $result, ?SectorFlowSnapshot $previous): array
    {
        $velocities = [];
        $accelerations = [];

        foreach (SectorFlowSnapshot::TIMEFRAMES as $tf) {
            $currentScore = $result->score($tf);
            $velocities[$tf] = null;
            $accelerations[$tf] = null;

            if ($previous === null || $currentScore === null) {
                continue;
            }

            $prevScore = $this->floatOrNull($previous->{"{$tf}_score"});
            if ($prevScore !== null) {
                $velocities[$tf] = $currentScore - $prevScore;

                $prevVelocity = $this->floatOrNull($previous->{"{$tf}_velocity"});
                if ($prevVelocity !== null) {
                    $accelerations[$tf] = $velocities[$tf] - $prevVelocity;
                }
            }
        }

        return [$velocities, $accelerations];
    }

    /**
     * Cross-sectional standing across the published sectors, by strength desc.
     * Percentile is 100 for the top and 0 for the bottom of the cohort.
     *
     * @param  array<string, SectorFlowResult>  $results
     * @return array<string, array{rank: int, percentile: float}>
     */
    private function rankByStrength(array $results): array
    {
        $sorted = $results;
        uasort($sorted, static fn (SectorFlowResult $a, SectorFlowResult $b) => ($b->strength ?? -INF) <=> ($a->strength ?? -INF));

        $n = count($sorted);
        $ranks = [];
        $i = 0;
        foreach ($sorted as $sectorKey => $_result) {
            $rank = $i + 1;
            $percentile = $n > 1 ? (($n - $rank) / ($n - 1)) * 100.0 : 100.0;
            $ranks[$sectorKey] = ['rank' => $rank, 'percentile' => round($percentile, 2)];
            $i++;
        }

        return $ranks;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @return array<int, array<string, mixed>>
     */
    private function fetchDaily(string $symbol, CarbonInterface $asOf, array $cfg): array
    {
        $lookback = (int) ($cfg['history_lookback_days'] ?? 400);

        return $this->provider->historicalDailyBars(
            $symbol,
            $asOf->subDays($lookback),
            $asOf,
        );
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @return array<int, array<string, mixed>>
     */
    private function fetchHourly(string $symbol, CarbonInterface $asOf, array $cfg): array
    {
        $intraday = (array) ($cfg['intraday'] ?? []);
        $interval = (string) ($intraday['interval'] ?? '1hour');
        $lookback = (int) ($intraday['lookback_days'] ?? 15);

        return $this->provider->historicalIntradayBars(
            $symbol,
            $interval,
            $asOf->subDays($lookback),
            $asOf,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $bars
     */
    private function lastBarDate(array $bars): ?string
    {
        if ($bars === []) {
            return null;
        }
        $last = $bars[count($bars) - 1];
        $date = $last['date'] ?? null;

        return $date !== null ? substr((string) $date, 0, 10) : null;
    }

    private function maxDate(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return strcmp($a, $b) >= 0 ? $a : $b;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
