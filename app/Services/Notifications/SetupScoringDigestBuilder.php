<?php

namespace App\Services\Notifications;

use App\Models\StockBuySetupAlert;
use App\Models\StockBuySetupRun;
use App\Services\Stocks\BuySetupConfigService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class SetupScoringDigestBuilder
{
    public const DEFAULT_OTHER_NOTICES_LIMIT = 50;

    private const SNAPSHOT_NOTICES_BUFFER = 250;

    public static function cacheKey(int $scanRunId): string
    {
        return "digest_run_snapshot:setup_scoring:{$scanRunId}";
    }

    /**
     * Compute global consolidated setup scoring results ONCE for the completed run,
     * persist the snapshot on the run record and in cache, and return it.
     *
     * @return array<string, mixed>
     */
    public function buildAndStoreRunSnapshot(int $scanRunId): array
    {
        $run = StockBuySetupRun::find($scanRunId);
        $configService = app(BuySetupConfigService::class);
        $notifyMinScore = $configService->getNotifyMinSetupScore();
        $otherNoticesLimit = (int) config(
            'market_data.buy_setup_scanner.digest_other_notices_limit',
            self::DEFAULT_OTHER_NOTICES_LIMIT,
        );

        $alerts = $this->queryAlertsForRun($scanRunId, $run);

        $consolidated = $this->consolidateBySymbol($alerts);

        // Significant market-wide setup notices: meet notify_min_setup_score and
        // represent either a newly scored setup or a score change (score_delta != 0).
        $significantNotices = array_values(array_filter(
            $consolidated,
            fn (array $item) => $item['setup_score'] >= $notifyMinScore
                && ($item['is_newly_scored'] || ($item['score_delta'] !== null && $item['score_delta'] !== 0)),
        ));

        usort($significantNotices, function (array $a, array $b): int {
            $scoreCmp = $b['setup_score'] <=> $a['setup_score'];
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }

            $deltaA = $a['score_delta'] ?? 0;
            $deltaB = $b['score_delta'] ?? 0;
            $deltaCmp = $deltaB <=> $deltaA;
            if ($deltaCmp !== 0) {
                return $deltaCmp;
            }

            return strcmp($a['symbol'], $b['symbol']);
        });

        $snapshot = [
            'scan_run_id' => $scanRunId,
            'completed_at' => $run?->completed_at?->toIso8601String() ?? now()->toIso8601String(),
            'notify_min_score' => $notifyMinScore,
            'other_notices_limit' => $otherNoticesLimit,
            'is_run_empty' => empty($consolidated),
            'totals' => [
                'total_consolidated_symbols' => count($consolidated),
                'total_significant_notices' => count($significantNotices),
            ],
            'other_notices_candidates' => array_slice($significantNotices, 0, self::SNAPSHOT_NOTICES_BUFFER),
        ];

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

        $run = StockBuySetupRun::find($scanRunId);
        if ($run && isset($run->summary_data['digest_snapshot']) && is_array($run->summary_data['digest_snapshot'])) {
            $snapshot = $run->summary_data['digest_snapshot'];
            Cache::put(self::cacheKey($scanRunId), $snapshot, now()->addHours(2));

            return $snapshot;
        }

        return $this->buildAndStoreRunSnapshot($scanRunId);
    }

    /**
     * Personalize the Setup Scoring Digest for a user by merging their watchlist
     * results with the precomputed market-wide notices.
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

        $watchlistImprovements = [];
        $watchlistDrops = [];
        $watchlistNewlyScored = [];
        $watchedSignificantCount = 0;
        $notifyMinScore = (int) ($snapshot['notify_min_score'] ?? 50);

        if ($hasWatchlist) {
            // Query ONLY the user's watched symbols for this scanner run.
            $watchedAlerts = $this->queryAlertsForRun($scanRunId, null, $normalizedWatchlist);

            $consolidatedWatched = $this->consolidateBySymbol($watchedAlerts);

            foreach ($consolidatedWatched as $item) {
                $item['is_watched'] = true;

                if ($item['setup_score'] >= $notifyMinScore && ($item['is_newly_scored'] || ($item['score_delta'] !== null && $item['score_delta'] !== 0))) {
                    $watchedSignificantCount++;
                }

                if ($item['is_newly_scored']) {
                    $watchlistNewlyScored[] = $item;
                } elseif ($item['score_delta'] > 0) {
                    $watchlistImprovements[] = $item;
                } elseif ($item['score_delta'] < 0) {
                    $watchlistDrops[] = $item;
                }
            }

            // Sort Section A: largest positive change first
            usort($watchlistImprovements, function (array $a, array $b): int {
                $deltaCmp = ($b['score_delta'] ?? 0) <=> ($a['score_delta'] ?? 0);
                if ($deltaCmp !== 0) {
                    return $deltaCmp;
                }

                $scoreCmp = $b['setup_score'] <=> $a['setup_score'];
                if ($scoreCmp !== 0) {
                    return $scoreCmp;
                }

                return strcmp($a['symbol'], $b['symbol']);
            });

            // Sort Section B: largest deterioration (most negative delta) first
            usort($watchlistDrops, function (array $a, array $b): int {
                $deltaCmp = ($a['score_delta'] ?? 0) <=> ($b['score_delta'] ?? 0);
                if ($deltaCmp !== 0) {
                    return $deltaCmp;
                }

                $scoreCmp = $a['setup_score'] <=> $b['setup_score'];
                if ($scoreCmp !== 0) {
                    return $scoreCmp;
                }

                return strcmp($a['symbol'], $b['symbol']);
            });

            // Sort Section C: highest setup score first
            usort($watchlistNewlyScored, function (array $a, array $b): int {
                $scoreCmp = $b['setup_score'] <=> $a['setup_score'];
                if ($scoreCmp !== 0) {
                    return $scoreCmp;
                }

                return strcmp($a['symbol'], $b['symbol']);
            });
        }

        $otherLimit = (int) ($snapshot['other_notices_limit'] ?? self::DEFAULT_OTHER_NOTICES_LIMIT);
        $otherCandidates = (array) ($snapshot['other_notices_candidates'] ?? []);

        if ($hasWatchlist) {
            $otherFiltered = array_values(array_filter(
                $otherCandidates,
                fn (array $item) => ! isset($watchedMap[$item['symbol']]),
            ));
        } else {
            $otherFiltered = $otherCandidates;
        }

        $otherNotices = array_slice($otherFiltered, 0, $otherLimit);
        $totalSignificantNotices = (int) ($snapshot['totals']['total_significant_notices'] ?? count($otherCandidates));
        $totalOtherNotices = max(count($otherNotices), $totalSignificantNotices - $watchedSignificantCount);

        $isEmpty = empty($watchlistImprovements)
            && empty($watchlistDrops)
            && empty($watchlistNewlyScored)
            && empty($otherNotices);

        return [
            'category' => 'setup_scoring',
            'scan_run_id' => $scanRunId,
            'completed_at' => $snapshot['completed_at'] ?? now()->toIso8601String(),
            'has_watchlist' => $hasWatchlist,
            'is_empty' => $isEmpty,
            'watchlist_improvements' => $watchlistImprovements,
            'watchlist_drops' => $watchlistDrops,
            'watchlist_newly_scored' => $watchlistNewlyScored,
            'other_notices' => $otherNotices,
            'watchlists_dashboard_url' => url('/watchlist/stock-buy-setups'),
            'summary_counts' => [
                'watchlist_improvements' => count($watchlistImprovements),
                'watchlist_drops' => count($watchlistDrops),
                'watchlist_newly_scored' => count($watchlistNewlyScored),
                'other_notices_displayed' => count($otherNotices),
                'other_notices_total' => $totalOtherNotices,
                'other_notices_limit' => $otherLimit,
            ],
        ];
    }

    /**
     * Consolidate multiple setup type matches for the same symbol in one logical run.
     * Selects the highest-scoring setup for the symbol while preserving previous_setup_score.
     *
     * @param  Collection<int, StockBuySetupAlert>  $alerts
     * @return list<array<string, mixed>>
     */
    private function consolidateBySymbol(Collection $alerts): array
    {
        $bySymbol = [];

        foreach ($alerts as $alert) {
            $symbol = strtoupper(trim((string) $alert->symbol));
            if ($symbol === '') {
                continue;
            }

            $currentScore = (int) $alert->setup_score;
            $prevScore = $alert->previous_setup_score !== null ? (int) $alert->previous_setup_score : null;
            $delta = $prevScore !== null
                ? ($alert->score_delta !== null ? (int) $alert->score_delta : ($currentScore - $prevScore))
                : null;
            $isNewlyScored = $prevScore === null;

            $spikeDate = $alert->spike_date ? $alert->spike_date->toDateString() : '';
            $setupType = (string) $alert->setup_type;
            $setupLabel = $this->formatSetupLabel($setupType);

            $changeType = 'unchanged';
            $indicator = '⚪';
            if ($isNewlyScored) {
                $changeType = 'new';
                $indicator = '🆕';
            } elseif ($delta > 0) {
                $changeType = 'improved';
                $indicator = '🟢';
            } elseif ($delta < 0) {
                $changeType = 'dropped';
                $indicator = '🔴';
            }

            $item = [
                'alert_id' => (int) $alert->id,
                'symbol' => $symbol,
                'company_name' => $alert->company_name ?: $symbol,
                'exchange' => $alert->exchange,
                'setup_type' => $setupType,
                'setup_label' => $setupLabel,
                'setup_score' => $currentScore,
                'previous_setup_score' => $prevScore,
                'score_delta' => $delta,
                'is_newly_scored' => $isNewlyScored,
                'change_type' => $changeType,
                'indicator' => $indicator,
                'spike_date' => $spikeDate,
                'reason_summary' => $alert->reason_summary,
                'dashboard_url' => url('/stock/'.strtolower($symbol)),
                'is_watched' => false,
            ];

            if (! isset($bySymbol[$symbol])) {
                $bySymbol[$symbol] = $item;

                continue;
            }

            $existing = $bySymbol[$symbol];

            // Keep the representative alert with the highest current setup_score
            // (tie-broken by latest spike_date, then alert_id DESC).
            // If any alert for the symbol in this run had a non-null previous_setup_score,
            // preserve that baseline so the symbol is not misclassified as newly scored.
            $mergedPrevScore = $existing['previous_setup_score'] ?? $item['previous_setup_score'];

            $shouldReplace = $item['setup_score'] > $existing['setup_score']
                || ($item['setup_score'] === $existing['setup_score'] && strcmp($item['spike_date'], $existing['spike_date']) > 0)
                || ($item['setup_score'] === $existing['setup_score'] && $item['spike_date'] === $existing['spike_date'] && $item['alert_id'] > $existing['alert_id']);

            $winner = $shouldReplace ? $item : $existing;
            if ($mergedPrevScore !== null && $winner['previous_setup_score'] === null) {
                $winner['previous_setup_score'] = $mergedPrevScore;
                $winner['score_delta'] = $winner['setup_score'] - $mergedPrevScore;
                $winner['is_newly_scored'] = false;
                if ($winner['score_delta'] > 0) {
                    $winner['change_type'] = 'improved';
                    $winner['indicator'] = '🟢';
                } elseif ($winner['score_delta'] < 0) {
                    $winner['change_type'] = 'dropped';
                    $winner['indicator'] = '🔴';
                } else {
                    $winner['change_type'] = 'unchanged';
                    $winner['indicator'] = '⚪';
                }
            }

            $bySymbol[$symbol] = $winner;
        }

        return array_values($bySymbol);
    }

    private function formatSetupLabel(string $setupType): string
    {
        $configured = config("market_data.buy_setup_scanner.setup_types.{$setupType}.label");
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return match ($setupType) {
            'heartbeat_consolidation_spike' => 'Heartbeat consolidation + spike',
            'range_compression_breakout' => 'Range compression breakout',
            'floor_reversal_accumulation' => 'Floor reversal / accumulation',
            'early_breakout_followthrough' => 'Early breakout follow-through',
            default => ucwords(str_replace('_', ' ', $setupType)),
        };
    }

    /**
     * Query setup alerts associated with a scanner run, falling back gracefully
     * to the run's execution window and recorded top matches if the scan_run_id
     * column has not yet been migrated on stock_buy_setup_alerts.
     *
     * @param  list<string>|null  $symbols
     * @return Collection<int, StockBuySetupAlert>
     */
    private function queryAlertsForRun(int $scanRunId, ?StockBuySetupRun $run = null, ?array $symbols = null): Collection
    {
        if (StockBuySetupAlert::hasRunTrackingColumn('scan_run_id')) {
            try {
                $query = StockBuySetupAlert::query()->where('scan_run_id', $scanRunId);
                if ($symbols !== null) {
                    $query->whereIn('symbol', $symbols);
                }

                return $query->get();
            } catch (QueryException $e) {
                StockBuySetupAlert::clearSchemaCache();
                if (! str_contains($e->getMessage(), 'scan_run_id')) {
                    if (! Schema::hasTable('stock_buy_setup_alerts')) {
                        return collect();
                    }

                    throw $e;
                }
            }
        } elseif (! Schema::hasTable('stock_buy_setup_alerts')) {
            return collect();
        }

        $run ??= Schema::hasTable('stock_buy_setup_runs')
            ? StockBuySetupRun::find($scanRunId)
            : null;

        if (! $run) {
            return collect();
        }

        $runMatchedSymbols = array_values(array_unique(array_filter(array_map(
            fn ($m) => is_array($m) ? strtoupper(trim((string) ($m['symbol'] ?? ''))) : '',
            (array) ($run->top_matches ?? []),
        ))));

        if (! $run->started_at && empty($runMatchedSymbols)) {
            return collect();
        }

        $query = StockBuySetupAlert::query();
        if ($symbols !== null) {
            $query->whereIn('symbol', $symbols);
        }

        if ($run->started_at) {
            $windowStart = $run->started_at->copy()->subSeconds(5);
            $windowEnd = ($run->completed_at ?? now())->copy()->addSeconds(5);

            if (! empty($runMatchedSymbols)) {
                $query->where(function ($q) use ($windowStart, $windowEnd, $runMatchedSymbols) {
                    $q->whereBetween('updated_at', [$windowStart, $windowEnd])
                        ->orWhereIn('symbol', $runMatchedSymbols);
                });
            } else {
                $query->whereBetween('updated_at', [$windowStart, $windowEnd]);
            }
        } else {
            $query->whereIn('symbol', $runMatchedSymbols);
        }

        return $query->get();
    }
}
