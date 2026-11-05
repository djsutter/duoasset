<?php

namespace App\Services\Stocks;

use App\Jobs\EnrichInstrumentNewsJob;
use App\Jobs\RefreshInstrumentNewsJob;
use App\Models\InstrumentNewsArticle;
use App\Services\MarketData\FakeMarketDataProvider;
use App\Services\MarketData\MarketDataProvider;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class InstrumentNewsService
{
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_REFRESHING = 'refreshing';

    public const STATUS_EMPTY = 'empty';

    public const STATUS_SERVICE_UNAVAILABLE = 'service_unavailable';

    public const STATUS_AUTH_ERROR = 'auth_error';

    public const STATUS_RATE_LIMITED = 'rate_limited';

    public const STATUS_DISABLED = 'disabled';

    protected OllamaNewsEnricher $aiEnricher;

    public function __construct(
        protected MarketDataProvider $provider,
        protected InstrumentClassifier $classifier,
        ?OllamaNewsEnricher $aiEnricher = null,
    ) {
        $this->aiEnricher = $aiEnricher ?? app(OllamaNewsEnricher::class);
    }

    /**
     * Retrieve normalized, relevance-filtered, deduplicated news items for an instrument.
     *
     * @param  array{
     *     from?: CarbonInterface|string|int|null,
     *     to?: CarbonInterface|string|int|null,
     *     limit?: int,
     *     around_timestamp?: int|null,
     *     pre_window_minutes?: int,
     *     look_ahead_minutes?: int,
     *     category?: string|null,
     *     refresh?: bool,
     *     async?: bool,
     *     force_searxng?: bool
     * }  $options
     * @return array<string, mixed>
     */
    public function getNews(string $symbol, array $options = []): array
    {
        $t0 = microtime(true);
        $instrument = $this->classifier->resolve($symbol);
        $upperSymbol = $instrument['symbol'];
        $assetType = $instrument['asset_type'];
        $availableCategories = $this->getAvailableCategories($assetType, $upperSymbol);

        $maxLimit = max(10, (int) config('market_data.news.max_limit', 150));
        $defaultLimit = max(1, (int) config('market_data.news.default_limit', 50));
        $limit = max(1, min($maxLimit, (int) ($options['limit'] ?? $defaultLimit)));

        $aroundTimestamp = isset($options['around_timestamp']) && is_numeric($options['around_timestamp'])
            ? (int) $options['around_timestamp']
            : null;

        $preWindowMinutes = max(15, min(2880, (int) ($options['pre_window_minutes'] ?? 720))); // default 12h before candle
        $lookAheadMinutes = max(0, min(1440, (int) ($options['look_ahead_minutes'] ?? 120))); // default 2h look-ahead

        $defaultLookbackDays = max(7, (int) config('market_data.news.default_lookback_days', 365));

        if ($aroundTimestamp !== null && $aroundTimestamp > 0) {
            $centerUtc = Carbon::createFromTimestamp($aroundTimestamp, 'UTC');
            $from = $centerUtc->copy()->subMinutes($preWindowMinutes);
            $to = $centerUtc->copy()->addMinutes($lookAheadMinutes);
        } else {
            $to = $this->parseBound($options['to'] ?? null, isEnd: true) ?? Carbon::now('UTC');
            $from = $this->parseBound($options['from'] ?? null, isEnd: false) ?? $to->copy()->subDays($defaultLookbackDays);
        }

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy(), $from->copy()];
        }

        $investigation = null;
        if ($aroundTimestamp !== null && $aroundTimestamp > 0) {
            $investigation = [
                'candle_timestamp' => $aroundTimestamp,
                'candle_iso' => Carbon::createFromTimestamp($aroundTimestamp, 'UTC')->toIso8601String(),
                'pre_window_minutes' => $preWindowMinutes,
                'look_ahead_minutes' => $lookAheadMinutes,
                'window_from_timestamp' => $from->timestamp,
                'window_to_timestamp' => $to->timestamp,
            ];
        }

        if (! config('market_data.news.enabled', true)) {
            return [
                'symbol' => $upperSymbol,
                'asset_type' => $assetType,
                'enabled' => false,
                'status' => self::STATUS_DISABLED,
                'status_message' => 'Interactive news timeline is disabled by configuration.',
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'from_timestamp' => $from->timestamp,
                'to_timestamp' => $to->timestamp,
                'count' => 0,
                'markers' => [],
                'items' => [],
                'available_categories' => $availableCategories,
                'investigation' => $investigation,
                'diagnostics' => [
                    'cache_hit' => false,
                    'raw_count' => 0,
                    'retained_count' => 0,
                    'filtered_count' => 0,
                    'duration_ms' => 0,
                    'failure_category' => null,
                    'sources' => [],
                ],
            ];
        }

        $forceRefresh = ! empty($options['refresh']);
        $preferAsync = ! empty($options['async']);

        $windowKey = md5(json_encode([
            $from->format('Y-m-d H:i'),
            $to->format('Y-m-d H:i'),
            $aroundTimestamp,
            $preWindowMinutes,
            $lookAheadMinutes,
        ]));
        $cacheKey = "duoasset.news.v2.{$upperSymbol}.{$windowKey}";

        if (! $forceRefresh) {
            $cachedBundle = Cache::get($cacheKey);
            if (is_array($cachedBundle) && isset($cachedBundle['items']) && is_array($cachedBundle['items'])) {
                return $this->buildResponseFromBundle(
                    bundle: $cachedBundle,
                    instrument: $instrument,
                    from: $from,
                    to: $to,
                    limit: $limit,
                    category: $options['category'] ?? null,
                    investigation: $investigation,
                    availableCategories: $availableCategories,
                    cacheHit: true,
                    durationMs: (int) round((microtime(true) - $t0) * 1000)
                );
            }
        }

        // If async mode is requested on a cold cache and queue is non-sync, return persisted DB items + refreshing status
        $queueDriver = (string) config('queue.default', 'sync');
        if ($preferAsync && $queueDriver !== 'sync' && ! ($this->provider instanceof FakeMarketDataProvider)) {
            $persisted = $this->loadPersistedArticles($upperSymbol, $from, $to, $aroundTimestamp);
            RefreshInstrumentNewsJob::dispatch($upperSymbol, [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'limit' => $limit,
                'around_timestamp' => $aroundTimestamp,
                'pre_window_minutes' => $preWindowMinutes,
                'look_ahead_minutes' => $lookAheadMinutes,
            ]);

            $bundle = [
                'items' => $persisted,
                'status' => self::STATUS_REFRESHING,
                'status_message' => 'Refreshing latest news timeline in the background...',
                'diagnostics' => [
                    'cache_hit' => false,
                    'raw_count' => count($persisted),
                    'retained_count' => count($persisted),
                    'filtered_count' => 0,
                    'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
                    'failure_category' => null,
                    'sources' => ['persisted_db' => ['status' => 'ok', 'count' => count($persisted)]],
                ],
            ];

            return $this->buildResponseFromBundle(
                bundle: $bundle,
                instrument: $instrument,
                from: $from,
                to: $to,
                limit: $limit,
                category: $options['category'] ?? null,
                investigation: $investigation,
                availableCategories: $availableCategories,
                cacheHit: false,
                durationMs: (int) round((microtime(true) - $t0) * 1000)
            );
        }

        // Prevent duplicate concurrent external aggregation work for the same symbol & window
        $lockTimeout = max(5, (int) config('market_data.news.lock_timeout_seconds', 15));
        $lockWait = max(1, (int) config('market_data.news.lock_wait_seconds', 3));
        $lock = Cache::lock("lock.{$cacheKey}", $lockTimeout);
        $acquired = false;

        try {
            $acquired = $lock->get();
            if (! $acquired) {
                try {
                    $lock->block($lockWait);
                    $acquired = true;
                } catch (\Throwable) {
                    $acquired = false;
                }

                // Re-check shared cache after waiting on lock
                $cachedAfterWait = Cache::get($cacheKey);
                if (is_array($cachedAfterWait) && isset($cachedAfterWait['items'])) {
                    return $this->buildResponseFromBundle(
                        bundle: $cachedAfterWait,
                        instrument: $instrument,
                        from: $from,
                        to: $to,
                        limit: $limit,
                        category: $options['category'] ?? null,
                        investigation: $investigation,
                        availableCategories: $availableCategories,
                        cacheHit: true,
                        durationMs: (int) round((microtime(true) - $t0) * 1000)
                    );
                }

                if (! $acquired) {
                    $persisted = $this->loadPersistedArticles($upperSymbol, $from, $to, $aroundTimestamp);
                    $bundle = [
                        'items' => $persisted,
                        'status' => self::STATUS_REFRESHING,
                        'status_message' => 'News aggregation is currently in progress for this instrument.',
                        'diagnostics' => [
                            'cache_hit' => false,
                            'raw_count' => count($persisted),
                            'retained_count' => count($persisted),
                            'filtered_count' => 0,
                            'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
                            'failure_category' => null,
                            'sources' => [],
                        ],
                    ];

                    return $this->buildResponseFromBundle(
                        bundle: $bundle,
                        instrument: $instrument,
                        from: $from,
                        to: $to,
                        limit: $limit,
                        category: $options['category'] ?? null,
                        investigation: $investigation,
                        availableCategories: $availableCategories,
                        cacheHit: false,
                        durationMs: (int) round((microtime(true) - $t0) * 1000)
                    );
                }
            }

            [$rawItems, $sourceStats] = $this->collectRawItems(
                instrument: $instrument,
                from: $from,
                to: $to,
                limit: $limit,
                explicitDateFilter: isset($options['from']) || isset($options['to']) || $aroundTimestamp !== null,
                forceSearxng: ! empty($options['force_searxng'])
            );

            $normalized = $this->normalizeFilterAndDeduplicate($rawItems, $instrument, $from, $to, $aroundTimestamp);

            // If primary sources yielded fewer than threshold articles, optionally run SearXNG discovery fallback
            $searxngMinArticles = (int) config('market_data.news.sources.searxng_fallback_min_articles', 5);
            if (
                empty($options['force_searxng'])
                && count($normalized) < $searxngMinArticles
                && $aroundTimestamp === null
                && $this->shouldAttemptSearxngFallback()
            ) {
                [$searxRows, $searxDiag] = $this->fetchSearxngNews($instrument, $from, $to);
                $sourceStats['searxng'] = $searxDiag;
                if (! empty($searxRows)) {
                    $rawItems = array_merge($rawItems, $searxRows);
                    $normalized = $this->normalizeFilterAndDeduplicate($rawItems, $instrument, $from, $to, $aroundTimestamp);
                }
            }

            // Persist newly normalized articles and merge with any existing historical persisted articles in window
            $this->persistArticles($upperSymbol, $normalized);
            $persistedInWindow = $this->loadPersistedArticles($upperSymbol, $from, $to, $aroundTimestamp);
            if (! empty($persistedInWindow)) {
                $normalized = $this->mergeWithPersisted($normalized, $persistedInWindow);
            }

            // Handle optional AI enrichment (attach cached enrichments, and dispatch async job or run sync if configured)
            [$normalized, $unEnriched] = $this->aiEnricher->attachCachedEnrichments($upperSymbol, $normalized);
            if ($this->aiEnricher->isEnabled() && ! empty($unEnriched)) {
                if ($this->aiEnricher->isAsync() && $queueDriver !== 'sync') {
                    EnrichInstrumentNewsJob::dispatch($upperSymbol, $unEnriched);
                } else {
                    $this->aiEnricher->enrichBatch($instrument, $unEnriched);
                    [$normalized] = $this->aiEnricher->attachCachedEnrichments($upperSymbol, $normalized);
                }
            }

            [$status, $statusMessage, $failureCategory] = $this->resolveRetrievalStatus($normalized, $sourceStats);
            $durationMs = (int) round((microtime(true) - $t0) * 1000);
            $rawCount = count($rawItems);
            $retainedCount = count($normalized);
            $filteredOrDedupCount = max(0, $rawCount - $retainedCount);

            $diagnostics = [
                'cache_hit' => false,
                'raw_count' => $rawCount,
                'retained_count' => $retainedCount,
                'filtered_count' => $filteredOrDedupCount,
                'duration_ms' => $durationMs,
                'failure_category' => $failureCategory,
                'sources' => $sourceStats,
            ];

            $this->logRetrievalDiagnostics($upperSymbol, $assetType, $status, $diagnostics);

            $bundle = [
                'items' => $normalized,
                'status' => $status,
                'status_message' => $statusMessage,
                'diagnostics' => $diagnostics,
            ];

            // Only cache successful or legitimately empty bundles; never cache transient provider failures for long
            if (in_array($status, [self::STATUS_AVAILABLE, self::STATUS_EMPTY], true)) {
                $isHistorical = $to->lessThan(Carbon::now('UTC')->subHours(24));
                $ttlSeconds = $isHistorical
                    ? max(60, (int) config('market_data.news.cache_ttl_historical_seconds', 43200))
                    : max(30, (int) config('market_data.news.cache_ttl_recent_seconds', 600));
                Cache::put($cacheKey, $bundle, now()->addSeconds($ttlSeconds));
            }

            return $this->buildResponseFromBundle(
                bundle: $bundle,
                instrument: $instrument,
                from: $from,
                to: $to,
                limit: $limit,
                category: $options['category'] ?? null,
                investigation: $investigation,
                availableCategories: $availableCategories,
                cacheHit: false,
                durationMs: $durationMs
            );
        } finally {
            if ($acquired) {
                try {
                    $lock->release();
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * Clear cached news for a symbol.
     */
    public function clearNewsCache(string $symbol): void
    {
        // Note: Individual window keys use short TTLs or refresh=true parameter.
    }

    /**
     * Format the final response from a cached or freshly aggregated bundle.
     *
     * @param  array<string, mixed>  $bundle
     * @param  array<string, mixed>  $instrument
     * @param  array<string, mixed>|null  $investigation
     * @param  array<int, array{key: string, label: string}>  $availableCategories
     * @return array<string, mixed>
     */
    protected function buildResponseFromBundle(
        array $bundle,
        array $instrument,
        CarbonInterface $from,
        CarbonInterface $to,
        int $limit,
        ?string $category,
        ?array $investigation,
        array $availableCategories,
        bool $cacheHit,
        int $durationMs
    ): array {
        $upperSymbol = $instrument['symbol'];
        $assetType = $instrument['asset_type'];
        $items = is_array($bundle['items'] ?? null) ? $bundle['items'] : [];

        // Refresh any newly completed AI enrichments from cache
        [$items] = $this->aiEnricher->attachCachedEnrichments($upperSymbol, $items);

        if ($category !== null && is_string($category) && trim($category) !== '' && strtolower(trim($category)) !== 'all') {
            $catFilter = strtolower(trim($category));
            $items = array_values(array_filter($items, function (array $item) use ($catFilter) {
                foreach ($item['category_keys'] ?? [] as $k) {
                    if (strtolower((string) $k) === $catFilter) {
                        return true;
                    }
                }

                return strtolower((string) ($item['primary_category_key'] ?? '')) === $catFilter;
            }));
        }

        $slicedItems = array_slice($items, 0, $limit);
        $markers = $this->buildChartMarkers($slicedItems);

        $diagnostics = is_array($bundle['diagnostics'] ?? null) ? $bundle['diagnostics'] : [];
        $diagnostics['cache_hit'] = $cacheHit;
        $diagnostics['duration_ms'] = $durationMs;

        $status = (string) ($bundle['status'] ?? (count($slicedItems) > 0 ? self::STATUS_AVAILABLE : self::STATUS_EMPTY));
        if ($status === self::STATUS_EMPTY && count($slicedItems) > 0) {
            $status = self::STATUS_AVAILABLE;
        }

        return [
            'symbol' => $upperSymbol,
            'asset_type' => $assetType,
            'enabled' => true,
            'status' => $status,
            'status_message' => $bundle['status_message'] ?? null,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'from_timestamp' => $from->timestamp,
            'to_timestamp' => $to->timestamp,
            'count' => count($slicedItems),
            'markers' => $markers,
            'items' => $slicedItems,
            'available_categories' => $availableCategories,
            'investigation' => $investigation,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * Collect raw news rows from configured FMP endpoints, official RSS, and optional SearXNG.
     *
     * @param  array<string, mixed>  $instrument
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    protected function collectRawItems(
        array $instrument,
        CarbonInterface $from,
        CarbonInterface $to,
        int $limit,
        bool $explicitDateFilter = false,
        bool $forceSearxng = false
    ): array {
        $symbol = $instrument['symbol'];
        $assetType = $instrument['asset_type'];
        $raw = [];
        $sourceStats = [];

        if (method_exists($this->provider, 'clearErrors')) {
            $this->provider->clearErrors();
        }

        $fetchLimit = max(50, min(150, $limit * 2));
        $fmpFrom = $explicitDateFilter ? $from : null;
        $fmpTo = $explicitDateFilter ? $to : null;

        if ($assetType === InstrumentClassifier::TYPE_FUTURES) {
            $configured = (array) config("market_data.futures.instruments.{$symbol}", []);
            $directProxies = array_map('strtoupper', (array) ($configured['direct_proxies'] ?? ($symbol === 'GCUSD' ? ['XAUUSD', 'GCUSD', 'GLD', 'IAU'] : [$symbol])));
            $forexProxies = (array) ($configured['news_proxies']['forex'] ?? ($symbol === 'GCUSD' ? ['XAUUSD'] : []));
            $stockProxies = (array) ($configured['news_proxies']['stock'] ?? ($symbol === 'GCUSD' ? ['GLD', 'IAU', 'USO', 'TLT', 'UUP'] : []));

            // 1. FMP Forex News
            if (config('market_data.news.sources.fmp_forex_enabled', true)) {
                $tSource = microtime(true);
                $forexCount = 0;
                if (! empty($forexProxies)) {
                    $forexRows = $this->callProviderNewsMethod('forexNews', [$forexProxies, $fmpFrom, $fmpTo, $fetchLimit], $sourceStats, 'fmp_forex');
                    foreach ($forexRows as $row) {
                        if (is_array($row)) {
                            $rowSym = strtoupper(trim((string) ($row['symbol'] ?? '')));
                            $row['_source_channel'] = 'fmp_forex';
                            $row['_direct_proxy'] = in_array($rowSym, $directProxies, true);
                            $row['_macro_proxy'] = ! $row['_direct_proxy'];
                            $raw[] = $row;
                            $forexCount++;
                        }
                    }
                }

                $latestForex = $this->callProviderNewsMethod('forexNews', [[], $fmpFrom, $fmpTo, 40], $sourceStats, 'fmp_forex_latest');
                foreach ($latestForex as $row) {
                    if (is_array($row)) {
                        $rowSym = strtoupper(trim((string) ($row['symbol'] ?? '')));
                        $row['_source_channel'] = 'fmp_forex_latest';
                        $row['_direct_proxy'] = in_array($rowSym, $directProxies, true);
                        $row['_macro_proxy'] = ! $row['_direct_proxy'];
                        $raw[] = $row;
                        $forexCount++;
                    }
                }
                $sourceStats['fmp_forex']['count'] = $forexCount;
                $sourceStats['fmp_forex']['duration_ms'] = (int) round((microtime(true) - $tSource) * 1000);
            }

            // 2. FMP Stock/ETF Proxy News (e.g. GLD, IAU, USO, TLT)
            if (! empty($stockProxies) && config('market_data.news.sources.fmp_stock_enabled', true)) {
                $tSource = microtime(true);
                $proxyRows = $this->callProviderNewsMethod('stockNews', [$stockProxies, $fmpFrom, $fmpTo, $fetchLimit], $sourceStats, 'fmp_proxy_news');
                $proxyCount = 0;
                foreach ($proxyRows as $row) {
                    if (is_array($row)) {
                        $rowSym = strtoupper(trim((string) ($row['symbol'] ?? '')));
                        $row['_source_channel'] = 'fmp_proxy_news';
                        $row['_direct_proxy'] = in_array($rowSym, $directProxies, true);
                        $row['_macro_proxy'] = ! $row['_direct_proxy'];
                        $raw[] = $row;
                        $proxyCount++;
                    }
                }
                $sourceStats['fmp_proxy_news']['count'] = $proxyCount;
                $sourceStats['fmp_proxy_news']['duration_ms'] = (int) round((microtime(true) - $tSource) * 1000);
            }

            // 3. FMP General Financial & Geopolitical News
            if (config('market_data.news.sources.fmp_general_enabled', true)) {
                $tSource = microtime(true);
                $generalRows = $this->callProviderNewsMethod('generalNews', [$fmpFrom, $fmpTo, $fetchLimit], $sourceStats, 'fmp_general');
                $genCount = 0;
                foreach ($generalRows as $row) {
                    if (is_array($row)) {
                        $row['_source_channel'] = 'fmp_general';
                        $raw[] = $row;
                        $genCount++;
                    }
                }
                $sourceStats['fmp_general']['count'] = $genCount;
                $sourceStats['fmp_general']['duration_ms'] = (int) round((microtime(true) - $tSource) * 1000);
            }

            // 4. FMP Economic Calendar (High-impact US / Fed / Inflation / Rate releases)
            if (config('market_data.news.sources.fmp_economic_calendar_enabled', true)) {
                $tSource = microtime(true);
                $econFrom = $from->copy()->greaterThan(Carbon::now('UTC')->subDays(90)) ? $from : Carbon::now('UTC')->subDays(90);
                $econRows = $this->callProviderNewsMethod('economicCalendar', [$econFrom, $to], $sourceStats, 'fmp_economic_calendar');
                $econCount = 0;
                foreach ($econRows as $row) {
                    if (is_array($row)) {
                        $converted = $this->convertEconomicCalendarRow($row);
                        if ($converted !== null) {
                            $raw[] = $converted;
                            $econCount++;
                        }
                    }
                }
                $sourceStats['fmp_economic_calendar']['count'] = $econCount;
                $sourceStats['fmp_economic_calendar']['duration_ms'] = (int) round((microtime(true) - $tSource) * 1000);
            }

            // 5. Federal Reserve Monetary Policy RSS Feed
            if (config('market_data.news.fed_rss_enabled', true) && config('market_data.news.sources.fed_rss_enabled', true)) {
                $tSource = microtime(true);
                $fedRows = $this->fetchFederalReserveRss($from, $to);
                foreach ($fedRows as $fedRow) {
                    $raw[] = $fedRow;
                }
                $sourceStats['fed_rss'] = [
                    'status' => 'ok',
                    'count' => count($fedRows),
                    'duration_ms' => (int) round((microtime(true) - $tSource) * 1000),
                ];
            }
        } else {
            // Stock instrument news
            if (config('market_data.news.sources.fmp_stock_enabled', true)) {
                $tSource = microtime(true);
                $stockRows = $this->callProviderNewsMethod('stockNews', [[$symbol], $fmpFrom, $fmpTo, $fetchLimit], $sourceStats, 'fmp_stock');
                $stockCount = 0;
                foreach ($stockRows as $row) {
                    if (is_array($row)) {
                        $row['_source_channel'] = 'fmp_stock';
                        $row['_direct_symbol'] = true;
                        $raw[] = $row;
                        $stockCount++;
                    }
                }
                $sourceStats['fmp_stock']['count'] = $stockCount;
                $sourceStats['fmp_stock']['duration_ms'] = (int) round((microtime(true) - $tSource) * 1000);
            }

            if (config('market_data.news.sources.fmp_press_releases_enabled', true)) {
                $tSource = microtime(true);
                $pressRows = $this->callProviderNewsMethod('pressReleases', [[$symbol], $fmpFrom, $fmpTo, 25], $sourceStats, 'fmp_press_release');
                $pressCount = 0;
                foreach ($pressRows as $row) {
                    if (is_array($row)) {
                        $row['_source_channel'] = 'fmp_press_release';
                        $row['_direct_symbol'] = true;
                        $raw[] = $row;
                        $pressCount++;
                    }
                }
                $sourceStats['fmp_press_release']['count'] = $pressCount;
                $sourceStats['fmp_press_release']['duration_ms'] = (int) round((microtime(true) - $tSource) * 1000);
            }

            if (config('market_data.news.sources.fmp_general_enabled', true)) {
                $tSource = microtime(true);
                $generalRows = $this->callProviderNewsMethod('generalNews', [$fmpFrom, $fmpTo, 30], $sourceStats, 'fmp_general');
                $genCount = 0;
                foreach ($generalRows as $row) {
                    if (is_array($row)) {
                        $row['_source_channel'] = 'fmp_general';
                        $raw[] = $row;
                        $genCount++;
                    }
                }
                $sourceStats['fmp_general']['count'] = $genCount;
                $sourceStats['fmp_general']['duration_ms'] = (int) round((microtime(true) - $tSource) * 1000);
            }
        }

        // Optional configured official RSS feeds
        foreach ($this->fetchConfiguredRssFeeds($instrument, $from, $to) as $rssRow) {
            $raw[] = $rssRow;
        }

        // Explicit SearXNG discovery when forced
        if ($forceSearxng && $this->shouldAttemptSearxngFallback(force: true)) {
            [$searxRows, $searxDiag] = $this->fetchSearxngNews($instrument, $from, $to);
            $sourceStats['searxng'] = $searxDiag;
            $raw = array_merge($raw, $searxRows);
        }

        return [$raw, $sourceStats];
    }

    /**
     * Invoke a news method on the bound MarketDataProvider (or its upstream if wrapped),
     * recording structured diagnostics if the method is missing or if upstream errors occurred.
     *
     * @param  array<int, mixed>  $args
     * @param  array<string, array<string, mixed>>  $sourceStats
     * @return array<int, array<string, mixed>>
     */
    protected function callProviderNewsMethod(string $method, array $args, array &$sourceStats, string $channelKey): array
    {
        $target = null;
        if (method_exists($this->provider, $method)) {
            $target = $this->provider;
        } elseif (method_exists($this->provider, 'getUpstream')) {
            $upstream = $this->provider->getUpstream();
            if (is_object($upstream) && method_exists($upstream, $method)) {
                $target = $upstream;
            }
        }

        if ($target === null) {
            $providerClass = get_class($this->provider);
            Log::warning('instrument_news.provider_method_missing', [
                'provider' => $providerClass,
                'method' => $method,
                'channel' => $channelKey,
            ]);
            $sourceStats[$channelKey] = [
                'status' => 'missing_method',
                'error_code' => 501,
                'error' => "Provider [{$providerClass}] does not implement [{$method}].",
                'count' => 0,
            ];

            return [];
        }

        $errorsBefore = method_exists($this->provider, 'lastErrors') ? count($this->provider->lastErrors()) : 0;

        try {
            $result = $target->{$method}(...$args);
            $rows = is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            Log::warning('instrument_news.provider_call_exception', [
                'method' => $method,
                'channel' => $channelKey,
                'error' => $e->getMessage(),
            ]);
            $sourceStats[$channelKey] = [
                'status' => 'error',
                'error_code' => (int) ($e->getCode() ?: 503),
                'error' => $e->getMessage(),
                'count' => 0,
            ];

            return [];
        }

        $allErrors = method_exists($this->provider, 'lastErrors') ? $this->provider->lastErrors() : [];
        if (count($allErrors) > $errorsBefore) {
            $latestErr = end($allErrors);
            $statusCode = (int) ($latestErr['status'] ?? 503);
            $sourceStats[$channelKey] = [
                'status' => 'error',
                'error_code' => $statusCode,
                'error' => (string) ($latestErr['body'] ?? 'Upstream provider error'),
                'count' => count($rows),
            ];
        } else {
            $sourceStats[$channelKey] = [
                'status' => 'ok',
                'count' => count($rows),
            ];
        }

        return $rows;
    }

    /**
     * Normalize, score relevance, deduplicate, and sort news items.
     *
     * @param  array<int, array<string, mixed>>  $rawItems
     * @param  array<string, mixed>  $instrument
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeFilterAndDeduplicate(
        array $rawItems,
        array $instrument,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $aroundTimestamp = null
    ): array {
        $symbol = $instrument['symbol'];
        $assetType = $instrument['asset_type'];
        $profileConfig = $this->resolveEffectiveProfileConfig($assetType, $symbol);
        $minScore = (int) ($profileConfig['min_score'] ?? 25);
        $themes = (array) ($profileConfig['themes'] ?? []);

        $fromTs = $from->copy()->startOfMinute()->timestamp;
        $toTs = ($to->format('H:i:s') === '00:00:00')
            ? $to->copy()->endOfDay()->timestamp
            : $to->timestamp;

        $byFingerprint = [];
        $byUrl = [];

        foreach ($rawItems as $raw) {
            $title = trim(strip_tags(html_entity_decode((string) ($raw['title'] ?? $raw['headline'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($title === '') {
                continue;
            }

            $pubRaw = $raw['publishedDate'] ?? $raw['published_at'] ?? $raw['pubdate'] ?? $raw['date'] ?? null;
            if (! $pubRaw) {
                continue;
            }

            $publishedCarbon = $this->parseNewsTimestamp((string) $pubRaw);
            if (! $publishedCarbon) {
                continue;
            }

            $publishedTs = $publishedCarbon->timestamp;
            if ($publishedTs < $fromTs || $publishedTs > $toTs) {
                continue;
            }

            $summary = trim(strip_tags(html_entity_decode((string) ($raw['text'] ?? $raw['summary'] ?? $raw['content'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if (mb_strlen($summary) > 360) {
                $summary = rtrim(mb_substr($summary, 0, 357)).'...';
            }

            $url = trim((string) ($raw['url'] ?? $raw['link'] ?? ''));
            $publisher = trim((string) ($raw['publisher'] ?? $raw['site'] ?? $raw['source'] ?? 'Financial News'));
            $site = trim((string) ($raw['site'] ?? ($url !== '' ? (parse_url($url, PHP_URL_HOST) ?? '') : '')));

            // Distinguish original event timestamp from publication timestamp when available
            $eventCarbon = $this->extractEventTimestamp($raw, $title, $summary, $publishedCarbon);
            $eventTs = $eventCarbon?->timestamp;

            // Evaluate relevance score and matched categories
            [$score, $matchedCategories] = $this->scoreRelevance(
                raw: $raw,
                title: $title,
                summary: $summary,
                instrument: $instrument,
                themes: $themes
            );

            if ($score < $minScore) {
                continue;
            }

            $primaryCategory = $matchedCategories[0] ?? [
                'key' => $assetType === InstrumentClassifier::TYPE_FUTURES ? 'macro_commodity' : 'company_news',
                'label' => $assetType === InstrumentClassifier::TYPE_FUTURES ? 'Commodity & Macro' : 'Company News',
            ];

            $canonicalUrl = $this->canonicalizeUrl($url);
            $fingerprint = $this->headlineFingerprint($title);

            $windowPhase = null;
            $offsetMinutes = null;
            if ($aroundTimestamp !== null && $aroundTimestamp > 0) {
                $diffSec = $publishedTs - $aroundTimestamp;
                $offsetMinutes = (int) round($diffSec / 60);
                $windowPhase = $diffSec <= 0 ? 'before_or_during' : 'look_ahead';
            }

            $itemId = 'news_'.substr(md5($fingerprint.'|'.$publishedCarbon->format('Y-m-d')), 0, 12);
            $rawSymbol = strtoupper(trim((string) ($raw['symbol'] ?? $symbol)));
            $sourceChannel = (string) ($raw['_source_channel'] ?? 'fmp');

            $item = [
                'id' => $itemId,
                'symbol' => $rawSymbol !== '' ? $rawSymbol : $symbol,
                'symbols' => array_values(array_unique(array_filter([$symbol, $rawSymbol]))),
                'title' => $title,
                'summary' => $summary,
                'source' => $publisher !== '' ? $publisher : ($site !== '' ? $site : 'Market News'),
                'site' => $site,
                'source_provider' => $sourceChannel,
                'url' => $url !== '' ? $url : null,
                'canonical_url' => $canonicalUrl !== '' ? $canonicalUrl : null,
                // Marker timestamp defaults strictly to the first verifiable publication timestamp
                'marker_timestamp' => $publishedTs,
                'published_at' => $publishedCarbon->toIso8601String(),
                'published_timestamp' => $publishedTs,
                'published_display' => $publishedCarbon->format('M j, Y H:i').' UTC',
                'event_at' => $eventCarbon?->toIso8601String(),
                'event_timestamp' => $eventTs,
                'event_display' => $eventCarbon ? $eventCarbon->format('M j, Y H:i').' UTC' : null,
                'has_distinct_event_time' => $eventTs !== null && abs($eventTs - $publishedTs) >= 60,
                'relevance_score' => $score,
                'primary_category_key' => $primaryCategory['key'],
                'primary_category_label' => $primaryCategory['label'],
                'categories' => $matchedCategories,
                'category_keys' => array_values(array_map(fn ($c) => $c['key'], $matchedCategories)),
                'window_phase' => $windowPhase,
                'offset_minutes' => $offsetMinutes,
                'ai_enriched' => false,
                'ai_relevant' => null,
                'ai_market_moving' => false,
                'ai_rationale' => null,
            ];

            // Deduplicate by URL or headline fingerprint, preserving earliest verifiable publication timestamp
            $existingKey = null;
            if ($canonicalUrl !== '' && isset($byUrl[$canonicalUrl])) {
                $existingKey = $byUrl[$canonicalUrl];
            } elseif (isset($byFingerprint[$fingerprint])) {
                $existingKey = $fingerprint;
            }

            if ($existingKey !== null && isset($byFingerprint[$existingKey])) {
                $existing = $byFingerprint[$existingKey];
                if ($item['published_timestamp'] < $existing['published_timestamp']) {
                    $existing['published_timestamp'] = $item['published_timestamp'];
                    $existing['published_at'] = $item['published_at'];
                    $existing['published_display'] = $item['published_display'];
                    $existing['marker_timestamp'] = $item['published_timestamp'];
                }
                if ($item['relevance_score'] > $existing['relevance_score']) {
                    $existing['relevance_score'] = $item['relevance_score'];
                }
                if (! $existing['event_timestamp'] && $item['event_timestamp']) {
                    $existing['event_timestamp'] = $item['event_timestamp'];
                    $existing['event_at'] = $item['event_at'];
                    $existing['event_display'] = $item['event_display'];
                    $existing['has_distinct_event_time'] = $item['has_distinct_event_time'];
                }
                if (empty($existing['url']) && ! empty($item['url'])) {
                    $existing['url'] = $item['url'];
                }
                $existing['symbols'] = array_values(array_unique(array_merge($existing['symbols'] ?? [], $item['symbols'] ?? [])));

                // Merge category tags
                $mergedCats = $existing['categories'];
                $seenCatKeys = array_column($mergedCats, 'key');
                foreach ($item['categories'] as $cat) {
                    if (! in_array($cat['key'], $seenCatKeys, true)) {
                        $mergedCats[] = $cat;
                        $seenCatKeys[] = $cat['key'];
                    }
                }
                $existing['categories'] = $mergedCats;
                $existing['category_keys'] = $seenCatKeys;
                $byFingerprint[$existingKey] = $existing;
            } else {
                $byFingerprint[$fingerprint] = $item;
                if ($canonicalUrl !== '') {
                    $byUrl[$canonicalUrl] = $fingerprint;
                }
            }
        }

        $items = array_values($byFingerprint);
        usort($items, fn (array $a, array $b) => $b['published_timestamp'] <=> $a['published_timestamp']);

        return $items;
    }

    /**
     * Resolve effective relevance profile and themes for an instrument, applying
     * per-commodity overrides for silver, crude oil, natural gas, copper, etc.
     *
     * @return array{min_score: int, themes: array<string, array<string, mixed>>}
     */
    protected function resolveEffectiveProfileConfig(string $assetType, string $symbol): array
    {
        $base = (array) config("market_data.news.relevance_profiles.{$assetType}", []);
        $minScore = (int) ($base['min_score'] ?? ($assetType === InstrumentClassifier::TYPE_FUTURES ? 25 : 25));
        $themes = (array) ($base['themes'] ?? []);

        if ($assetType === InstrumentClassifier::TYPE_FUTURES) {
            $configuredInstrument = (array) config("market_data.futures.instruments.{$symbol}", []);
            $commodityKey = (string) ($configuredInstrument['commodity_profile'] ?? '');
            if ($commodityKey !== '' && $commodityKey !== 'gold') {
                $commodityProfile = (array) config("market_data.news.relevance_profiles.futures.commodity_profiles.{$commodityKey}", []);
                $excluded = (array) ($commodityProfile['excluded_themes'] ?? []);
                foreach ($excluded as $exKey) {
                    unset($themes[$exKey]);
                }
                if (! empty($commodityProfile['primary_theme']) && ! empty($commodityProfile['primary_keywords'])) {
                    $pt = $commodityProfile['primary_theme'];
                    $ptKey = (string) ($pt['key'] ?? $commodityKey);
                    $themes = array_merge([
                        $ptKey => [
                            'label' => (string) ($pt['label'] ?? ucfirst($commodityKey)),
                            'weight' => (int) ($pt['weight'] ?? 50),
                            'keywords' => (array) $commodityProfile['primary_keywords'],
                        ],
                    ], $themes);
                }
            }
        }

        return [
            'min_score' => $minScore,
            'themes' => $themes,
        ];
    }

    /**
     * Score relevance (0-100) and return matched category tags, enforcing strict
     * equity identity matching, ticker/name collision rejection, and futures proxy differentiation.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $instrument
     * @param  array<string, array<string, mixed>>  $themes
     * @return array{0: int, 1: array<int, array{key: string, label: string}>}
     */
    protected function scoreRelevance(
        array $raw,
        string $title,
        string $summary,
        array $instrument,
        array $themes
    ): array {
        $symbol = strtoupper($instrument['symbol']);
        $assetType = $instrument['asset_type'];
        $nameRaw = trim((string) ($instrument['name'] ?? ''));
        $nameLower = strtolower($nameRaw);
        $instrumentExchange = strtoupper(trim((string) ($instrument['exchange'] ?? '')));

        $titleLower = strtolower($title);
        $bodyLower = strtolower($summary);
        $combinedLower = $titleLower.' '.$bodyLower;
        $combinedOriginal = $title.' '.$summary;
        $rawSymbol = strtoupper(trim((string) ($raw['symbol'] ?? '')));

        // 1. Check explicit disambiguation exclusions (e.g., "cronos gas", "masivo silver", "tsxv: mass")
        $disambig = (array) config("market_data.news.symbol_disambiguation.{$symbol}", []);
        foreach ((array) ($disambig['exclude_phrases'] ?? []) as $exPhrase) {
            $exLower = strtolower(trim((string) $exPhrase));
            if ($exLower !== '' && str_contains($combinedLower, $exLower)) {
                return [0, []];
            }
        }

        $score = 0;
        $matched = [];

        if ($assetType === InstrumentClassifier::TYPE_STOCK) {
            // Foreign-exchange ticker collision guard:
            // If article explicitly qualifies the same ticker with a contradicting exchange (e.g., "(TSXV: MASS)" when stock is NASDAQ),
            // reject unless the actual company name is also present.
            if ($instrumentExchange !== '' && preg_match('/\b(TSXV|TSX-V|CVE|ASX|AIM|LSE|JSE)\s*:\s*'.preg_quote($symbol, '/').'\b/i', $combinedOriginal, $exMatch)) {
                $citedExchange = strtoupper(str_replace('-', '', $exMatch[1]));
                if (! str_contains($instrumentExchange, $citedExchange)) {
                    return [0, []];
                }
            }

            $hasCompanyMention = false;
            $hasTitleCompanyMention = false;

            // Check configured company aliases
            foreach ((array) ($disambig['company_aliases'] ?? []) as $alias) {
                $aliasLower = strtolower(trim((string) $alias));
                if ($aliasLower !== '') {
                    if (str_contains($titleLower, $aliasLower)) {
                        $hasCompanyMention = true;
                        $hasTitleCompanyMention = true;
                    } elseif (str_contains($bodyLower, $aliasLower)) {
                        $hasCompanyMention = true;
                    }
                }
            }

            // Check cleaned company name (e.g. "908 devices", "nebius group", "cronos group", "apple")
            if ($nameLower !== '' && strlen($nameLower) >= 3) {
                $cleanFull = trim((string) preg_replace('/\b(inc\.?|corp\.?|corporation|n\.?v\.?|ltd\.?|limited|plc|co\.?|holdings?)\b/i', '', $nameLower));
                $cleanFull = trim((string) preg_replace('/[,\.]+$/', '', $cleanFull));
                $cleanCore = trim((string) preg_replace('/\b(group|devices|technologies|therapeutics|pharmaceuticals|biosciences|systems|international)\b/i', '', $cleanFull));

                if ($cleanFull !== '' && strlen($cleanFull) >= 3) {
                    if (str_contains($titleLower, $cleanFull)) {
                        $hasCompanyMention = true;
                        $hasTitleCompanyMention = true;
                    } elseif (str_contains($bodyLower, $cleanFull)) {
                        $hasCompanyMention = true;
                    }
                }

                // Only match single-word core name if it is sufficiently distinctive (>= 5 chars)
                // and not a known collision without ticker/company context
                if (! $hasCompanyMention && $cleanCore !== '' && strlen($cleanCore) >= 5 && $cleanCore !== $cleanFull) {
                    $corePattern = '/\b'.preg_quote($cleanCore, '/').'\b/i';
                    if (preg_match($corePattern, $titleLower)) {
                        $hasCompanyMention = true;
                        $hasTitleCompanyMention = true;
                    } elseif (preg_match($corePattern, $bodyLower)) {
                        $hasCompanyMention = true;
                    }
                }
            }

            // Check explicit ticker mention in title or summary
            $hasExplicitTickerMention = $this->hasExplicitTickerMention($symbol, $combinedOriginal);

            // If we know the company's name (production / realistic profile), require either a company/alias mention
            // or an explicit ticker mention in the headline/summary. This prevents untargeted market roundups or
            // mis-tagged stories that never mention the company or ticker from polluting the stock timeline.
            $hasKnownCompanyMetadata = ($nameLower !== '' && $nameLower !== strtolower($symbol)) || ! empty($disambig['company_aliases']);

            if ($hasKnownCompanyMetadata) {
                if (! $hasCompanyMention && ! $hasExplicitTickerMention) {
                    return [0, []];
                }
            } else {
                // When no company profile metadata is available (e.g. minimal synthetic test), accept direct provider symbol tag or ticker mention
                if (empty($raw['_direct_symbol']) && $rawSymbol !== $symbol && ! $hasExplicitTickerMention) {
                    return [0, []];
                }
            }

            if ($hasTitleCompanyMention || $this->hasExplicitTickerMention($symbol, $title)) {
                $score += 45;
            } elseif ($hasCompanyMention || $hasExplicitTickerMention || ! empty($raw['_direct_symbol']) || $rawSymbol === $symbol) {
                $score += 35;
            }
        } else {
            // Futures relevance scoring
            $configured = (array) config("market_data.futures.instruments.{$symbol}", []);
            $commodityKey = (string) ($configured['commodity_profile'] ?? ($symbol === 'GCUSD' ? 'gold' : ''));
            $directProxies = array_map('strtoupper', (array) ($configured['direct_proxies'] ?? ($symbol === 'GCUSD' ? ['XAUUSD', 'GCUSD', 'MGCUSD', 'GLD', 'IAU'] : [$symbol])));

            $isDirectProxy = ! empty($raw['_direct_proxy']) || in_array($rawSymbol, $directProxies, true);

            // Reject foreign currency pair technical forecasts (e.g., "EUR/USD Price Forecast", "AUD/USD Price Forecast")
            // unless they directly mention the target commodity
            if (! $isDirectProxy && preg_match('/\b(EUR\/USD|GBP\/USD|AUD\/USD|NZD\/USD|USD\/JPY|USD\/CAD|USD\/CHF|EUR\/GBP)\b/i', $title)) {
                $mentionsTargetCommodity = match ($commodityKey) {
                    'gold' => (bool) preg_match('/\b(gold|bullion|xau|xauusd|gcusd)\b/i', $combinedLower),
                    'silver' => (bool) preg_match('/\b(silver|xag|xagusd|siusd)\b/i', $combinedLower),
                    'crude_oil' => (bool) preg_match('/\b(crude oil|wti|brent|opec|clusd)\b/i', $combinedLower),
                    'natural_gas' => (bool) preg_match('/\b(natural gas|lng|henry hub|ngusd)\b/i', $combinedLower),
                    'copper' => (bool) preg_match('/\b(copper|hgusd)\b/i', $combinedLower),
                    default => false,
                };
                if (! $mentionsTargetCommodity) {
                    return [0, []];
                }
            }

            if ($isDirectProxy) {
                $score += 35;
            }
        }

        foreach ($themes as $themeKey => $themeDef) {
            $weight = (int) ($themeDef['weight'] ?? 25);
            $label = (string) ($themeDef['label'] ?? $themeKey);
            $keywords = (array) ($themeDef['keywords'] ?? []);

            $hitTitle = false;
            $hitBody = false;

            foreach ($keywords as $kw) {
                $kwLower = strtolower(trim((string) $kw));
                if ($kwLower === '') {
                    continue;
                }
                $pattern = '/\b'.preg_quote($kwLower, '/').'\b/i';
                if (preg_match($pattern, $titleLower)) {
                    $hitTitle = true;
                    break;
                }
                if (preg_match($pattern, $bodyLower)) {
                    $hitBody = true;
                }
            }

            if ($hitTitle || $hitBody) {
                $score += $hitTitle ? $weight : (int) round($weight * 0.55);
                $matched[] = [
                    'key' => (string) $themeKey,
                    'label' => $label,
                ];
            }
        }

        // For futures: if the article came from an indirect forex/ETF proxy or general news without direct proxy status,
        // ensure it matches the commodity's primary theme or high-impact policy/yield/inflation/geopolitical drivers.
        if ($assetType === InstrumentClassifier::TYPE_FUTURES) {
            $configured = (array) config("market_data.futures.instruments.{$symbol}", []);
            $commodityKey = (string) ($configured['commodity_profile'] ?? ($symbol === 'GCUSD' ? 'gold' : ''));
            $matchedKeys = array_column($matched, 'key');

            $primaryCommodityThemes = match ($commodityKey) {
                'gold' => ['gold_precious_metals', 'central_bank_gold'],
                'silver' => ['silver_precious_metals', 'gold_precious_metals'],
                'crude_oil' => ['oil_supply'],
                'natural_gas' => ['natural_gas_energy', 'oil_supply'],
                'copper' => ['copper_industrial_metals'],
                default => ['gold_precious_metals', 'central_bank_gold', 'oil_supply'],
            };

            $hasPrimaryTheme = count(array_intersect($matchedKeys, $primaryCommodityThemes)) > 0;
            $hasMacroPolicyTheme = count(array_intersect($matchedKeys, [
                'fed_monetary_policy',
                'inflation_rates',
                'treasury_yields',
                'geopolitics',
            ])) > 0;

            if (! empty($raw['_macro_proxy']) && ! $hasPrimaryTheme && ! $hasMacroPolicyTheme) {
                return [0, []];
            }

            // If an article is specifically a price forecast for a DIFFERENT commodity and doesn't match this commodity's primary theme, exclude it
            if (! $hasPrimaryTheme && preg_match('/\b(Silver Price Forecast|Gold Price Forecast|Natural Gas and Oil Forecast|Oil Price Forecast|Copper Price Forecast)\b/i', $title)) {
                return [0, []];
            }
        }

        if ($assetType === InstrumentClassifier::TYPE_STOCK && empty($matched) && $score >= 25) {
            $matched[] = [
                'key' => 'company_news',
                'label' => 'Company News',
            ];
        }

        return [min(100, $score), $matched];
    }

    /**
     * Check whether the text contains an explicit reference to the stock ticker symbol.
     */
    protected function hasExplicitTickerMention(string $symbol, string $text): bool
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return false;
        }

        // Qualified ticker references: (NASDAQ: MASS), NASDAQ:MASS, $MASS, (MASS), MASS:CA
        $qualifiedPattern = '/(?:(?:\b(?:NASDAQ|NYSE|NYSEAMERICAN|AMEX|TSX|TSXV|OTC)\s*:\s*)|\$|\()'.preg_quote($symbol, '/').'\b/i';
        if (preg_match($qualifiedPattern, $text)) {
            return true;
        }

        // Common English words that are also tickers require uppercase + financial context or qualification
        $commonWordTickers = ['MASS', 'RARE', 'ALL', 'ON', 'CAT', 'FAST', 'BEST', 'GOOD', 'LOVE', 'CARE', 'NEXT', 'OPEN', 'TRUE', 'WORK', 'NOW', 'RUN', 'SEE', 'BIG', 'LOW', 'KEY'];
        if (in_array($symbol, $commonWordTickers, true)) {
            return (bool) preg_match('/\b'.preg_quote($symbol, '/').'\b\s+(?:shares|stock|equity|benefits|rose|fell|gained|dropped|posted|reported)/', $text);
        }

        // Case-sensitive standalone uppercase symbol match (e.g. "NBIS", "CRON", "AAPL")
        return (bool) preg_match('/(?:^|[\s(\[$:,\-])'.preg_quote($symbol, '/').'(?:$|[\s)\].:,\-+])/', $text);
    }

    /**
     * Determine whether SearXNG discovery should be attempted as a secondary fallback.
     */
    protected function shouldAttemptSearxngFallback(bool $force = false): bool
    {
        if (! config('market_data.news.sources.searxng_enabled', true)) {
            return false;
        }

        if (! $force && ($this->provider instanceof FakeMarketDataProvider)) {
            return false;
        }

        $url = trim((string) config('market_data.news.sources.searxng_url', ''));

        return $url !== '';
    }

    /**
     * Query the configured SearXNG JSON endpoint (`/search?q=<query>&format=json`) for targeted news discovery.
     * Never fabricates publication timestamps if missing from search results.
     *
     * @param  array<string, mixed>  $instrument
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    public function fetchSearxngNews(array $instrument, CarbonInterface $from, CarbonInterface $to): array
    {
        $t0 = microtime(true);
        $baseUrl = rtrim((string) config('market_data.news.sources.searxng_url', 'http://searxng:8080'), '/');
        $timeout = max(2, min(15, (int) config('market_data.news.sources.searxng_timeout_seconds', 5)));
        $symbol = strtoupper((string) ($instrument['symbol'] ?? ''));
        $assetType = (string) ($instrument['asset_type'] ?? InstrumentClassifier::TYPE_STOCK);
        $name = trim((string) ($instrument['name'] ?? ''));

        if ($assetType === InstrumentClassifier::TYPE_FUTURES) {
            $query = $name !== '' && $name !== $symbol
                ? "\"{$name}\" OR \"{$symbol}\" market news"
                : "\"{$symbol}\" futures market news";
        } else {
            $cleanName = trim((string) preg_replace('/\b(inc\.?|corp\.?|n\.?v\.?|ltd\.?|plc)\b/i', '', $name));
            $query = $cleanName !== '' && strtolower($cleanName) !== strtolower($symbol)
                ? "\"{$cleanName}\" \"{$symbol}\" stock news"
                : "\"{$symbol}\" stock news";
        }

        try {
            $resp = Http::acceptJson()->timeout($timeout)->get("{$baseUrl}/search", [
                'q' => $query,
                'format' => 'json',
                'categories' => 'news',
            ]);

            $durationMs = (int) round((microtime(true) - $t0) * 1000);

            if (! $resp->successful()) {
                Log::warning('instrument_news.searxng_http_error', [
                    'symbol' => $symbol,
                    'status' => $resp->status(),
                    'duration_ms' => $durationMs,
                ]);

                return [[], [
                    'status' => 'error',
                    'error_code' => $resp->status(),
                    'error' => "SearXNG returned HTTP {$resp->status()}",
                    'count' => 0,
                    'duration_ms' => $durationMs,
                ]];
            }

            $json = $resp->json();
            if (! is_array($json) || ! isset($json['results']) || ! is_array($json['results'])) {
                Log::warning('instrument_news.searxng_json_invalid', [
                    'symbol' => $symbol,
                    'duration_ms' => $durationMs,
                ]);

                return [[], [
                    'status' => 'json_disabled_or_invalid',
                    'error_code' => 422,
                    'error' => 'SearXNG response did not contain a valid JSON results array (check search.formats in SearXNG settings.yml).',
                    'count' => 0,
                    'duration_ms' => $durationMs,
                ]];
            }

            $out = [];
            foreach ($json['results'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $title = trim((string) ($row['title'] ?? ''));
                $url = trim((string) ($row['url'] ?? ''));
                // Strictly require an explicit upstream publication timestamp; never fabricate now()
                $pubRaw = $row['publishedDate'] ?? $row['pubdate'] ?? $row['published_at'] ?? null;
                if ($title === '' || $url === '' || empty($pubRaw)) {
                    continue;
                }

                $parsedPub = $this->parseNewsTimestamp((string) $pubRaw);
                if (! $parsedPub) {
                    continue;
                }

                $host = parse_url($url, PHP_URL_HOST) ?: 'SearXNG News';
                $source = trim((string) ($row['engine'] ?? $row['source'] ?? $host));

                $out[] = [
                    'symbol' => $symbol,
                    'title' => $title,
                    'text' => (string) ($row['content'] ?? $row['snippet'] ?? ''),
                    'publishedDate' => $parsedPub->toIso8601String(),
                    'publisher' => $source,
                    'site' => $host,
                    'url' => $url,
                    '_source_channel' => 'searxng',
                    '_direct_symbol' => $assetType === InstrumentClassifier::TYPE_STOCK,
                    '_direct_proxy' => $assetType === InstrumentClassifier::TYPE_FUTURES,
                ];
            }

            return [$out, [
                'status' => 'ok',
                'count' => count($out),
                'duration_ms' => $durationMs,
            ]];
        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $t0) * 1000);
            Log::info('instrument_news.searxng_unreachable', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
                'duration_ms' => $durationMs,
            ]);

            return [[], [
                'status' => 'unreachable',
                'error_code' => 0,
                'error' => $e->getMessage(),
                'count' => 0,
                'duration_ms' => $durationMs,
            ]];
        }
    }

    /**
     * Persist normalized articles to `instrument_news_articles` so historical timeline navigation
     * retains previously collected articles across sessions and users.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function persistArticles(string $symbol, array $items): void
    {
        if (empty($items) || ! config('market_data.news.persistent_storage_enabled', true)) {
            return;
        }

        try {
            if (! Schema::hasTable('instrument_news_articles')) {
                return;
            }

            $now = now();
            $rowsToUpsert = [];
            foreach (array_slice($items, 0, 100) as $item) {
                $uid = (string) ($item['id'] ?? '');
                if ($uid === '') {
                    continue;
                }

                $rowsToUpsert[] = [
                    'symbol' => $symbol,
                    'article_uid' => $uid,
                    'headline' => mb_substr((string) ($item['title'] ?? ''), 0, 500),
                    'summary' => $item['summary'] ?? null,
                    'publisher' => isset($item['source']) ? mb_substr((string) $item['source'], 0, 190) : null,
                    'site' => isset($item['site']) ? mb_substr((string) $item['site'], 0, 190) : null,
                    'canonical_url' => isset($item['canonical_url']) ? mb_substr((string) $item['canonical_url'], 0, 500) : null,
                    'url' => $item['url'] ?? null,
                    'source_provider' => mb_substr((string) ($item['source_provider'] ?? 'fmp'), 0, 64),
                    'published_at' => Carbon::createFromTimestamp((int) $item['published_timestamp'], 'UTC')->toDateTimeString(),
                    'event_at' => ! empty($item['event_timestamp'])
                        ? Carbon::createFromTimestamp((int) $item['event_timestamp'], 'UTC')->toDateTimeString()
                        : null,
                    'retrieved_at' => $now->toDateTimeString(),
                    'relevance_score' => (int) ($item['relevance_score'] ?? 0),
                    'primary_category_key' => $item['primary_category_key'] ?? null,
                    'primary_category_label' => $item['primary_category_label'] ?? null,
                    'categories' => json_encode($item['categories'] ?? []),
                    'symbols' => json_encode($item['symbols'] ?? [$symbol]),
                    'relevance_metadata' => json_encode([
                        'marker_timestamp' => $item['marker_timestamp'] ?? $item['published_timestamp'],
                    ]),
                    'created_at' => $now->toDateTimeString(),
                    'updated_at' => $now->toDateTimeString(),
                ];
            }

            if (! empty($rowsToUpsert)) {
                InstrumentNewsArticle::upsert(
                    $rowsToUpsert,
                    ['symbol', 'article_uid'],
                    [
                        'headline',
                        'summary',
                        'publisher',
                        'site',
                        'canonical_url',
                        'url',
                        'source_provider',
                        'published_at',
                        'event_at',
                        'retrieved_at',
                        'relevance_score',
                        'primary_category_key',
                        'primary_category_label',
                        'categories',
                        'symbols',
                        'relevance_metadata',
                        'updated_at',
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('instrument_news.persist_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Load previously persisted articles for a symbol and date window from the database.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function loadPersistedArticles(
        string $symbol,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $aroundTimestamp = null
    ): array {
        if (! config('market_data.news.persistent_storage_enabled', true)) {
            return [];
        }

        try {
            if (! Schema::hasTable('instrument_news_articles')) {
                return [];
            }

            $rows = InstrumentNewsArticle::where('symbol', $symbol)
                ->whereBetween('published_at', [$from->copy()->startOfMinute(), $to->copy()->endOfMinute()])
                ->orderByDesc('published_at')
                ->limit(150)
                ->get();

            if ($rows->isEmpty()) {
                return [];
            }

            $instrument = $this->classifier->resolve($symbol);
            $assetType = $instrument['asset_type'];
            $profileConfig = $this->resolveEffectiveProfileConfig($assetType, $symbol);
            $minScore = (int) ($profileConfig['min_score'] ?? 25);
            $themes = (array) ($profileConfig['themes'] ?? []);

            $out = [];
            foreach ($rows as $row) {
                $pubCarbon = $row->published_at ? Carbon::instance($row->published_at)->utc() : null;
                if (! $pubCarbon) {
                    continue;
                }
                $pubTs = $pubCarbon->timestamp;
                $eventCarbon = $row->event_at ? Carbon::instance($row->event_at)->utc() : null;
                $eventTs = $eventCarbon?->timestamp;

                $sourceProvider = (string) ($row->source_provider ?? 'fmp');
                $rawSynthetic = [
                    'symbol' => $row->symbol,
                    '_source_channel' => $sourceProvider,
                    '_direct_symbol' => in_array($sourceProvider, ['fmp_stock', 'fmp_press_release', 'official_rss', 'searxng'], true),
                    '_direct_proxy' => in_array($sourceProvider, ['fmp_forex', 'fmp_economic_calendar', 'fed_rss', 'official_rss'], true),
                    '_macro_proxy' => in_array($sourceProvider, ['fmp_forex_latest', 'fmp_proxy_news'], true),
                ];

                [$recomputedScore, $recomputedCats] = $this->scoreRelevance(
                    raw: $rawSynthetic,
                    title: (string) $row->headline,
                    summary: (string) ($row->summary ?? ''),
                    instrument: $instrument,
                    themes: $themes
                );

                if ($recomputedScore < $minScore) {
                    continue;
                }

                $cats = ! empty($recomputedCats) ? $recomputedCats : (is_array($row->categories) ? $row->categories : []);

                $windowPhase = null;
                $offsetMinutes = null;
                if ($aroundTimestamp !== null && $aroundTimestamp > 0) {
                    $diffSec = $pubTs - $aroundTimestamp;
                    $offsetMinutes = (int) round($diffSec / 60);
                    $windowPhase = $diffSec <= 0 ? 'before_or_during' : 'look_ahead';
                }

                $ai = is_array($row->ai_enrichment) ? $row->ai_enrichment : null;
                $primaryCat = $cats[0] ?? [
                    'key' => (string) ($row->primary_category_key ?? 'company_news'),
                    'label' => (string) ($row->primary_category_label ?? 'Company News'),
                ];

                $out[] = [
                    'id' => $row->article_uid,
                    'symbol' => $row->symbol,
                    'symbols' => is_array($row->symbols) ? $row->symbols : [$row->symbol],
                    'title' => $row->headline,
                    'summary' => (string) ($row->summary ?? ''),
                    'source' => (string) ($row->publisher ?: ($row->site ?: 'Market News')),
                    'site' => (string) ($row->site ?? ''),
                    'source_provider' => $sourceProvider,
                    'url' => $row->url,
                    'canonical_url' => $row->canonical_url,
                    'marker_timestamp' => $pubTs,
                    'published_at' => $pubCarbon->toIso8601String(),
                    'published_timestamp' => $pubTs,
                    'published_display' => $pubCarbon->format('M j, Y H:i').' UTC',
                    'event_at' => $eventCarbon?->toIso8601String(),
                    'event_timestamp' => $eventTs,
                    'event_display' => $eventCarbon ? $eventCarbon->format('M j, Y H:i').' UTC' : null,
                    'has_distinct_event_time' => $eventTs !== null && abs($eventTs - $pubTs) >= 60,
                    'relevance_score' => $recomputedScore,
                    'primary_category_key' => (string) $primaryCat['key'],
                    'primary_category_label' => (string) $primaryCat['label'],
                    'categories' => $cats,
                    'category_keys' => array_values(array_map(fn ($c) => $c['key'] ?? '', $cats)),
                    'window_phase' => $windowPhase,
                    'offset_minutes' => $offsetMinutes,
                    'ai_enrichment' => $ai,
                    'ai_enriched' => is_array($ai) && isset($ai['relevant']),
                    'ai_relevant' => is_array($ai) ? (bool) ($ai['relevant'] ?? true) : null,
                    'ai_market_moving' => is_array($ai) ? (bool) ($ai['market_moving'] ?? false) : false,
                    'ai_rationale' => is_array($ai) && ! empty($ai['why_it_matters']) ? (string) $ai['why_it_matters'] : null,
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Merge freshly normalized items with persisted historical items by article ID.
     *
     * @param  array<int, array<string, mixed>>  $fresh
     * @param  array<int, array<string, mixed>>  $persisted
     * @return array<int, array<string, mixed>>
     */
    protected function mergeWithPersisted(array $fresh, array $persisted): array
    {
        $byId = [];
        foreach ($fresh as $item) {
            $byId[$item['id']] = $item;
        }
        foreach ($persisted as $pItem) {
            $id = $pItem['id'];
            if (! isset($byId[$id])) {
                $byId[$id] = $pItem;
            } elseif (empty($byId[$id]['ai_enrichment']) && ! empty($pItem['ai_enrichment'])) {
                $byId[$id]['ai_enrichment'] = $pItem['ai_enrichment'];
            }
        }

        $merged = array_values($byId);
        usort($merged, fn (array $a, array $b) => $b['published_timestamp'] <=> $a['published_timestamp']);

        return $merged;
    }

    /**
     * Determine overall retrieval status and failure category from normalized items and per-source diagnostics.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, array<string, mixed>>  $sourceStats
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    protected function resolveRetrievalStatus(array $items, array $sourceStats): array
    {
        $primaryErrors = [];
        foreach ($sourceStats as $sourceKey => $stat) {
            if ($sourceKey === 'searxng') {
                continue; // SearXNG is an optional discovery fallback
            }
            $st = (string) ($stat['status'] ?? 'ok');
            if (in_array($st, ['error', 'missing_method'], true)) {
                $primaryErrors[$sourceKey] = $stat;
            }
        }

        if (! empty($items)) {
            return [self::STATUS_AVAILABLE, null, null];
        }

        // Check if provider recorded direct errors even when sourceStats was partial
        $providerErrors = method_exists($this->provider, 'lastErrors') ? $this->provider->lastErrors() : [];
        if (empty($primaryErrors) && ! empty($providerErrors)) {
            $lastErr = end($providerErrors);
            $primaryErrors['provider'] = [
                'status' => 'error',
                'error_code' => (int) ($lastErr['status'] ?? 503),
                'error' => (string) ($lastErr['body'] ?? 'Provider error'),
            ];
        }

        if (! empty($primaryErrors)) {
            $firstErr = reset($primaryErrors);
            $code = (int) ($firstErr['error_code'] ?? 503);

            if ($code === 429) {
                return [
                    self::STATUS_RATE_LIMITED,
                    'News provider rate limit exceeded (HTTP 429). Please retry shortly.',
                    'rate_limited',
                ];
            }

            if (in_array($code, [401, 402, 403], true)) {
                return [
                    self::STATUS_AUTH_ERROR,
                    "News provider authorization or subscription error (HTTP {$code}). Verify FMP_API_KEY and plan permissions.",
                    'auth_or_subscription_error',
                ];
            }

            if ($code === 501) {
                return [
                    self::STATUS_SERVICE_UNAVAILABLE,
                    'Configured market data provider is missing required news delegation methods.',
                    'missing_provider_method',
                ];
            }

            return [
                self::STATUS_SERVICE_UNAVAILABLE,
                'External news service is temporarily unavailable. Please retry shortly.',
                'provider_unavailable',
            ];
        }

        return [
            self::STATUS_EMPTY,
            'No relevant news articles found for this instrument in the selected date window.',
            null,
        ];
    }

    /**
     * Emit structured diagnostic log entry without exposing API keys or secrets.
     *
     * @param  array<string, mixed>  $diagnostics
     */
    protected function logRetrievalDiagnostics(string $symbol, string $assetType, string $status, array $diagnostics): void
    {
        $payload = [
            'symbol' => $symbol,
            'asset_type' => $assetType,
            'status' => $status,
            'raw_articles' => $diagnostics['raw_count'] ?? 0,
            'retained_articles' => $diagnostics['retained_count'] ?? 0,
            'filtered_or_deduplicated' => $diagnostics['filtered_count'] ?? 0,
            'cache_hit' => (bool) ($diagnostics['cache_hit'] ?? false),
            'duration_ms' => $diagnostics['duration_ms'] ?? 0,
            'failure_category' => $diagnostics['failure_category'] ?? null,
            'sources' => array_map(fn ($s) => [
                'status' => $s['status'] ?? 'unknown',
                'count' => $s['count'] ?? 0,
                'error_code' => $s['error_code'] ?? null,
            ], (array) ($diagnostics['sources'] ?? [])),
        ];

        if (in_array($status, [self::STATUS_AUTH_ERROR, self::STATUS_RATE_LIMITED, self::STATUS_SERVICE_UNAVAILABLE], true)) {
            Log::warning('instrument_news.retrieval_degraded', $payload);
        } else {
            Log::info('instrument_news.retrieved', $payload);
        }
    }

    /**
     * Build capped Vela-compatible timeline markers from the news items.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function buildChartMarkers(array $items): array
    {
        $maxMarkers = (int) config('market_data.news.max_markers_on_chart', 25);

        $sortedByRelevance = $items;
        usort($sortedByRelevance, function (array $a, array $b) {
            if ($b['relevance_score'] !== $a['relevance_score']) {
                return $b['relevance_score'] <=> $a['relevance_score'];
            }

            return $b['published_timestamp'] <=> $a['published_timestamp'];
        });

        $selected = array_slice($sortedByRelevance, 0, $maxMarkers);
        usort($selected, fn (array $a, array $b) => $a['published_timestamp'] <=> $b['published_timestamp']);

        return array_values(array_map(function (array $item) {
            $catKey = $item['primary_category_key'] ?? '';
            $color = match ($catKey) {
                'gold_precious_metals', 'central_bank_gold', 'silver_precious_metals', 'copper_industrial_metals' => '#d97706',
                'fed_monetary_policy', 'inflation_rates' => '#7c3aed',
                'geopolitics', 'oil_supply', 'natural_gas_energy' => '#e11d48',
                'earnings_financials' => '#0284c7',
                default => '#0284c7',
            };

            $letter = match ($catKey) {
                'gold_precious_metals', 'central_bank_gold' => 'G',
                'silver_precious_metals' => 'S',
                'copper_industrial_metals' => 'C',
                'fed_monetary_policy' => 'F',
                'inflation_rates' => 'I',
                'geopolitics' => '!',
                'oil_supply', 'natural_gas_energy' => 'O',
                'earnings_financials' => 'E',
                default => 'N',
            };

            return [
                'id' => $item['id'],
                'time' => $item['marker_timestamp'] * 1000, // Vela uses epoch ms
                'timestamp' => $item['marker_timestamp'],
                'title' => $item['title'],
                'tooltip' => $item['title'].' ('.$item['source'].' • '.$item['published_display'].')',
                'source' => $item['source'],
                'category' => $item['primary_category_label'],
                'color' => $color,
                'letter' => $letter,
            ];
        }, $selected));
    }

    /**
     * Extract an explicit original event time if distinct from publication time.
     *
     * @param  array<string, mixed>  $raw
     */
    protected function extractEventTimestamp(array $raw, string $title, string $summary, Carbon $publishedCarbon): ?Carbon
    {
        if (! empty($raw['event_at']) || ! empty($raw['eventDate'])) {
            $parsed = $this->parseNewsTimestamp((string) ($raw['event_at'] ?? $raw['eventDate']));
            if ($parsed && $parsed->lessThanOrEqualTo($publishedCarbon)) {
                return $parsed;
            }
        }

        // Check for explicit clock time in text such as "at 14:00 UTC" or "at 2:00 p.m. ET" / "at 08:30 EDT"
        $combined = $title.' '.$summary;
        if (preg_match('/\bat\s+(\d{1,2}:\d{2})\s*(a\.?m\.?|p\.?m\.?)?\s*(UTC|GMT|EST|EDT|ET)\b/i', $combined, $matches)) {
            try {
                $timePart = $matches[1];
                $ampm = isset($matches[2]) && $matches[2] !== '' ? str_replace('.', '', strtolower($matches[2])) : '';
                $tzRaw = strtoupper($matches[3]);
                $tz = in_array($tzRaw, ['EST', 'EDT', 'ET'], true) ? 'America/New_York' : 'UTC';

                $dateStr = $publishedCarbon->copy()->setTimezone($tz)->toDateString();
                $candidate = Carbon::parse(trim("{$dateStr} {$timePart} {$ampm}"), $tz)->utc();

                if ($candidate->lessThanOrEqualTo($publishedCarbon) && $publishedCarbon->diffInHours($candidate) <= 12) {
                    return $candidate;
                }
            } catch (\Throwable) {
                // Ignore parse error
            }
        }

        return null;
    }

    /**
     * Convert high-relevance macroeconomic calendar items into timeline events.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    protected function convertEconomicCalendarRow(array $row): ?array
    {
        $country = strtoupper(trim((string) ($row['country'] ?? '')));
        $impact = strtolower(trim((string) ($row['impact'] ?? '')));
        $event = trim((string) ($row['event'] ?? ''));
        $date = trim((string) ($row['date'] ?? ''));

        if ($event === '' || $date === '') {
            return null;
        }

        if ($country !== 'US' || ! in_array($impact, ['high', 'medium'], true)) {
            return null;
        }

        $eventLower = strtolower($event);
        $relevantMacro = ['fed', 'fomc', 'cpi', 'inflation', 'pce', 'nonfarm', 'payroll', 'ppi', 'gdp', 'treasury', 'interest rate', 'powell', 'gold', 'crude oil', 'natural gas', 'jobless'];
        $matchesMacro = false;
        foreach ($relevantMacro as $kw) {
            if (str_contains($eventLower, $kw)) {
                $matchesMacro = true;
                break;
            }
        }

        if (! $matchesMacro && $impact !== 'high') {
            return null;
        }

        $details = [];
        if (isset($row['actual']) && $row['actual'] !== null && $row['actual'] !== '') {
            $details[] = 'Actual: '.$row['actual'].($row['unit'] ?? '');
        }
        if (isset($row['estimate']) && $row['estimate'] !== null && $row['estimate'] !== '') {
            $details[] = 'Consensus: '.$row['estimate'].($row['unit'] ?? '');
        }
        if (isset($row['previous']) && $row['previous'] !== null && $row['previous'] !== '') {
            $details[] = 'Prior: '.$row['previous'].($row['unit'] ?? '');
        }

        $summary = empty($details)
            ? "U.S. macroeconomic release ({$row['impact']} impact)."
            : implode(' • ', $details)." ({$row['impact']} impact U.S. release).";

        return [
            'symbol' => 'US-MACRO',
            'title' => "U.S. Economic Release: {$event}",
            'text' => $summary,
            'publishedDate' => $date,
            'event_at' => $date,
            'publisher' => 'Economic Calendar',
            'site' => 'federalreserve.gov / BLS / BEA',
            'url' => null,
            '_source_channel' => 'fmp_economic_calendar',
            '_direct_proxy' => true,
        ];
    }

    /**
     * Fetch Federal Reserve monetary policy announcements via RSS when enabled.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchFederalReserveRss(CarbonInterface $from, CarbonInterface $to): array
    {
        if (! config('market_data.news.fed_rss_enabled', true)) {
            return [];
        }

        // Avoid outbound HTTP requests during tests that use FakeMarketDataProvider
        if ($this->provider instanceof FakeMarketDataProvider) {
            return [];
        }

        if ($this->provider instanceof \App\Services\MarketData\PersistentMarketDataProvider
            && $this->provider->getUpstream() instanceof FakeMarketDataProvider) {
            return [];
        }

        $rssUrl = (string) config('market_data.news.fed_rss_url', 'https://www.federalreserve.gov/feeds/press_monetary.xml');
        if ($rssUrl === '') {
            return [];
        }

        return Cache::remember('duoasset.news.fed_rss', now()->addMinutes(30), function () use ($rssUrl) {
            return $this->parseRssFeed($rssUrl, 'FED', 'Federal Reserve: ', 'Federal Reserve Board', 'federalreserve.gov', 'fed_rss', true);
        });
    }

    /**
     * Fetch any additional official RSS feeds configured under `market_data.news.sources.rss_feeds`.
     *
     * @param  array<string, mixed>  $instrument
     * @return array<int, array<string, mixed>>
     */
    protected function fetchConfiguredRssFeeds(array $instrument, CarbonInterface $from, CarbonInterface $to): array
    {
        $feeds = (array) config('market_data.news.sources.rss_feeds', []);
        if (empty($feeds)) {
            return [];
        }

        $symbol = strtoupper((string) ($instrument['symbol'] ?? ''));
        $assetType = (string) ($instrument['asset_type'] ?? InstrumentClassifier::TYPE_STOCK);
        $out = [];

        foreach ($feeds as $feed) {
            if (! is_array($feed) || empty($feed['url'])) {
                continue;
            }
            if (isset($feed['enabled']) && ! $feed['enabled']) {
                continue;
            }
            if (! empty($feed['symbols']) && is_array($feed['symbols'])) {
                $allowedSyms = array_map('strtoupper', $feed['symbols']);
                if (! in_array($symbol, $allowedSyms, true)) {
                    continue;
                }
            }
            if (! empty($feed['asset_type']) && $feed['asset_type'] !== $assetType) {
                continue;
            }

            $url = (string) $feed['url'];
            $publisher = (string) ($feed['publisher'] ?? 'Official RSS');
            $site = (string) ($feed['site'] ?? (parse_url($url, PHP_URL_HOST) ?: 'rss'));
            $cacheKey = 'duoasset.news.rss.'.md5($url);

            $rows = Cache::remember($cacheKey, now()->addMinutes(20), function () use ($url, $symbol, $publisher, $site) {
                return $this->parseRssFeed($url, $symbol, '', $publisher, $site, 'official_rss', true);
            });

            $out = array_merge($out, $rows);
        }

        return $out;
    }

    /**
     * Parse a standard RSS 2.0 or Atom XML feed safely, preserving original publication timestamps and links.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function parseRssFeed(
        string $rssUrl,
        string $symbol,
        string $titlePrefix,
        string $publisher,
        string $site,
        string $channel,
        bool $directProxy = false
    ): array {
        try {
            $resp = Http::timeout(6)->get($rssUrl);
            if (! $resp->successful()) {
                return [];
            }

            $xml = @simplexml_load_string($resp->body(), 'SimpleXMLElement', LIBXML_NOCDATA);
            if (! $xml) {
                return [];
            }

            $items = [];
            if (isset($xml->channel->item)) {
                $items = $xml->channel->item;
            } elseif (isset($xml->entry)) {
                $items = $xml->entry;
            }

            $out = [];
            foreach ($items as $item) {
                $title = trim((string) ($item->title ?? ''));
                $link = trim((string) ($item->link ?? ''));
                if ($link === '' && isset($item->link['href'])) {
                    $link = trim((string) $item->link['href']);
                }
                $desc = trim((string) ($item->description ?? $item->summary ?? ''));
                $pubDate = trim((string) ($item->pubDate ?? $item->published ?? $item->updated ?? ''));

                if ($title === '' || $pubDate === '') {
                    continue;
                }

                $parsed = $this->parseNewsTimestamp($pubDate);
                if (! $parsed) {
                    continue;
                }

                $out[] = [
                    'symbol' => $symbol,
                    'title' => $titlePrefix !== '' ? $titlePrefix.$title : $title,
                    'text' => $desc !== '' ? $desc : $title,
                    'publishedDate' => $parsed->toIso8601String(),
                    'event_at' => $parsed->toIso8601String(),
                    'publisher' => $publisher,
                    'site' => $site,
                    'url' => $link,
                    '_source_channel' => $channel,
                    '_direct_proxy' => $directProxy,
                    '_direct_symbol' => $directProxy,
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('instrument_news.rss_parse_error', [
                'url' => $rssUrl,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    public function getAvailableCategories(string $assetType, ?string $symbol = null): array
    {
        $profile = $this->resolveEffectiveProfileConfig($assetType, strtoupper((string) $symbol));
        $themes = (array) ($profile['themes'] ?? []);
        $out = [];
        foreach ($themes as $key => $def) {
            $out[] = [
                'key' => (string) $key,
                'label' => (string) ($def['label'] ?? $key),
            ];
        }

        return $out;
    }

    protected function parseNewsTimestamp(string $raw): ?Carbon
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            if (is_numeric($raw)) {
                $ts = (int) $raw;

                return Carbon::createFromTimestamp($ts > 1e11 ? (int) round($ts / 1000) : $ts, 'UTC');
            }

            $hasExplicitOffset = (bool) preg_match('/(?:\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?)\s*(?:Z|UTC|GMT|[+\-]\d{2}(?::?\d{2})?)$/i', $raw);
            if ($hasExplicitOffset) {
                return Carbon::parse($raw)->utc();
            }

            // FMP news timestamps without timezone offset are reported in America/New_York
            return Carbon::parse($raw, 'America/New_York')->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function parseBound(mixed $value, bool $isEnd = false): ?Carbon
    {
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value)->copy()->utc();
        }

        if (is_numeric($value)) {
            $ts = (int) $value;
            if ($ts <= 0) {
                return null;
            }

            return Carbon::createFromTimestamp($ts > 1e11 ? (int) round($ts / 1000) : $ts, 'UTC');
        }

        if (is_string($value) && trim($value) !== '') {
            $raw = trim($value);
            try {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                    $c = Carbon::parse($raw, 'UTC');

                    return $isEnd ? $c->endOfDay() : $c->startOfDay();
                }

                return Carbon::parse($raw, 'UTC')->utc();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    protected function canonicalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return strtolower($url);
        }

        $host = strtolower(preg_replace('/^www\./i', '', $parts['host']) ?? $parts['host']);
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return $host.$path;
    }

    protected function headlineFingerprint(string $title): string
    {
        $lower = strtolower($title);
        $clean = preg_replace('/[^a-z0-9\s]/', ' ', $lower) ?? $lower;
        $words = array_values(array_filter(explode(' ', $clean), fn ($w) => strlen($w) > 2));

        return implode(' ', array_slice($words, 0, 12));
    }
}
