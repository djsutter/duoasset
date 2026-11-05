<?php

namespace App\Services\Notifications;

use App\Models\EarningsAlert;
use App\Models\EarningsScanRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class EpsSurpriseDigestBuilder
{
    public const MARKET_SURPRISES_LIMIT = 50;

    public const MARKET_MISSES_LIMIT = 20;

    public const TOP_SCORES_LIMIT = 60;

    private const SNAPSHOT_SURPRISES_BUFFER = 250;

    private const SNAPSHOT_MISSES_BUFFER = 150;

    public static function cacheKey(int $scanRunId): string
    {
        return "digest_run_snapshot:eps_surprise:{$scanRunId}";
    }

    /**
     * Compute global market-wide rankings ONCE for the completed scanner run,
     * persist the snapshot on the run record and in cache, and return it.
     *
     * @return array<string, mixed>
     */
    public function buildAndStoreRunSnapshot(int $scanRunId): array
    {
        $run = EarningsScanRun::find($scanRunId);

        $alerts = EarningsAlert::query()
            ->with('earningsEvent')
            ->where('scan_run_id', $scanRunId)
            ->where('alert_type', 'eps_surprise')
            ->get();

        $snapshot = $this->compileMarketSnapshotFromAlerts(
            $scanRunId,
            $alerts,
            $run?->completed_at?->toIso8601String() ?? now()->toIso8601String(),
        );

        if ($run) {
            $summaryData = $run->summary_data ?? [];
            $summaryData['digest_snapshot'] = $snapshot;
            $run->summary_data = $summaryData;
            $run->save();
        }

        Cache::put(self::cacheKey($scanRunId), $snapshot, now()->addHours(2));

        return $snapshot;
    }

    /**
     * Retrieve the precomputed global run snapshot, computing it once if absent.
     *
     * @return array<string, mixed>
     */
    public function getOrBuildRunSnapshot(int $scanRunId): array
    {
        $cached = Cache::get(self::cacheKey($scanRunId));
        if (is_array($cached) && isset($cached['scan_run_id'])) {
            return $cached;
        }

        $run = EarningsScanRun::find($scanRunId);
        if ($run && isset($run->summary_data['digest_snapshot']) && is_array($run->summary_data['digest_snapshot'])) {
            $snapshot = $run->summary_data['digest_snapshot'];
            Cache::put(self::cacheKey($scanRunId), $snapshot, now()->addHours(2));

            return $snapshot;
        }

        return $this->buildAndStoreRunSnapshot($scanRunId);
    }

    /**
     * Personalize the EPS Surprise Digest for a user by merging their watchlist
     * results with the precomputed market-wide rankings.
     *
     * @param  list<string>  $watchedSymbols
     * @param  array<string, mixed>|null  $runSnapshot
     * @return array<string, mixed>
     */
    public function buildForUser(int $scanRunId, array $watchedSymbols, ?array $runSnapshot = null): array
    {
        $snapshot = $runSnapshot ?? $this->getOrBuildRunSnapshot($scanRunId);

        $normalizedWatchlist = array_values(array_unique(array_filter(array_map(
            fn ($s) => strtoupper(trim((string) $s)),
            $watchedSymbols,
        ))));
        $watchedMap = array_fill_keys($normalizedWatchlist, true);
        $hasWatchlist = ! empty($normalizedWatchlist);

        $watchlistSurprises = [];
        $watchlistMisses = [];

        if ($hasWatchlist) {
            // Query ONLY the user's watched symbols for this scanner run.
            $watchedAlerts = EarningsAlert::query()
                ->with('earningsEvent')
                ->where('scan_run_id', $scanRunId)
                ->where('alert_type', 'eps_surprise')
                ->whereIn('symbol', $normalizedWatchlist)
                ->get();

            $dedupedByDir = $this->deduplicateBySymbolAndDirection($watchedAlerts);

            foreach ($dedupedByDir as $item) {
                if ($item['direction'] === EarningsAlert::DIRECTION_POSITIVE) {
                    $watchlistSurprises[] = $item;
                } elseif ($item['direction'] === EarningsAlert::DIRECTION_NEGATIVE) {
                    $watchlistMisses[] = $item;
                }
            }

            $this->sortPositiveSurprises($watchlistSurprises);
            $this->sortNegativeMisses($watchlistMisses);
        }

        $marketPositiveCandidates = (array) ($snapshot['top_positive_candidates'] ?? []);
        $marketNegativeCandidates = (array) ($snapshot['bottom_negative_candidates'] ?? []);

        if ($hasWatchlist) {
            $marketPositiveFiltered = array_values(array_filter(
                $marketPositiveCandidates,
                fn (array $item) => ! isset($watchedMap[$item['symbol']]),
            ));
            $marketNegativeFiltered = array_values(array_filter(
                $marketNegativeCandidates,
                fn (array $item) => ! isset($watchedMap[$item['symbol']]),
            ));
        } else {
            $marketPositiveFiltered = $marketPositiveCandidates;
            $marketNegativeFiltered = $marketNegativeCandidates;
        }

        $marketSurprises = array_slice($marketPositiveFiltered, 0, self::MARKET_SURPRISES_LIMIT);
        $marketMisses = array_slice($marketNegativeFiltered, 0, self::MARKET_MISSES_LIMIT);

        // Section E — Top 60 EPS Scores is global rather than watchlist-exclusive.
        // Watchlist items remain in their true global rank and are annotated with is_watched = true.
        $topScores = array_map(function (array $item) use ($watchedMap) {
            $item['is_watched'] = isset($watchedMap[$item['symbol']]);

            return $item;
        }, (array) ($snapshot['top_60_scores'] ?? []));

        $totalMarketSurprises = (int) ($snapshot['totals']['total_market_surprises'] ?? count($marketSurprises));
        $totalMarketMisses = (int) ($snapshot['totals']['total_market_misses'] ?? count($marketMisses));
        $totalNonWatchlistSurprises = max(0, $totalMarketSurprises - count($watchlistSurprises));
        $totalNonWatchlistMisses = max(0, $totalMarketMisses - count($watchlistMisses));
        $totalScoredOpportunities = (int) ($snapshot['totals']['total_scored_opportunities'] ?? count($topScores));

        $isEmpty = empty($watchlistSurprises)
            && empty($watchlistMisses)
            && empty($marketSurprises)
            && empty($marketMisses)
            && empty($topScores);

        return [
            'category' => 'eps_surprise',
            'scan_run_id' => $scanRunId,
            'completed_at' => $snapshot['completed_at'] ?? now()->toIso8601String(),
            'has_watchlist' => $hasWatchlist,
            'is_empty' => $isEmpty,
            'watchlist_surprises' => $watchlistSurprises,
            'watchlist_misses' => $watchlistMisses,
            'market_surprises' => $marketSurprises,
            'market_misses' => $marketMisses,
            'top_scores' => $topScores,
            'summary_counts' => [
                'watchlist_surprises' => count($watchlistSurprises),
                'watchlist_misses' => count($watchlistMisses),
                'market_surprises_displayed' => count($marketSurprises),
                'market_surprises_total' => $totalMarketSurprises,
                'market_non_watchlist_surprises_total' => $totalNonWatchlistSurprises,
                'market_misses_displayed' => count($marketMisses),
                'market_misses_total' => $totalMarketMisses,
                'market_non_watchlist_misses_total' => $totalNonWatchlistMisses,
                'top_scores_displayed' => count($topScores),
                'top_scores_total' => $totalScoredOpportunities,
            ],
        ];
    }

    /**
     * @param  Collection<int, EarningsAlert>  $alerts
     * @return array<string, mixed>
     */
    private function compileMarketSnapshotFromAlerts(int $scanRunId, Collection $alerts, string $completedAt): array
    {
        $dedupedByDir = $this->deduplicateBySymbolAndDirection($alerts);

        $positiveSurprises = [];
        $negativeMisses = [];

        foreach ($dedupedByDir as $item) {
            if ($item['direction'] === EarningsAlert::DIRECTION_POSITIVE) {
                $positiveSurprises[] = $item;
            } elseif ($item['direction'] === EarningsAlert::DIRECTION_NEGATIVE) {
                $negativeMisses[] = $item;
            }
        }

        $this->sortPositiveSurprises($positiveSurprises);
        $this->sortNegativeMisses($negativeMisses);

        // Build global Top 60 EPS Scores across all scored alerts in this run, deduplicated by symbol.
        $scoredBySymbol = [];
        foreach ($dedupedByDir as $item) {
            if ($item['score'] === null || (int) $item['score'] <= 0) {
                continue;
            }

            $sym = $item['symbol'];
            if (! isset($scoredBySymbol[$sym])) {
                $scoredBySymbol[$sym] = $item;

                continue;
            }

            $existing = $scoredBySymbol[$sym];
            if (
                $item['score'] > $existing['score']
                || ($item['score'] === $existing['score'] && $item['eps_surprise_percent'] > $existing['eps_surprise_percent'])
            ) {
                $scoredBySymbol[$sym] = $item;
            }
        }

        $allScored = array_values($scoredBySymbol);
        usort($allScored, function (array $a, array $b): int {
            $scoreCmp = $b['score'] <=> $a['score'];
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }

            $pctCmp = $b['eps_surprise_percent'] <=> $a['eps_surprise_percent'];
            if ($pctCmp !== 0) {
                return $pctCmp;
            }

            return strcmp($a['symbol'], $b['symbol']);
        });

        $top60Scores = array_slice($allScored, 0, self::TOP_SCORES_LIMIT);
        foreach ($top60Scores as $idx => &$row) {
            $row['rank'] = $idx + 1;
        }
        unset($row);

        return [
            'scan_run_id' => $scanRunId,
            'completed_at' => $completedAt,
            'is_run_empty' => empty($positiveSurprises) && empty($negativeMisses) && empty($top60Scores),
            'totals' => [
                'total_market_surprises' => count($positiveSurprises),
                'total_market_misses' => count($negativeMisses),
                'total_scored_opportunities' => count($allScored),
            ],
            'top_positive_candidates' => array_slice($positiveSurprises, 0, self::SNAPSHOT_SURPRISES_BUFFER),
            'bottom_negative_candidates' => array_slice($negativeMisses, 0, self::SNAPSHOT_MISSES_BUFFER),
            'top_60_scores' => $top60Scores,
        ];
    }

    /**
     * Consolidate duplicate alerts per (symbol, direction) within the run,
     * preferring the most recent report_date and highest event/alert ID.
     *
     * @param  Collection<int, EarningsAlert>  $alerts
     * @return list<array<string, mixed>>
     */
    private function deduplicateBySymbolAndDirection(Collection $alerts): array
    {
        $PositiveThreshold = (float) config(
            'market_data.earnings_scanner.positive_threshold',
            config('market_data.earnings_scanner.min_eps_surprise_percent', 90),
        );
        $negativeThreshold = (float) config('market_data.earnings_scanner.negative_threshold', -30);

        $map = [];

        foreach ($alerts as $alert) {
            $event = $alert->earningsEvent;
            if (! $event) {
                continue;
            }

            $symbol = strtoupper(trim((string) ($alert->symbol ?: $event->symbol)));
            if ($symbol === '') {
                continue;
            }

            if ($event->eps_surprise_percent === null || $event->eps_actual === null || $event->eps_estimated === null) {
                continue;
            }

            $surprisePct = (float) $event->eps_surprise_percent;
            if (! is_finite($surprisePct)) {
                continue;
            }

            $direction = $alert->direction;
            if ($direction === EarningsAlert::DIRECTION_POSITIVE && $surprisePct < $PositiveThreshold) {
                continue;
            }
            if ($direction === EarningsAlert::DIRECTION_NEGATIVE && $surprisePct > $negativeThreshold) {
                continue;
            }

            $reportDate = $event->report_date ? $event->report_date->toDateString() : '';
            $item = [
                'alert_id' => (int) $alert->id,
                'earnings_event_id' => (int) $event->id,
                'symbol' => $symbol,
                'company_name' => $event->company_name ?: $symbol,
                'exchange' => $event->exchange,
                'direction' => $direction,
                'eps_actual' => (float) $event->eps_actual,
                'eps_estimated' => (float) $event->eps_estimated,
                'eps_surprise' => $event->eps_surprise !== null ? (float) $event->eps_surprise : ((float) $event->eps_actual - (float) $event->eps_estimated),
                'eps_surprise_percent' => round($surprisePct, 2),
                'revenue_surprise_percent' => $event->revenue_surprise_percent !== null ? round((float) $event->revenue_surprise_percent, 2) : null,
                'relative_volume' => $event->relative_volume !== null ? round((float) $event->relative_volume, 2) : null,
                'market_cap' => $event->market_cap !== null ? (int) $event->market_cap : null,
                'score' => $alert->score !== null ? (int) $alert->score : null,
                'report_date' => $reportDate,
                'dashboard_url' => url('/stock/'.strtolower($symbol)),
                'is_watched' => false,
            ];

            $key = $symbol.'|'.$direction;
            if (! isset($map[$key])) {
                $map[$key] = $item;

                continue;
            }

            $existing = $map[$key];
            if (
                strcmp($item['report_date'], $existing['report_date']) > 0
                || ($item['report_date'] === $existing['report_date'] && $item['alert_id'] > $existing['alert_id'])
            ) {
                $map[$key] = $item;
            }
        }

        return array_values($map);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function sortPositiveSurprises(array &$items): void
    {
        usort($items, function (array $a, array $b): int {
            $pctCmp = $b['eps_surprise_percent'] <=> $a['eps_surprise_percent'];
            if ($pctCmp !== 0) {
                return $pctCmp;
            }

            $scoreCmp = ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }

            return strcmp($a['symbol'], $b['symbol']);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function sortNegativeMisses(array &$items): void
    {
        usort($items, function (array $a, array $b): int {
            $pctCmp = $a['eps_surprise_percent'] <=> $b['eps_surprise_percent'];
            if ($pctCmp !== 0) {
                return $pctCmp;
            }

            $scoreCmp = ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }

            return strcmp($a['symbol'], $b['symbol']);
        });
    }
}
