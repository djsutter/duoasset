<?php

namespace App\Services\Dashboard;

use App\Jobs\PrefetchUserDashboardJob;
use App\Models\EarningsEvent;
use App\Models\MarketDataPayload;
use App\Models\SectorFlowSnapshot;
use App\Models\StockBuySetupAlert;
use App\Models\User;
use App\Models\Watchlist;
use App\Services\MarketData\MarketDataProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Context-isolated, versioned, and durably recoverable dashboard cache service.
 *
 * Supports:
 * - Versioned snapshots per context (`dashboard:{context}:active` -> `dashboard:{context}:v{N}`)
 * - Atomic active pointer switching after pre-publication validation
 * - Monotonic generation fencing so stale concurrent warmers cannot overwrite newer snapshots
 * - Durable last-known-good snapshot recovery in `MarketDataPayload` surviving `php artisan cache:clear`
 * - Backward-compatible card envelopes (`dash_top_scoring_setups`, `dash_highest_eps_surprises`, etc.)
 */
class DashboardCacheService
{
    public const SCHEMA_VERSION = 2;

    public const DURABLE_SYMBOL = 'DASHBOARD';

    public const KEY_TOP_SCORING_SETUPS = 'dash_top_scoring_setups';

    public const KEY_HIGHEST_EPS_SURPRISES = 'dash_highest_eps_surprises';

    public const SLOT_GAINERS = 'gainers';

    public const SLOT_LOSERS = 'losers';

    public const ACTIVE_TTL = 900;

    public const SCANNER_TTL = 604800;

    public function __construct(
        private DashboardQueryService $queries,
        private TradingSessionService $sessions,
    ) {}

    public static function activePointerKey(string $context): string
    {
        $ctx = strtolower(trim($context));

        return "dashboard:{$ctx}:active";
    }

    public static function previousPointerKey(string $context): string
    {
        $ctx = strtolower(trim($context));

        return "dashboard:{$ctx}:previous";
    }

    public static function versionSnapshotKey(string $context, int|string $version): string
    {
        $ctx = strtolower(trim($context));
        $v = ltrim((string) $version, 'vV');

        return "dashboard:{$ctx}:v{$v}";
    }

    public static function buildingSnapshotKey(string $context, int|string $version): string
    {
        $ctx = strtolower(trim($context));
        $v = ltrim((string) $version, 'vV');

        return "dashboard:{$ctx}:building:v{$v}";
    }

    public static function metaKey(string $context): string
    {
        $ctx = strtolower(trim($context));

        return "dashboard:{$ctx}:meta";
    }

    public static function generationKey(string $context): string
    {
        $ctx = strtolower(trim($context));

        return "dashboard:{$ctx}:generation";
    }

    public static function publishedGenerationKey(string $context): string
    {
        $ctx = strtolower(trim($context));

        return "dashboard:{$ctx}:published_generation";
    }

    public static function warmerLockKey(string $context): string
    {
        $ctx = strtolower(trim($context));

        return "dashboard:{$ctx}:warmer_lock";
    }

    public static function knownVersionsKey(string $context): string
    {
        $ctx = strtolower(trim($context));

        return "dashboard:{$ctx}:versions";
    }

    public static function moneyFlowsKey(string $slot): string
    {
        return "dash_money_flows_{$slot}";
    }

    public static function userGainersKey(int $userId): string
    {
        return "dash_user_{$userId}_gainers";
    }

    public static function prefetchLockKey(int $userId): string
    {
        return "dash_prefetch_lock_{$userId}";
    }

    public function acquirePrefetchLock(int $userId, int $seconds = 60): bool
    {
        $key = self::prefetchLockKey($userId);
        try {
            $lock = Cache::lock($key, $seconds);

            return (bool) $lock->get();
        } catch (Throwable) {
            return (bool) Cache::add($key, true, $seconds);
        }
    }

    public function releasePrefetchLock(int $userId): void
    {
        $key = self::prefetchLockKey($userId);
        try {
            $lock = Cache::lock($key, 60);
            $lock->forceRelease();
        } catch (Throwable) {
            Cache::forget($key);
        }
    }

    public function acquireContextWarmerLock(string $context, int $seconds = 120): bool
    {
        $key = self::warmerLockKey($context);
        try {
            $lock = Cache::lock($key, $seconds);

            return (bool) $lock->get();
        } catch (Throwable) {
            return (bool) Cache::add($key, true, $seconds);
        }
    }

    public function releaseContextWarmerLock(string $context): void
    {
        $key = self::warmerLockKey($context);
        try {
            $lock = Cache::lock($key, 120);
            $lock->forceRelease();
        } catch (Throwable) {
            Cache::forget($key);
        }
    }

    public function isUserDashboardStale(User $user): bool
    {
        $meta = $this->getSnapshotMetadata('stocks');
        if (! empty($meta['used_durable_fallback'])) {
            return true;
        }

        $gainersKey = self::userGainersKey($user->id);
        $gainersEnvelope = $this->getEnvelope($gainersKey);
        if ($gainersEnvelope !== null && $this->isStale($gainersEnvelope)) {
            return true;
        }

        $flowGainersEnvelope = $this->getEnvelope(self::moneyFlowsKey(self::SLOT_GAINERS));
        if ($flowGainersEnvelope !== null && $this->isStale($flowGainersEnvelope)) {
            return true;
        }

        $flowLosersEnvelope = $this->getEnvelope(self::moneyFlowsKey(self::SLOT_LOSERS));
        if ($flowLosersEnvelope !== null && $this->isStale($flowLosersEnvelope)) {
            return true;
        }

        return false;
    }

    public function triggerPrefetchIfStale(User $user): bool
    {
        if ($this->isUserDashboardStale($user)) {
            if ($this->acquirePrefetchLock($user->id, 60)) {
                PrefetchUserDashboardJob::dispatch($user->id);

                return true;
            }
        }

        return false;
    }

    /**
     * Begin a new warming cycle for the given context without invalidating the active snapshot.
     *
     * @return array{version: int, generation: int}
     */
    public function beginWarming(string $context): array
    {
        $ctx = strtolower(trim($context));
        $generation = $this->nextGenerationNumber($ctx);
        $version = $this->nextVersionNumber($ctx);

        $buildingPayload = [
            'context' => $ctx,
            'snapshot_version' => $version,
            'generation' => $generation,
            'status' => 'BUILDING',
            'started_at' => now()->toIso8601String(),
        ];

        Cache::forever(self::buildingSnapshotKey($ctx, $version), $buildingPayload);

        $this->updateSnapshotMetadata($ctx, [
            'is_warming' => true,
            'building_version' => $version,
            'active_generation' => $generation,
            'last_refresh_attempt_at' => now()->toIso8601String(),
        ]);

        return [
            'version' => $version,
            'generation' => $generation,
        ];
    }

    /**
     * Build, validate, and atomically publish a new versioned dashboard snapshot for a context.
     * Preserves the existing active snapshot during warming and if warming fails.
     *
     * @param  (callable(): array<string, mixed>)|null  $builder
     * @return array<string, mixed>|null
     */
    public function warmContextSnapshot(
        string $context,
        ?callable $builder = null,
        ?int $generation = null,
        ?int $candidateVersion = null,
    ): ?array {
        $ctx = strtolower(trim($context));

        if ($generation === null || $candidateVersion === null) {
            $started = $this->beginWarming($ctx);
            $generation ??= $started['generation'];
            $candidateVersion ??= $started['version'];
        } else {
            $this->registerGenerationNumber($ctx, $generation);
            $this->registerVersionNumber($ctx, $candidateVersion);
            Cache::forever(self::buildingSnapshotKey($ctx, $candidateVersion), [
                'context' => $ctx,
                'snapshot_version' => $candidateVersion,
                'generation' => $generation,
                'status' => 'BUILDING',
                'started_at' => now()->toIso8601String(),
            ]);
            $this->updateSnapshotMetadata($ctx, [
                'is_warming' => true,
                'building_version' => $candidateVersion,
                'active_generation' => $generation,
                'last_refresh_attempt_at' => now()->toIso8601String(),
            ]);
        }

        try {
            $datasets = $builder !== null
                ? $builder()
                : $this->buildDefaultContextDatasets($ctx);

            if (! is_array($datasets)) {
                throw new \RuntimeException("Warmer callback for context [{$ctx}] did not return a valid datasets array.");
            }

            return $this->publishSnapshot($ctx, $datasets, $generation, $candidateVersion);
        } catch (Throwable $e) {
            $this->recordWarmingFailure($ctx, $candidateVersion, $e->getMessage());

            return null;
        }
    }

    /**
     * Validate and atomically promote a candidate snapshot to ACTIVE.
     *
     * @param  array<string, mixed>  $datasets
     * @return array<string, mixed>|null
     */
    public function publishSnapshot(
        string $context,
        array $datasets,
        ?int $generation = null,
        ?int $candidateVersion = null,
        ?string $dataAsOf = null,
    ): ?array {
        $ctx = strtolower(trim($context));
        $candidateVersion ??= $this->nextVersionNumber($ctx);
        $generation ??= $this->nextGenerationNumber($ctx);

        // Generation / fencing check: reject if a newer generation has already started or published
        $latestGen = (int) Cache::get(self::generationKey($ctx), 0);
        $publishedGen = (int) Cache::get(self::publishedGenerationKey($ctx), 0);

        if ($generation < $publishedGen || $generation < $latestGen) {
            Cache::forget(self::buildingSnapshotKey($ctx, $candidateVersion));
            Log::info('dashboard.warmer_fenced', [
                'context' => $ctx,
                'candidate_version' => $candidateVersion,
                'candidate_generation' => $generation,
                'latest_generation' => $latestGen,
                'published_generation' => $publishedGen,
            ]);

            return null;
        }

        $nowIso = now()->toIso8601String();
        $normalizedDatasets = $this->serializeDatasetsForStorage($ctx, $datasets);

        $snapshot = [
            'schema_version' => self::SCHEMA_VERSION,
            'context' => $ctx,
            'snapshot_version' => $candidateVersion,
            'status' => 'ACTIVE',
            'generation' => $generation,
            'generated_at' => $nowIso,
            'published_at' => $nowIso,
            'data_as_of' => $dataAsOf ?? $nowIso,
            'stale_at' => now()->timestamp + self::ACTIVE_TTL,
            'datasets' => $normalizedDatasets,
        ];

        if (! $this->validateSnapshot($ctx, $snapshot)) {
            $this->recordWarmingFailure($ctx, $candidateVersion, 'Snapshot failed pre-publication schema and dataset validation.');

            return null;
        }

        $previousActive = Cache::get(self::activePointerKey($ctx));

        // 1. Write new versioned snapshot before switching pointer
        Cache::forever(self::versionSnapshotKey($ctx, $candidateVersion), $snapshot);
        Cache::forget(self::buildingSnapshotKey($ctx, $candidateVersion));

        // 2. Atomically switch active pointer and record previous version
        if ($previousActive !== null && (int) $previousActive !== $candidateVersion) {
            $prevSnap = Cache::get(self::versionSnapshotKey($ctx, $previousActive));
            if (is_array($prevSnap)) {
                $prevSnap['status'] = 'PREVIOUS';
                Cache::forever(self::versionSnapshotKey($ctx, $previousActive), $prevSnap);
            }
            Cache::forever(self::previousPointerKey($ctx), (int) $previousActive);
        }

        Cache::forever(self::activePointerKey($ctx), $candidateVersion);
        Cache::forever(self::publishedGenerationKey($ctx), $generation);

        $this->trackVersionNumber($ctx, $candidateVersion);

        // 3. Update metadata
        $this->updateSnapshotMetadata($ctx, [
            'context' => $ctx,
            'snapshot_version' => $candidateVersion,
            'previous_version' => $previousActive !== null ? (int) $previousActive : null,
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $nowIso,
            'published_at' => $nowIso,
            'data_as_of' => $snapshot['data_as_of'],
            'last_refresh_attempt_at' => $nowIso,
            'last_refresh_status' => 'succeeded',
            'last_error' => null,
            'is_warming' => false,
            'building_version' => null,
            'used_durable_fallback' => false,
            'published_generation' => $generation,
        ]);

        // 4. Mirror to individual card envelopes for stocks context
        if ($ctx === 'stocks') {
            $this->syncStocksCardEnvelopesFromDatasets($datasets);
        }

        // 5. Persist durable last-known-good snapshot in MarketDataPayload
        $this->persistDurableSnapshot($ctx, $snapshot);

        // 6. Clean up obsolete snapshots older than PREVIOUS
        $this->cleanupObsoleteVersions($ctx, keepPrevious: 1);

        return $snapshot;
    }

    /**
     * Validate a candidate or recovered dashboard snapshot before publication or serving.
     */
    public function validateSnapshot(string $context, mixed $snapshot): bool
    {
        if (! is_array($snapshot)) {
            return false;
        }

        $ctx = strtolower(trim($context));
        if (($snapshot['context'] ?? null) !== $ctx) {
            return false;
        }

        if ((int) ($snapshot['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            return false;
        }

        if (! isset($snapshot['snapshot_version']) || ! is_numeric($snapshot['snapshot_version']) || (int) $snapshot['snapshot_version'] <= 0) {
            return false;
        }

        if (empty($snapshot['generated_at']) || ! is_string($snapshot['generated_at'])) {
            return false;
        }

        if (! empty($snapshot['has_critical_error'])) {
            return false;
        }

        $datasets = $snapshot['datasets'] ?? null;
        if (! is_array($datasets) || empty($datasets)) {
            return false;
        }

        if ($ctx === 'stocks') {
            $requiredKeys = [
                'top_scoring_setups',
                'highest_eps_surprises',
                'money_flow_gainers',
                'money_flow_losers',
            ];

            foreach ($requiredKeys as $reqKey) {
                if (! array_key_exists($reqKey, $datasets)) {
                    return false;
                }

                $section = $datasets[$reqKey];
                if ($section instanceof Collection) {
                    $section = $section->all();
                }

                if (! is_array($section)) {
                    return false;
                }

                if ($reqKey === 'top_scoring_setups') {
                    foreach ($section as $item) {
                        $arr = is_array($item) ? $item : (is_object($item) && method_exists($item, 'toArray') ? $item->toArray() : null);
                        if (! is_array($arr) || empty($arr['symbol']) || ! isset($arr['setup_score']) || ! is_numeric($arr['setup_score'])) {
                            return false;
                        }
                    }
                } elseif ($reqKey === 'highest_eps_surprises') {
                    foreach ($section as $item) {
                        $arr = is_array($item) ? $item : (is_object($item) && method_exists($item, 'toArray') ? $item->toArray() : null);
                        if (! is_array($arr) || empty($arr['symbol'])) {
                            return false;
                        }
                    }
                } elseif (in_array($reqKey, ['money_flow_gainers', 'money_flow_losers'], true)) {
                    foreach ($section as $item) {
                        $arr = is_array($item) ? $item : (is_object($item) && method_exists($item, 'toArray') ? $item->toArray() : null);
                        if (! is_array($arr) || (empty($arr['sector']) && empty($arr['label']))) {
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * Retrieve the currently active validated snapshot for a context, falling back
     * to the durable last-known-good snapshot if the cache was cleared.
     *
     * @return array<string, mixed>|null
     */
    public function getActiveSnapshot(string $context, bool $allowDurableRecovery = true): ?array
    {
        $ctx = strtolower(trim($context));
        $activeVersion = Cache::get(self::activePointerKey($ctx));

        if ($activeVersion !== null) {
            $snapshot = Cache::get(self::versionSnapshotKey($ctx, $activeVersion));
            if ($this->validateSnapshot($ctx, $snapshot)) {
                $snapshot['recovery_source'] = 'cache';
                $snapshot['used_durable_fallback'] = (bool) ($snapshot['used_durable_fallback'] ?? false);

                return $snapshot;
            }

            // Active snapshot in cache is corrupted or from an incompatible schema version
            Log::warning('dashboard.snapshot_incompatible_in_cache', [
                'context' => $ctx,
                'active_version' => $activeVersion,
                'found_schema_version' => is_array($snapshot) ? ($snapshot['schema_version'] ?? null) : null,
                'expected_schema_version' => self::SCHEMA_VERSION,
            ]);
            Cache::forget(self::activePointerKey($ctx));
            Cache::forget(self::versionSnapshotKey($ctx, $activeVersion));
        }

        if (! $allowDurableRecovery) {
            return null;
        }

        return $this->recoverDurableSnapshot($ctx);
    }

    /**
     * Recover a durable last-known-good snapshot from persistent storage after a cache clear.
     *
     * @return array<string, mixed>|null
     */
    public function recoverDurableSnapshot(string $context): ?array
    {
        $ctx = strtolower(trim($context));

        try {
            $record = MarketDataPayload::query()
                ->where('symbol', self::DURABLE_SYMBOL)
                ->where('data_type', "dashboard_snapshot_{$ctx}")
                ->where('period', 'latest')
                ->first();

            if (! $record || ! is_array($record->payload)) {
                return null;
            }

            $snapshot = $record->payload;
            if (! $this->validateSnapshot($ctx, $snapshot)) {
                Log::warning('dashboard.durable_snapshot_incompatible', [
                    'context' => $ctx,
                    'found_schema_version' => $snapshot['schema_version'] ?? null,
                    'expected_schema_version' => self::SCHEMA_VERSION,
                ]);

                return null;
            }

            $version = (int) $snapshot['snapshot_version'];
            $generation = (int) ($snapshot['generation'] ?? 1);

            // Mark stale so background re-warming is triggered while serving immediately
            $snapshot['stale_at'] = now()->timestamp - 1;
            $snapshot['used_durable_fallback'] = true;
            $snapshot['recovery_source'] = 'durable';

            Cache::forever(self::versionSnapshotKey($ctx, $version), $snapshot);
            Cache::forever(self::activePointerKey($ctx), $version);
            $this->registerGenerationNumber($ctx, $generation);
            Cache::forever(self::publishedGenerationKey($ctx), $generation);
            $this->trackVersionNumber($ctx, $version);

            $this->updateSnapshotMetadata($ctx, [
                'context' => $ctx,
                'snapshot_version' => $version,
                'schema_version' => self::SCHEMA_VERSION,
                'generated_at' => $snapshot['generated_at'],
                'published_at' => $snapshot['published_at'] ?? $snapshot['generated_at'],
                'data_as_of' => $snapshot['data_as_of'] ?? $snapshot['generated_at'],
                'used_durable_fallback' => true,
                'recovered_at' => now()->toIso8601String(),
                'is_warming' => false,
            ]);

            if ($ctx === 'stocks') {
                $hydrated = $this->hydrateDatasetsFromSnapshot($ctx, $snapshot['datasets']);
                $this->syncStocksCardEnvelopesFromDatasets($hydrated, markStale: true);
            }

            return $snapshot;
        } catch (Throwable $e) {
            Log::warning('dashboard.durable_recovery_error', [
                'context' => $ctx,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Return observability metadata for the given dashboard context.
     *
     * @return array<string, mixed>
     */
    public function getSnapshotMetadata(string $context): array
    {
        $ctx = strtolower(trim($context));
        $meta = Cache::get(self::metaKey($ctx));
        $activeVersion = Cache::get(self::activePointerKey($ctx));

        $defaults = [
            'context' => $ctx,
            'snapshot_version' => $activeVersion !== null ? (int) $activeVersion : null,
            'previous_version' => Cache::get(self::previousPointerKey($ctx)),
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => null,
            'published_at' => null,
            'data_as_of' => null,
            'last_refresh_attempt_at' => null,
            'last_refresh_status' => 'idle',
            'last_error' => null,
            'is_warming' => false,
            'building_version' => null,
            'used_durable_fallback' => false,
            'has_valid_snapshot' => false,
        ];

        $merged = is_array($meta) ? array_merge($defaults, $meta) : $defaults;
        if ($activeVersion !== null) {
            $merged['snapshot_version'] = (int) $activeVersion;
            $snap = Cache::get(self::versionSnapshotKey($ctx, $activeVersion));
            $merged['has_valid_snapshot'] = $this->validateSnapshot($ctx, $snap);
        }

        return $merged;
    }

    /**
     * Clean up obsolete versioned snapshots while retaining ACTIVE and $keepPrevious versions.
     *
     * @return list<int>
     */
    public function cleanupObsoleteVersions(string $context, int $keepPrevious = 1): array
    {
        $ctx = strtolower(trim($context));
        $activeVersion = (int) Cache::get(self::activePointerKey($ctx), 0);
        $previousVersion = (int) Cache::get(self::previousPointerKey($ctx), 0);

        $known = Cache::get(self::knownVersionsKey($ctx), []);
        if (! is_array($known)) {
            $known = [];
        }

        $known = array_values(array_unique(array_map('intval', $known)));
        rsort($known);

        $retained = [];
        if ($activeVersion > 0) {
            $retained[] = $activeVersion;
        }
        if ($keepPrevious > 0 && $previousVersion > 0 && $previousVersion !== $activeVersion) {
            $retained[] = $previousVersion;
        }

        foreach ($known as $v) {
            if (count($retained) >= (1 + max(0, $keepPrevious))) {
                break;
            }
            if ($v > 0 && ! in_array($v, $retained, true)) {
                $retained[] = $v;
            }
        }

        $removed = [];
        foreach ($known as $v) {
            if (! in_array($v, $retained, true)) {
                Cache::forget(self::versionSnapshotKey($ctx, $v));
                Cache::forget(self::buildingSnapshotKey($ctx, $v));
                $removed[] = $v;
            }
        }

        Cache::forever(self::knownVersionsKey($ctx), $retained);

        return $removed;
    }

    /**
     * Intentionally purge both in-memory versioned caches and durable snapshots.
     *
     * @return array{contexts: list<string>, durable_deleted: int}
     */
    public function hardPurge(?string $context = null): array
    {
        $contexts = $context !== null
            ? [strtolower(trim($context))]
            : ['stocks', 'crypto', 'futures'];

        foreach ($contexts as $ctx) {
            $known = Cache::get(self::knownVersionsKey($ctx), []);
            if (is_array($known)) {
                foreach ($known as $v) {
                    Cache::forget(self::versionSnapshotKey($ctx, $v));
                    Cache::forget(self::buildingSnapshotKey($ctx, $v));
                }
            }

            $active = Cache::get(self::activePointerKey($ctx));
            if ($active !== null) {
                Cache::forget(self::versionSnapshotKey($ctx, $active));
            }
            $prev = Cache::get(self::previousPointerKey($ctx));
            if ($prev !== null) {
                Cache::forget(self::versionSnapshotKey($ctx, $prev));
            }

            Cache::forget(self::activePointerKey($ctx));
            Cache::forget(self::previousPointerKey($ctx));
            Cache::forget(self::metaKey($ctx));
            Cache::forget(self::generationKey($ctx));
            Cache::forget(self::publishedGenerationKey($ctx));
            Cache::forget(self::warmerLockKey($ctx));
            Cache::forget(self::knownVersionsKey($ctx));

            if ($ctx === 'stocks') {
                Cache::forget(self::KEY_TOP_SCORING_SETUPS);
                Cache::forget(self::KEY_HIGHEST_EPS_SURPRISES);
                Cache::forget(self::moneyFlowsKey(self::SLOT_GAINERS));
                Cache::forget(self::moneyFlowsKey(self::SLOT_LOSERS));
            }
        }

        $durableDeleted = 0;
        try {
            $query = MarketDataPayload::query();
            if ($context !== null) {
                $ctx = strtolower(trim($context));
                $query->where('symbol', self::DURABLE_SYMBOL)
                    ->where('data_type', "dashboard_snapshot_{$ctx}");
            } else {
                $query->where(function ($q) {
                    $q->where('symbol', self::DURABLE_SYMBOL)
                        ->orWhere('symbol', 'like', 'DASHBOARD_USER_%');
                });
            }
            $durableDeleted = $query->delete();
        } catch (Throwable) {
        }

        return [
            'contexts' => $contexts,
            'durable_deleted' => $durableDeleted,
        ];
    }

    /**
     * Stale-while-revalidate cache envelope wrapper.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return array{data: T, stale_at: int}
     */
    public function rememberStale(string $key, int $ttlSeconds, \Closure $callback): array
    {
        $envelope = $this->getEnvelope($key);
        if ($envelope !== null) {
            return $envelope;
        }

        // Attempt durable snapshot recovery before running synchronous rebuilds
        $recovered = $this->tryRecoverEnvelopeFromDurable($key);
        if ($recovered !== null) {
            return $recovered;
        }

        $data = $callback();

        return $this->putEnvelope($key, $data, $ttlSeconds);
    }

    /**
     * Store payload into a cache envelope with indefinite retention.
     *
     * @template T
     *
     * @param  T  $data
     * @return array{data: T, stale_at: int}
     */
    public function putEnvelope(string $key, mixed $data, int $ttlSeconds): array
    {
        $envelope = [
            'data' => $data,
            'stale_at' => now()->timestamp + $ttlSeconds,
        ];

        Cache::forever($key, $envelope);

        if (str_starts_with($key, 'dash_user_') && str_ends_with($key, '_gainers')) {
            $this->persistDurableUserGainers($key, $envelope);
        }

        return $envelope;
    }

    /**
     * Retrieve raw cache envelope if present.
     *
     * @return array{data: mixed, stale_at: int}|null
     */
    public function getEnvelope(string $key): ?array
    {
        $cached = Cache::get($key);
        if (is_array($cached) && array_key_exists('data', $cached) && array_key_exists('stale_at', $cached)) {
            return $cached;
        }

        return null;
    }

    /**
     * Determine if a cache envelope or key is stale.
     */
    public function isStale(array|string $envelopeOrKey): bool
    {
        if (is_string($envelopeOrKey)) {
            $envelope = $this->getEnvelope($envelopeOrKey);
            if ($envelope === null || ! isset($envelope['stale_at'])) {
                return true;
            }

            return now()->timestamp >= (int) $envelope['stale_at'];
        }

        if (! isset($envelopeOrKey['stale_at'])) {
            return true;
        }

        return now()->timestamp >= (int) $envelopeOrKey['stale_at'];
    }

    public function topScoringSetups(): Collection
    {
        $envelope = $this->rememberStale(
            self::KEY_TOP_SCORING_SETUPS,
            self::SCANNER_TTL,
            fn () => $this->queries->topScoringSetups()
        );

        $this->maybePublishInitialStocksSnapshot();

        return $this->unwrapCollection($envelope['data']);
    }

    public function highestEpsSurprises(): Collection
    {
        $envelope = $this->rememberStale(
            self::KEY_HIGHEST_EPS_SURPRISES,
            self::SCANNER_TTL,
            fn () => $this->queries->highestEpsSurprises()
        );

        $this->maybePublishInitialStocksSnapshot();

        return $this->unwrapCollection($envelope['data']);
    }

    public function moneyFlowGainers(?User $user): Collection
    {
        $envelope = $this->rememberStale(
            self::moneyFlowsKey(self::SLOT_GAINERS),
            $this->ttlFor($user),
            fn () => $this->queries->moneyFlowGainers()
        );

        $this->maybePublishInitialStocksSnapshot();

        return $this->unwrapCollection($envelope['data']);
    }

    public function moneyFlowLosers(?User $user): Collection
    {
        $envelope = $this->rememberStale(
            self::moneyFlowsKey(self::SLOT_LOSERS),
            $this->ttlFor($user),
            fn () => $this->queries->moneyFlowLosers()
        );

        $this->maybePublishInitialStocksSnapshot();

        return $this->unwrapCollection($envelope['data']);
    }

    public function userGainers(User $user, ?Watchlist $watchlist, MarketDataProvider $provider): Collection
    {
        if ($watchlist === null || $watchlist->items->isEmpty()) {
            return collect();
        }

        $envelope = $this->rememberStale(
            self::userGainersKey($user->id),
            $this->ttlFor($user),
            fn () => $this->queries->highestGainingStocks($watchlist, $provider)
        );

        return $this->unwrapCollection($envelope['data']);
    }

    public function invalidateTopScoringSetups(): void
    {
        Cache::forget(self::KEY_TOP_SCORING_SETUPS);
        $this->invalidateDurableDatasetSlot('stocks', 'top_scoring_setups');
    }

    public function invalidateEpsSurprises(): void
    {
        Cache::forget(self::KEY_HIGHEST_EPS_SURPRISES);
        $this->invalidateDurableDatasetSlot('stocks', 'highest_eps_surprises');
    }

    public function invalidateMoneyFlows(): void
    {
        Cache::forget(self::moneyFlowsKey(self::SLOT_GAINERS));
        Cache::forget(self::moneyFlowsKey(self::SLOT_LOSERS));
        $this->invalidateDurableDatasetSlot('stocks', 'money_flow_gainers');
        $this->invalidateDurableDatasetSlot('stocks', 'money_flow_losers');
    }

    public function invalidateUserGainers(int $userId): void
    {
        $key = self::userGainersKey($userId);
        Cache::forget($key);

        try {
            MarketDataPayload::query()
                ->where('symbol', "DASHBOARD_USER_{$userId}")
                ->where('data_type', 'dashboard_user_gainers')
                ->delete();
        } catch (Throwable) {
        }
    }

    /**
     * Warm every dashboard card for the user (used by the login pre-warm job).
     */
    public function warmForUser(User $user, ?Watchlist $watchlist, MarketDataProvider $provider): void
    {
        $this->warmContextSnapshot('stocks');
        $this->userGainers($user, $watchlist, $provider);
    }

    /**
     * Force refresh and publish a new validated snapshot version alongside user gainers.
     */
    public function refreshUserDashboard(User $user, ?Watchlist $watchlist, MarketDataProvider $provider): void
    {
        $ttl = $this->ttlFor($user);

        $this->warmContextSnapshot('stocks');

        if ($watchlist !== null && $watchlist->items->isNotEmpty()) {
            $this->putEnvelope(
                self::userGainersKey($user->id),
                $this->queries->highestGainingStocks($watchlist, $provider),
                $ttl
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildDefaultContextDatasets(string $context): array
    {
        $ctx = strtolower(trim($context));
        if ($ctx === 'stocks') {
            return [
                'top_scoring_setups' => $this->queries->topScoringSetups(),
                'highest_eps_surprises' => $this->queries->highestEpsSurprises(),
                'money_flow_gainers' => $this->queries->moneyFlowGainers(),
                'money_flow_losers' => $this->queries->moneyFlowLosers(),
            ];
        }

        if ($ctx === 'crypto') {
            return [
                'summary' => [
                    'status' => 'ready',
                    'generated_at' => now()->toIso8601String(),
                ],
            ];
        }

        return [
            'status' => 'dynamic',
        ];
    }

    protected function maybePublishInitialStocksSnapshot(): void
    {
        if (Cache::has(self::activePointerKey('stocks'))) {
            return;
        }

        $topEnv = $this->getEnvelope(self::KEY_TOP_SCORING_SETUPS);
        $epsEnv = $this->getEnvelope(self::KEY_HIGHEST_EPS_SURPRISES);
        $flowGainEnv = $this->getEnvelope(self::moneyFlowsKey(self::SLOT_GAINERS));
        $flowLoseEnv = $this->getEnvelope(self::moneyFlowsKey(self::SLOT_LOSERS));

        if ($topEnv !== null && $epsEnv !== null && $flowGainEnv !== null && $flowLoseEnv !== null) {
            $this->publishSnapshot('stocks', [
                'top_scoring_setups' => $topEnv['data'],
                'highest_eps_surprises' => $epsEnv['data'],
                'money_flow_gainers' => $flowGainEnv['data'],
                'money_flow_losers' => $flowLoseEnv['data'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $datasets
     */
    protected function syncStocksCardEnvelopesFromDatasets(array $datasets, bool $markStale = false): void
    {
        $hydrated = $this->hydrateDatasetsFromSnapshot('stocks', $datasets);
        $scannerTtl = $markStale ? -1 : self::SCANNER_TTL;
        $activeTtl = $markStale ? -1 : self::ACTIVE_TTL;

        if (isset($hydrated['top_scoring_setups'])) {
            $this->putEnvelope(self::KEY_TOP_SCORING_SETUPS, $hydrated['top_scoring_setups'], $scannerTtl);
        }
        if (isset($hydrated['highest_eps_surprises'])) {
            $this->putEnvelope(self::KEY_HIGHEST_EPS_SURPRISES, $hydrated['highest_eps_surprises'], $scannerTtl);
        }
        if (isset($hydrated['money_flow_gainers'])) {
            $this->putEnvelope(self::moneyFlowsKey(self::SLOT_GAINERS), $hydrated['money_flow_gainers'], $activeTtl);
        }
        if (isset($hydrated['money_flow_losers'])) {
            $this->putEnvelope(self::moneyFlowsKey(self::SLOT_LOSERS), $hydrated['money_flow_losers'], $activeTtl);
        }
    }

    /**
     * @param  array<string, mixed>  $datasets
     * @return array<string, mixed>
     */
    protected function serializeDatasetsForStorage(string $context, array $datasets): array
    {
        $out = [];
        foreach ($datasets as $key => $value) {
            if ($value instanceof Collection) {
                $value = $value->all();
            }

            if (is_array($value)) {
                $rows = [];
                foreach ($value as $idx => $item) {
                    if ($item instanceof \Illuminate\Database\Eloquent\Model) {
                        $rows[$idx] = $item->attributesToArray();
                    } elseif (is_array($item)) {
                        $rowCopy = $item;
                        if (isset($rowCopy['alert']) && $rowCopy['alert'] instanceof \Illuminate\Database\Eloquent\Model) {
                            $rowCopy['alert'] = $rowCopy['alert']->attributesToArray();
                        }
                        $rows[$idx] = $rowCopy;
                    } else {
                        $rows[$idx] = $item;
                    }
                }
                $out[$key] = $rows;
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $datasets
     * @return array<string, mixed>
     */
    public function hydrateDatasetsFromSnapshot(string $context, array $datasets): array
    {
        if (strtolower(trim($context)) !== 'stocks') {
            return $datasets;
        }

        $hydrated = $datasets;

        if (isset($datasets['top_scoring_setups'])) {
            $rawTop = $datasets['top_scoring_setups'] instanceof Collection
                ? $datasets['top_scoring_setups']->all()
                : (array) $datasets['top_scoring_setups'];

            $hydrated['top_scoring_setups'] = collect($rawTop)->map(function ($item) {
                if (! is_array($item)) {
                    return $item;
                }
                if (isset($item['alert']) && is_array($item['alert'])) {
                    $model = new StockBuySetupAlert;
                    $model->forceFill($item['alert']);
                    $model->exists = isset($item['alert']['id']);
                    $item['alert'] = $model;
                }

                return $item;
            })->values();
        }

        if (isset($datasets['highest_eps_surprises'])) {
            $rawEps = $datasets['highest_eps_surprises'] instanceof Collection
                ? $datasets['highest_eps_surprises']->all()
                : (array) $datasets['highest_eps_surprises'];

            $hydrated['highest_eps_surprises'] = collect($rawEps)->map(function ($item) {
                if ($item instanceof EarningsEvent) {
                    return $item;
                }
                if (is_array($item)) {
                    $model = new EarningsEvent;
                    $model->forceFill($item);
                    $model->exists = isset($item['id']);

                    return $model;
                }

                return $item;
            })->values();
        }

        foreach (['money_flow_gainers', 'money_flow_losers'] as $flowKey) {
            if (isset($datasets[$flowKey])) {
                $rawFlows = $datasets[$flowKey] instanceof Collection
                    ? $datasets[$flowKey]->all()
                    : (array) $datasets[$flowKey];

                $hydrated[$flowKey] = collect($rawFlows)->map(function ($item) {
                    if ($item instanceof SectorFlowSnapshot) {
                        return $item;
                    }
                    if (is_array($item)) {
                        $model = new SectorFlowSnapshot;
                        $model->forceFill($item);
                        $model->exists = isset($item['id']);

                        return $model;
                    }

                    return $item;
                })->values();
            }
        }

        return $hydrated;
    }

    protected function persistDurableSnapshot(string $context, array $snapshot): void
    {
        try {
            $ctx = strtolower(trim($context));
            MarketDataPayload::updateOrCreate(
                [
                    'symbol' => self::DURABLE_SYMBOL,
                    'data_type' => "dashboard_snapshot_{$ctx}",
                    'period' => 'latest',
                ],
                [
                    'payload' => $snapshot,
                    'retrieved_at' => now(),
                ]
            );
        } catch (Throwable) {
        }
    }

    protected function invalidateDurableDatasetSlot(string $context, string $slotKey): void
    {
        $ctx = strtolower(trim($context));

        $activeVersion = Cache::get(self::activePointerKey($ctx));
        if ($activeVersion !== null) {
            $snap = Cache::get(self::versionSnapshotKey($ctx, $activeVersion));
            if (is_array($snap)) {
                $snap['invalidated_slots'][$slotKey] = true;
                Cache::forever(self::versionSnapshotKey($ctx, $activeVersion), $snap);
            }
        }

        try {
            $record = MarketDataPayload::query()
                ->where('symbol', self::DURABLE_SYMBOL)
                ->where('data_type', "dashboard_snapshot_{$ctx}")
                ->where('period', 'latest')
                ->first();

            if ($record && is_array($record->payload)) {
                $payload = $record->payload;
                $payload['invalidated_slots'][$slotKey] = true;
                $record->payload = $payload;
                $record->save();
            }
        } catch (Throwable) {
        }
    }

    /**
     * @param  array{data: mixed, stale_at: int}  $envelope
     */
    protected function persistDurableUserGainers(string $cacheKey, array $envelope): void
    {
        if (! preg_match('/^dash_user_(\d+)_gainers$/', $cacheKey, $m)) {
            return;
        }

        $userId = (int) $m[1];
        try {
            $rows = $envelope['data'] instanceof Collection ? $envelope['data']->all() : (array) $envelope['data'];
            $serialized = array_map(function ($row) {
                if (is_array($row) && isset($row['added_at']) && $row['added_at'] instanceof \Carbon\CarbonInterface) {
                    $row['added_at'] = $row['added_at']->toIso8601String();
                }

                return $row;
            }, $rows);

            MarketDataPayload::updateOrCreate(
                [
                    'symbol' => "DASHBOARD_USER_{$userId}",
                    'data_type' => 'dashboard_user_gainers',
                    'period' => 'latest',
                ],
                [
                    'payload' => [
                        'schema_version' => self::SCHEMA_VERSION,
                        'user_id' => $userId,
                        'data' => $serialized,
                        'generated_at' => now()->toIso8601String(),
                    ],
                    'retrieved_at' => now(),
                ]
            );
        } catch (Throwable) {
        }
    }

    /**
     * @return array{data: mixed, stale_at: int}|null
     */
    protected function tryRecoverEnvelopeFromDurable(string $key): ?array
    {
        $slotMap = [
            self::KEY_TOP_SCORING_SETUPS => 'top_scoring_setups',
            self::KEY_HIGHEST_EPS_SURPRISES => 'highest_eps_surprises',
            self::moneyFlowsKey(self::SLOT_GAINERS) => 'money_flow_gainers',
            self::moneyFlowsKey(self::SLOT_LOSERS) => 'money_flow_losers',
        ];

        if (isset($slotMap[$key])) {
            $slot = $slotMap[$key];
            try {
                $record = MarketDataPayload::query()
                    ->where('symbol', self::DURABLE_SYMBOL)
                    ->where('data_type', 'dashboard_snapshot_stocks')
                    ->where('period', 'latest')
                    ->first();

                if (! $record || ! is_array($record->payload)) {
                    return null;
                }

                $snapshot = $record->payload;
                if (! empty($snapshot['invalidated_slots'][$slot])) {
                    return null;
                }

                if (! $this->validateSnapshot('stocks', $snapshot)) {
                    return null;
                }

                $recovered = $this->recoverDurableSnapshot('stocks');
                if ($recovered !== null) {
                    return $this->getEnvelope($key);
                }
            } catch (Throwable) {
                return null;
            }
        }

        if (preg_match('/^dash_user_(\d+)_gainers$/', $key, $m)) {
            $userId = (int) $m[1];
            try {
                $record = MarketDataPayload::query()
                    ->where('symbol', "DASHBOARD_USER_{$userId}")
                    ->where('data_type', 'dashboard_user_gainers')
                    ->where('period', 'latest')
                    ->first();

                if (! $record || ! is_array($record->payload)) {
                    return null;
                }

                $payload = $record->payload;
                if ((int) ($payload['schema_version'] ?? 0) !== self::SCHEMA_VERSION || ! is_array($payload['data'] ?? null)) {
                    return null;
                }

                $rows = collect($payload['data'])->map(function ($row) {
                    if (is_array($row) && isset($row['added_at']) && is_string($row['added_at'])) {
                        $row['added_at'] = \Illuminate\Support\Carbon::parse($row['added_at']);
                    }

                    return $row;
                })->values();

                // Restore as stale so background prefetch warms fresh prices asynchronously
                $envelope = [
                    'data' => $rows,
                    'stale_at' => now()->timestamp - 1,
                ];
                Cache::forever($key, $envelope);

                return $envelope;
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    protected function recordWarmingFailure(string $context, int $candidateVersion, string $reason): void
    {
        $ctx = strtolower(trim($context));
        Cache::forget(self::buildingSnapshotKey($ctx, $candidateVersion));
        Cache::forget(self::versionSnapshotKey($ctx, $candidateVersion));

        $this->updateSnapshotMetadata($ctx, [
            'last_refresh_attempt_at' => now()->toIso8601String(),
            'last_refresh_status' => 'failed',
            'last_error' => $reason,
            'is_warming' => false,
            'building_version' => null,
        ]);

        Log::warning('dashboard.warmer_failed', [
            'context' => $ctx,
            'candidate_version' => $candidateVersion,
            'active_version' => Cache::get(self::activePointerKey($ctx)),
            'reason' => $reason,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function updateSnapshotMetadata(string $context, array $attributes): void
    {
        $ctx = strtolower(trim($context));
        $current = $this->getSnapshotMetadata($ctx);
        $updated = array_merge($current, $attributes);
        Cache::forever(self::metaKey($ctx), $updated);
    }

    protected function nextVersionNumber(string $context): int
    {
        $ctx = strtolower(trim($context));
        $known = Cache::get(self::knownVersionsKey($ctx), []);
        $maxKnown = is_array($known) && ! empty($known) ? max(array_map('intval', $known)) : 0;
        $active = (int) Cache::get(self::activePointerKey($ctx), 0);
        $metaVer = (int) ($this->getSnapshotMetadata($ctx)['snapshot_version'] ?? 0);

        $next = max($maxKnown, $active, $metaVer) + 1;
        $this->trackVersionNumber($ctx, $next);

        return $next;
    }

    protected function registerVersionNumber(string $context, int $version): void
    {
        $this->trackVersionNumber(strtolower(trim($context)), $version);
    }

    protected function trackVersionNumber(string $context, int $version): void
    {
        $ctx = strtolower(trim($context));
        $known = Cache::get(self::knownVersionsKey($ctx), []);
        if (! is_array($known)) {
            $known = [];
        }
        if (! in_array($version, $known, true)) {
            $known[] = $version;
        }
        Cache::forever(self::knownVersionsKey($ctx), array_values(array_unique(array_map('intval', $known))));
    }

    protected function nextGenerationNumber(string $context): int
    {
        $ctx = strtolower(trim($context));
        $current = (int) Cache::get(self::generationKey($ctx), 0);
        $published = (int) Cache::get(self::publishedGenerationKey($ctx), 0);
        $next = max($current, $published) + 1;
        Cache::forever(self::generationKey($ctx), $next);

        return $next;
    }

    protected function registerGenerationNumber(string $context, int $generation): void
    {
        $ctx = strtolower(trim($context));
        $current = (int) Cache::get(self::generationKey($ctx), 0);
        if ($generation > $current) {
            Cache::forever(self::generationKey($ctx), $generation);
        }
    }

    private function unwrapCollection(mixed $data): Collection
    {
        return $data instanceof Collection ? $data : collect($data);
    }

    private function ttlFor(?User $user): int
    {
        $timezone = $user?->timezone() ?? TradingSessionService::DEFAULT_TIMEZONE;

        return $this->sessions->getTradingHoursTtl($timezone, self::ACTIVE_TTL);
    }
}
