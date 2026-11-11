<?php

namespace App\Services\Watchlists;

use App\Enums\MoatLevel;
use App\Models\EarningsEvent;
use App\Models\StockBuySetupAlert;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\Dashboard\DashboardQueryService;
use App\Services\Stocks\StockProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DefaultWatchlistService
{
    /**
     * Request-scoped memoized recommendations to avoid re-querying across multiple users.
     *
     * @var array<int, array{stock_id: int, currency: string, moat_level: string, notes: string}>|null
     */
    protected ?array $memoizedRecommendations = null;

    public function __construct(
        protected DashboardQueryService $dashboardQueryService,
        protected StockProvisioner $stockProvisioner,
    ) {}

    /**
     * Ensure a user has a default watchlist called 'setup' (or marks an existing setup watchlist as default),
     * and populates it with recommendations only if it is eligible (newly created by the system, or empty and unprovisioned).
     * Never populates an existing nonempty user-managed default watchlist, and never re-adds stocks deleted by users.
     */
    public function ensureDefaultWatchlist(User $user, ?array $recommendations = null): ?Watchlist
    {
        if ($user->isAnonymousUser()) {
            return null;
        }

        if (! Schema::hasTable('watchlists') || ! Schema::hasTable('watchlist_items')) {
            return null;
        }

        return DB::transaction(function () use ($user, $recommendations) {
            $defaultWatchlist = Watchlist::query()
                ->where('user_id', $user->id)
                ->where('is_global', false)
                ->where('is_default', true)
                ->first();

            $isNewlyCreated = false;

            if (! $defaultWatchlist) {
                $setupWatchlist = Watchlist::query()
                    ->where('user_id', $user->id)
                    ->where('is_global', false)
                    ->where(function ($q) {
                        $q->where('name', 'setup')->orWhere('name', 'Setup');
                    })
                    ->first();

                if ($setupWatchlist) {
                    Watchlist::withoutEvents(function () use ($setupWatchlist) {
                        $setupWatchlist->update(['is_default' => true]);
                    });
                    $defaultWatchlist = $setupWatchlist;
                } else {
                    $defaultWatchlist = Watchlist::withoutEvents(function () use ($user) {
                        return Watchlist::create([
                            'user_id' => $user->id,
                            'name' => 'setup',
                            'description' => 'System-generated default watchlist',
                            'is_default' => true,
                        ]);
                    });
                    $isNewlyCreated = true;
                }
            }

            // Guarantee exactly one default watchlist for this user
            Watchlist::withoutEvents(function () use ($user, $defaultWatchlist) {
                Watchlist::query()
                    ->where('user_id', $user->id)
                    ->where('is_global', false)
                    ->where('id', '!=', $defaultWatchlist->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            });

            if ($this->shouldPopulateWatchlist($defaultWatchlist, $isNewlyCreated)) {
                $this->populateDefaultWatchlist($defaultWatchlist, $recommendations);
            }

            return $defaultWatchlist;
        });
    }

    /**
     * Determine whether a default watchlist is eligible for automatic population.
     *
     * Business rules:
     * 1. Never populate an existing nonempty user-managed default watchlist.
     * 2. Permit retries only for eligible system-generated watchlists that have incomplete automatic provisioning.
     * 3. Once provisioned (auto_provisioned_at set), never re-add stocks deliberately removed by users.
     */
    public function shouldPopulateWatchlist(Watchlist $watchlist, bool $isNewlyCreated = false): bool
    {
        if ($this->hasAutoProvisionedColumn() && $watchlist->auto_provisioned_at !== null) {
            return false;
        }

        if ($isNewlyCreated) {
            return true;
        }

        $hasItems = WatchlistItem::query()
            ->where('watchlist_id', $watchlist->id)
            ->exists();

        // Nonempty user-managed watchlists must never be populated
        if ($hasItems) {
            return false;
        }

        // Empty default watchlist that has not been auto-provisioned is eligible
        return true;
    }

    /**
     * Resolve and provision the top recommended setup and EPS surprise stocks once.
     *
     * @return array<int, array{stock_id: int, currency: string, moat_level: string, notes: string}>
     */
    public function resolveRecommendations(bool $fresh = false): array
    {
        if (! $fresh && $this->memoizedRecommendations !== null) {
            return $this->memoizedRecommendations;
        }

        $recommendations = [];
        $seenSymbols = [];

        // 1. Top 10 setup scoring stocks
        if (Schema::hasTable('stock_buy_setup_alerts') && Schema::hasTable('stocks')) {
            $topSetups = $this->dashboardQueryService->topScoringSetups(10);
            foreach ($topSetups as $item) {
                $symbol = $item['symbol'] ?? null;
                if (! $symbol || isset($seenSymbols[$symbol])) {
                    continue;
                }

                /** @var StockBuySetupAlert|null $alert */
                $alert = $item['alert'] ?? null;
                $exchange = $alert?->exchange;
                $companyName = $item['company_name'] ?? $alert?->company_name;

                $stock = $this->stockProvisioner->findOrCreate($symbol, $exchange, $companyName);

                $score = $item['setup_score'] ?? ($alert?->setup_score ?? '—');
                $type = $item['setup_type'] ?? ($alert?->setup_type ?? 'setup');
                $summary = $alert?->reason_summary;
                $notes = 'Buy setup ['.$type.'] ('.$score.'/100)'.($summary ? ': '.$summary : '');

                $recommendations[] = [
                    'stock_id' => $stock->id,
                    'currency' => $stock->currency?->value ?? 'USD',
                    'moat_level' => MoatLevel::Medium->value,
                    'notes' => $notes,
                ];
                $seenSymbols[$symbol] = true;
            }
        }

        // 2. Top 10 EPS surprises
        if (Schema::hasTable('earnings_events') && Schema::hasTable('stocks')) {
            $topEps = $this->dashboardQueryService->highestEpsSurprises(10);
            foreach ($topEps as $event) {
                /** @var EarningsEvent $event */
                $symbol = $event->symbol;
                if (! $symbol || isset($seenSymbols[$symbol])) {
                    continue;
                }

                $stock = $this->stockProvisioner->findOrCreate($symbol, $event->exchange, $event->company_name);

                $pct = (float) ($event->eps_surprise_percent ?? 0);
                $sign = $pct >= 0 ? '+' : '';
                $kind = $pct >= 0 ? 'beat' : 'miss';
                $note = sprintf(
                    'Added from EPS surprise scanner: %s%s%% EPS %s on %s.',
                    $sign,
                    number_format($pct, 2),
                    $kind,
                    optional($event->report_date)->toDateString() ?? '—',
                );

                $recommendations[] = [
                    'stock_id' => $stock->id,
                    'currency' => $stock->currency?->value ?? 'USD',
                    'moat_level' => MoatLevel::Medium->value,
                    'notes' => $note,
                ];
                $seenSymbols[$symbol] = true;
            }
        }

        return $this->memoizedRecommendations = $recommendations;
    }

    /**
     * Populate the default watchlist with recommended stocks and mark provisioning as complete.
     */
    public function populateDefaultWatchlist(Watchlist $watchlist, ?array $recommendations = null): void
    {
        $recommendations ??= $this->resolveRecommendations();

        if (empty($recommendations)) {
            return;
        }

        $existingStockIds = WatchlistItem::query()
            ->where('watchlist_id', $watchlist->id)
            ->pluck('stock_id')
            ->all();

        $existingMap = array_flip($existingStockIds);

        foreach ($recommendations as $rec) {
            $stockId = $rec['stock_id'];
            if (isset($existingMap[$stockId])) {
                continue;
            }

            WatchlistItem::create([
                'watchlist_id' => $watchlist->id,
                'stock_id' => $stockId,
                'currency' => $rec['currency'],
                'moat_level' => $rec['moat_level'],
                'notes' => $rec['notes'],
            ]);

            $existingMap[$stockId] = true;
        }

        if ($this->hasAutoProvisionedColumn()) {
            Watchlist::withoutEvents(function () use ($watchlist) {
                $watchlist->update(['auto_provisioned_at' => now()]);
            });
        }
    }

    /**
     * Backfill default watchlists and recommendations for all existing non-anonymous users.
     *
     * @return array{total: int, created: int, populated: int, skipped: int, failed: int}
     */
    public function backfillExistingUsers(?int $specificUserId = null, bool $fresh = false): array
    {
        $stats = [
            'total' => 0,
            'created' => 0,
            'populated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        if (! Schema::hasTable('users') || ! Schema::hasTable('watchlists') || ! Schema::hasTable('watchlist_items')) {
            return $stats;
        }

        $query = User::query()
            ->where(function ($query) {
                if (User::hasSchemaColumn('is_anonymous')) {
                    $query->where('is_anonymous', false);
                }
                $query->where('email', '!=', User::ANONYMOUS_EMAIL)
                    ->where('name', '!=', User::ANONYMOUS_NAME);
            });

        if ($specificUserId !== null) {
            $query->where('id', $specificUserId);
        }

        try {
            $recommendations = $this->resolveRecommendations($fresh);
        } catch (\Throwable $e) {
            Log::warning("Backfill recommendations resolution failed: {$e->getMessage()}", [
                'exception' => $e,
            ]);

            $totalUsers = (clone $query)->count();
            $stats['total'] = $totalUsers;
            $stats['failed'] = $totalUsers;

            return $stats;
        }

        $query->chunkById(100, function ($users) use (&$stats, $recommendations) {
            foreach ($users as $user) {
                $stats['total']++;
                try {
                    $hadDefault = Watchlist::query()
                        ->where('user_id', $user->id)
                        ->where('is_global', false)
                        ->where('is_default', true)
                        ->exists();

                    $existingItemsCount = 0;
                    if ($hadDefault) {
                        $existingDefault = Watchlist::query()
                            ->where('user_id', $user->id)
                            ->where('is_global', false)
                            ->where('is_default', true)
                            ->first();
                        $existingItemsCount = WatchlistItem::query()
                            ->where('watchlist_id', $existingDefault?->id)
                            ->count();
                    }

                    $defaultWatchlist = $this->ensureDefaultWatchlist($user, $recommendations);

                    if (! $defaultWatchlist) {
                        $stats['skipped']++;
                    } elseif (! $hadDefault) {
                        $stats['created']++;
                    } elseif ($existingItemsCount === 0 && WatchlistItem::query()->where('watchlist_id', $defaultWatchlist->id)->exists()) {
                        $stats['populated']++;
                    } else {
                        $stats['skipped']++;
                    }
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    Log::warning("Backfill failed for user {$user->id}: {$e->getMessage()}", [
                        'user_id' => $user->id,
                        'exception' => $e,
                    ]);
                }
            }
        });

        return $stats;
    }

    /**
     * Determine whether the auto_provisioned_at column exists on the watchlists table.
     */
    protected function hasAutoProvisionedColumn(): bool
    {
        return Schema::hasTable('watchlists') && Schema::hasColumn('watchlists', 'auto_provisioned_at');
    }
}
