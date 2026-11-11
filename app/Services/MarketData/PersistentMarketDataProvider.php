<?php

namespace App\Services\MarketData;

use App\Models\CompanyFinancial;
use App\Models\CompanyProfile;
use App\Models\MarketDataPayload;
use App\Models\StockDailyBar;
use App\Services\Dashboard\TradingSessionService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Two-level market data provider:
 * - Level 1: In-memory cache for ultra-fast repeat access.
 * - Level 2: Authoritative persistent database (never expires automatically).
 *
 * Missing or incremental data is fetched from the upstream provider (e.g. FMP)
 * and persisted immediately across both relational models and full JSON archives.
 */
class PersistentMarketDataProvider implements MarketDataProvider
{
    protected FinancialStorageConfigService $financialStorageConfig;

    /**
     * @var array<int, array{path:string,status:int,body:string}>
     */
    protected array $localErrors = [];

    public function __construct(
        protected MarketDataProvider $upstream,
        ?FinancialStorageConfigService $financialStorageConfig = null,
    ) {
        $this->financialStorageConfig = $financialStorageConfig ?? app(FinancialStorageConfigService::class);
    }

    public function isFinancialStorageEnabled(): bool
    {
        return $this->financialStorageConfig->isEnabled();
    }

    public function setFinancialStorageEnabled(bool $enabled): self
    {
        $this->financialStorageConfig->setEnabled($enabled);

        return $this;
    }

    public function getUpstream(): MarketDataProvider
    {
        return $this->upstream;
    }

    protected function normalizeFuturesAlias(string $symbol): string
    {
        $sym = strtoupper(trim($symbol));
        if ($sym === '') {
            return '';
        }

        if (method_exists($this->upstream, 'normalizeFuturesAlias')) {
            try {
                return $this->upstream->normalizeFuturesAlias($sym);
            } catch (\Throwable) {
            }
        }

        $aliases = (array) config('market_data.futures.aliases', []);
        if (isset($aliases[$sym])) {
            return strtoupper(trim((string) $aliases[$sym]));
        }

        if (str_ends_with($sym, 'USDT') && strlen($sym) > 4) {
            $usdCandidate = substr($sym, 0, -1);
            $configured = (array) config('market_data.futures.instruments', []);
            if (isset($configured[$usdCandidate])) {
                return $usdCandidate;
            }
            foreach ($this->commoditiesList() as $item) {
                if (is_array($item) && strtoupper(trim((string) ($item['symbol'] ?? ''))) === $usdCandidate) {
                    return $usdCandidate;
                }
            }
        }

        return $sym;
    }

    public function searchSymbols(string $query): array
    {
        if (method_exists($this->upstream, 'searchSymbols')) {
            return $this->upstream->searchSymbols($query);
        }

        return [];
    }

    public function commoditiesList(): array
    {
        $v = self::getCacheVersion('all', 'GLOBAL');
        $cacheKey = "duoasset.v{$v}.commodities_list";
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && ! empty($cached)) {
            return $cached;
        }

        $stored = MarketDataPayload::where('symbol', 'GLOBAL')
            ->where('data_type', 'commodities_list')
            ->where('period', 'latest')
            ->first();

        if ($stored && is_array($stored->payload) && ! empty($stored->payload) && $stored->retrieved_at && $stored->retrieved_at->diffInHours(now()) < 24) {
            Cache::put($cacheKey, $stored->payload, now()->addHours(12));

            return $stored->payload;
        }

        if (method_exists($this->upstream, 'commoditiesList')) {
            try {
                $fresh = $this->upstream->commoditiesList();
                if (is_array($fresh) && ! empty($fresh)) {
                    MarketDataPayload::updateOrCreate(
                        ['symbol' => 'GLOBAL', 'data_type' => 'commodities_list', 'period' => 'latest'],
                        ['payload' => $fresh, 'retrieved_at' => now()]
                    );
                    Cache::put($cacheKey, $fresh, now()->addHours(12));

                    return $fresh;
                }
            } catch (Throwable $e) {
                Log::warning('persistent_market_data.commodities_list_failed', ['error' => $e->getMessage()]);
            }
        }

        return $stored && is_array($stored->payload) ? $stored->payload : [];
    }

    /**
     * Delegate stock/ETF news retrieval to the upstream provider.
     *
     * @param  array<int, string>  $symbols
     * @return array<int, array<string, mixed>>
     */
    public function stockNews(array $symbols = [], ?CarbonInterface $from = null, ?CarbonInterface $to = null, int $limit = 50): array
    {
        if (method_exists($this->upstream, 'stockNews')) {
            return $this->upstream->stockNews($symbols, $from, $to, $limit);
        }

        $this->recordMissingMethodError('stockNews');

        return [];
    }

    /**
     * Delegate forex/commodity news retrieval to the upstream provider.
     *
     * @param  array<int, string>  $symbols
     * @return array<int, array<string, mixed>>
     */
    public function forexNews(array $symbols = [], ?CarbonInterface $from = null, ?CarbonInterface $to = null, int $limit = 50): array
    {
        if (method_exists($this->upstream, 'forexNews')) {
            return $this->upstream->forexNews($symbols, $from, $to, $limit);
        }

        $this->recordMissingMethodError('forexNews');

        return [];
    }

    /**
     * Delegate general market news retrieval to the upstream provider.
     *
     * @return array<int, array<string, mixed>>
     */
    public function generalNews(?CarbonInterface $from = null, ?CarbonInterface $to = null, int $limit = 50): array
    {
        if (method_exists($this->upstream, 'generalNews')) {
            return $this->upstream->generalNews($from, $to, $limit);
        }

        $this->recordMissingMethodError('generalNews');

        return [];
    }

    /**
     * Delegate company press releases retrieval to the upstream provider.
     *
     * @param  array<int, string>  $symbols
     * @return array<int, array<string, mixed>>
     */
    public function pressReleases(array $symbols = [], ?CarbonInterface $from = null, ?CarbonInterface $to = null, int $limit = 20): array
    {
        if (method_exists($this->upstream, 'pressReleases')) {
            return $this->upstream->pressReleases($symbols, $from, $to, $limit);
        }

        $this->recordMissingMethodError('pressReleases');

        return [];
    }

    /**
     * Delegate macroeconomic calendar retrieval to the upstream provider.
     *
     * @return array<int, array<string, mixed>>
     */
    public function economicCalendar(CarbonInterface $from, CarbonInterface $to): array
    {
        if (method_exists($this->upstream, 'economicCalendar')) {
            return $this->upstream->economicCalendar($from, $to);
        }

        $this->recordMissingMethodError('economicCalendar');

        return [];
    }

    protected function recordMissingMethodError(string $method): void
    {
        $upstreamClass = get_class($this->upstream);
        $err = [
            'path' => "provider::{$method}",
            'status' => 501,
            'body' => "Upstream market data provider [{$upstreamClass}] does not implement [{$method}].",
        ];
        $this->localErrors[] = $err;
        Log::warning('persistent_market_data.missing_upstream_method', $err);
    }

    public function lookupOverview(string $symbol): ?array
    {
        if (method_exists($this->upstream, 'lookupOverview')) {
            $overview = $this->upstream->lookupOverview($symbol);
            if ($overview !== null) {
                return $overview;
            }
        }

        $profile = $this->profile($symbol);
        if (! is_array($profile)) {
            return null;
        }

        return [
            'symbol' => (string) ($profile['symbol'] ?? strtoupper($symbol)),
            'name' => (string) ($profile['company_name'] ?? $profile['companyName'] ?? $profile['name'] ?? ''),
            'exchange' => strtoupper((string) ($profile['exchange'] ?? '')),
            'currency' => strtoupper((string) ($profile['currency'] ?? '')),
            'country' => (string) ($profile['country'] ?? ''),
            'sector' => (string) ($profile['sector'] ?? ''),
            'industry' => (string) ($profile['industry'] ?? ''),
            'sub_industry' => (string) ($profile['sub_industry'] ?? ''),
        ];
    }

    public function __call(string $method, array $arguments): mixed
    {
        if (method_exists($this->upstream, $method)) {
            return $this->upstream->{$method}(...$arguments);
        }

        throw new \BadMethodCallException("Method [{$method}] does not exist on [".static::class.'].');
    }

    public static function getCacheVersion(string $type = 'all', string $symbol = ''): int
    {
        // Support legacy signature getCacheVersion(string $symbol)
        $knownTypes = ['all', 'bars', 'financials', 'profile', 'quote', 'intraday', 'estimates', 'screener', 'earnings'];
        if ($symbol === '' && ! in_array(strtolower($type), $knownTypes, true)) {
            $symbol = $type;
            $type = 'all';
        }

        $type = strtolower($type);
        $global = (int) Cache::get('duoasset.market_data.cache_version', 1);
        $typeGlobal = $type !== 'all' ? (int) Cache::get("duoasset.market_data.cache_version.{$type}", 0) : 0;
        $sym = $symbol !== '' ? (int) Cache::get("duoasset.market_data.version.{$symbol}", 0) : 0;
        $symType = ($symbol !== '' && $type !== 'all') ? (int) Cache::get("duoasset.market_data.version.{$symbol}.{$type}", 0) : 0;

        return $global + $typeGlobal + $sym + $symType;
    }

    public static function bumpCacheVersion(string $type = 'all', string $symbol = ''): void
    {
        // Support legacy signature bumpCacheVersion(string $symbol)
        $knownTypes = ['all', 'bars', 'financials', 'profile', 'quote', 'intraday', 'estimates', 'screener', 'earnings'];
        if ($symbol === '' && ! in_array(strtolower($type), $knownTypes, true)) {
            $symbol = $type;
            $type = 'all';
        }

        $type = strtolower($type);

        if ($symbol !== '') {
            if ($type === 'all') {
                $curr = (int) Cache::get("duoasset.market_data.version.{$symbol}", 0);
                Cache::put("duoasset.market_data.version.{$symbol}", $curr + 1, now()->addDays(30));
                Cache::forget("duoasset.profile.{$symbol}");
                Cache::forget("fmp.quote.{$symbol}");
                Cache::forget("fmp.profile_raw.{$symbol}");
            } else {
                $curr = (int) Cache::get("duoasset.market_data.version.{$symbol}.{$type}", 0);
                Cache::put("duoasset.market_data.version.{$symbol}.{$type}", $curr + 1, now()->addDays(30));
                if ($type === 'profile') {
                    Cache::forget("duoasset.profile.{$symbol}");
                    Cache::forget("fmp.profile_raw.{$symbol}");
                } elseif ($type === 'quote') {
                    Cache::forget("fmp.quote.{$symbol}");
                }
            }
        } else {
            if ($type === 'all') {
                $curr = (int) Cache::get('duoasset.market_data.cache_version', 1);
                Cache::put('duoasset.market_data.cache_version', $curr + 1, now()->addDays(30));
            } else {
                $curr = (int) Cache::get("duoasset.market_data.cache_version.{$type}", 0);
                Cache::put("duoasset.market_data.cache_version.{$type}", $curr + 1, now()->addDays(30));
            }
        }
    }

    public function storedDailyBars(string $symbol, CarbonInterface $from, CarbonInterface $to): array
    {
        $symbol = $this->normalizeFuturesAlias($symbol);
        if ($symbol === '') {
            return [];
        }

        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();

        return StockDailyBar::query()
            ->where('symbol', $symbol)
            ->whereBetween('bar_date', [$fromStr, $toStr])
            ->orderBy('bar_date')
            ->get()
            ->map(fn (StockDailyBar $b) => [
                'date' => $b->bar_date->toDateString(),
                'open' => (float) $b->open,
                'high' => (float) $b->high,
                'low' => (float) $b->low,
                'close' => (float) $b->close,
                'adj_close' => $b->adj_close !== null ? (float) $b->adj_close : null,
                'volume' => (int) $b->volume,
            ])
            ->values()
            ->all();
    }

    public function isDailyBarsStaleOrMissing(string $symbol, CarbonInterface $from, CarbonInterface $to): bool
    {
        $symbol = $this->normalizeFuturesAlias($symbol);
        if ($symbol === '') {
            return false;
        }

        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();
        $fromDate = CarbonImmutable::parse($fromStr);
        $toDate = CarbonImmutable::parse($toStr);

        $existing = StockDailyBar::query()
            ->where('symbol', $symbol)
            ->whereBetween('bar_date', [$fromStr, $toStr])
            ->orderBy('bar_date')
            ->get();

        if ($existing->isEmpty()) {
            return ! $this->hasQueriedBarInterval($symbol, $fromDate, $toDate);
        }

        $firstStored = CarbonImmutable::parse($existing->first()->bar_date);
        $lastStored = CarbonImmutable::parse($existing->last()->bar_date);

        if ($fromDate->lessThan($firstStored) && $this->hasTradingDays($fromDate, $firstStored->subDay(), $symbol)) {
            if (! $this->hasQueriedBarInterval($symbol, $fromDate, $firstStored->subDay())) {
                return true;
            }
        }

        for ($i = 0; $i < $existing->count() - 1; $i++) {
            $currDate = CarbonImmutable::parse($existing[$i]->bar_date);
            $nextDate = CarbonImmutable::parse($existing[$i + 1]->bar_date);
            $gapStart = $currDate->addDay();
            $gapEnd = $nextDate->subDay();

            if ($gapStart->lessThanOrEqualTo($gapEnd) && $this->hasTradingDays($gapStart, $gapEnd, $symbol)) {
                if (! $this->hasQueriedBarInterval($symbol, $gapStart, $gapEnd)) {
                    return true;
                }
            }
        }

        $unfinishedLast = $this->shouldRefreshUnfinishedDay($existing->last());
        if (($toDate->greaterThan($lastStored) && $this->hasTradingDays($lastStored->addDay(), $toDate, $symbol)) || $unfinishedLast) {
            $gapStart = $unfinishedLast ? $lastStored : $lastStored->addDay();
            if ($gapStart->lessThanOrEqualTo($toDate)) {
                if (! $this->hasQueriedBarInterval($symbol, $gapStart, $toDate)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function historicalDailyBars(string $symbol, CarbonInterface $from, CarbonInterface $to): array
    {
        $symbol = $this->normalizeFuturesAlias($symbol);
        if ($symbol === '') {
            return [];
        }

        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();
        $v = self::getCacheVersion('bars', $symbol);
        $cacheKey = "duoasset.v{$v}.bars.{$symbol}.{$fromStr}.{$toStr}";

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            // Memory caching must not prevent unfinished or unfinalized trading days from refreshing
            $needsRefresh = false;
            $toNy = CarbonImmutable::parse($toStr, 'America/New_York');
            if ($toNy->greaterThanOrEqualTo(today('America/New_York'))) {
                $lastBar = StockDailyBar::where('symbol', $symbol)->where('bar_date', $toStr)->first();
                if (! $lastBar || $this->shouldRefreshUnfinishedDay($lastBar)) {
                    $needsRefresh = true;
                }
            } elseif (! empty($cached)) {
                $lastCachedDate = $cached[count($cached) - 1]['date'] ?? null;
                if ($lastCachedDate) {
                    $lastBar = StockDailyBar::where('symbol', $symbol)->where('bar_date', $lastCachedDate)->first();
                    if ($lastBar && $this->shouldRefreshUnfinishedDay($lastBar)) {
                        $needsRefresh = true;
                    }
                }
            }

            if (! $needsRefresh) {
                return $cached;
            }
        }

        $fromDate = CarbonImmutable::parse($fromStr);
        $toDate = CarbonImmutable::parse($toStr);

        // 1. Query existing bars from authoritative database
        $existing = StockDailyBar::query()
            ->where('symbol', $symbol)
            ->whereBetween('bar_date', [$fromStr, $toStr])
            ->orderBy('bar_date')
            ->get();

        if ($existing->isEmpty()) {
            if (! $this->hasQueriedBarInterval($symbol, $fromDate, $toDate)) {
                $this->fetchAndStoreBars($symbol, $fromDate, $toDate);
            }
        } else {
            $firstStored = CarbonImmutable::parse($existing->first()->bar_date);
            $lastStored = CarbonImmutable::parse($existing->last()->bar_date);

            // 1. Earlier gap (before first stored bar)
            if ($fromDate->lessThan($firstStored)) {
                $gapEnd = $firstStored->subDay();
                if (! $this->hasQueriedBarInterval($symbol, $fromDate, $gapEnd)) {
                    $this->fetchAndStoreBars($symbol, $fromDate, $gapEnd);
                }
            }

            // 2. Middle gaps (between consecutive stored bars)
            for ($i = 0; $i < $existing->count() - 1; $i++) {
                $currDate = CarbonImmutable::parse($existing[$i]->bar_date);
                $nextDate = CarbonImmutable::parse($existing[$i + 1]->bar_date);
                $gapStart = $currDate->addDay();
                $gapEnd = $nextDate->subDay();

                if ($gapStart->lessThanOrEqualTo($gapEnd) && $this->hasTradingDays($gapStart, $gapEnd, $symbol)) {
                    if (! $this->hasQueriedBarInterval($symbol, $gapStart, $gapEnd)) {
                        $this->fetchAndStoreBars($symbol, $gapStart, $gapEnd);
                    }
                }
            }

            // 3. Later gap (after last stored bar, or refresh unfinished/unfinalized trading day)
            $unfinishedLast = $existing->isNotEmpty() && $this->shouldRefreshUnfinishedDay($existing->last());
            if ($toDate->greaterThan($lastStored) || $unfinishedLast) {
                $gapStart = $unfinishedLast ? $lastStored : $lastStored->addDay();
                if ($gapStart->lessThanOrEqualTo($toDate)) {
                    if (! $this->hasQueriedBarInterval($symbol, $gapStart, $toDate)) {
                        $this->fetchAndStoreBars($symbol, $gapStart, $toDate);
                    }
                }
            }
        }

        // 2. Return merged ascending series from authoritative database
        $merged = StockDailyBar::query()
            ->where('symbol', $symbol)
            ->whereBetween('bar_date', [$fromStr, $toStr])
            ->orderBy('bar_date')
            ->get()
            ->map(fn (StockDailyBar $b) => [
                'date' => $b->bar_date->toDateString(),
                'open' => (float) $b->open,
                'high' => (float) $b->high,
                'low' => (float) $b->low,
                'close' => (float) $b->close,
                'adj_close' => $b->adj_close !== null ? (float) $b->adj_close : null,
                'volume' => (int) $b->volume,
            ])
            ->values()
            ->all();

        if (! empty($merged)) {
            $containsUnfinished = $toDate->greaterThanOrEqualTo(today('America/New_York'))
                || ($existing->isNotEmpty() && $this->shouldRefreshUnfinishedDay($existing->last()));

            if ($containsUnfinished) {
                $tradingService = app(TradingSessionService::class);
                $isTrading = $tradingService->isTradingHours(TradingSessionService::DEFAULT_TIMEZONE);
                $ttl = $isTrading ? now()->addMinutes(15) : now()->addHours(1);
                Cache::put($cacheKey, $merged, $ttl);
            } else {
                Cache::put($cacheKey, $merged, now()->addHours(12));
            }
        }

        return $merged;
    }

    protected function shouldRefreshUnfinishedDay(?StockDailyBar $bar): bool
    {
        if (! $bar) {
            return false;
        }

        $barDate = CarbonImmutable::parse($bar->bar_date->toDateString(), 'America/New_York')->startOfDay();
        $nyNow = now('America/New_York');
        $today = $nyNow->startOfDay();

        if ($barDate->greaterThan($today)) {
            return false;
        }

        if (! $bar->retrieved_at) {
            return true;
        }

        $retrievedNy = $bar->retrieved_at->copy()->setTimezone('America/New_York');
        $barSessionClose = $barDate->setTime(16, 15, 0); // 4:15 PM Eastern

        // 1. Current trading day
        if ($barDate->equalTo($today)) {
            $tradingService = app(TradingSessionService::class);
            $isTrading = $tradingService->isTradingHours(TradingSessionService::DEFAULT_TIMEZONE);

            // During trading hours, allow refreshing if older than 15 minutes
            if ($isTrading) {
                return $bar->retrieved_at->diffInMinutes(now()) >= 15;
            }

            // Outside trading hours on today:
            // If market close has passed and retrieved before close, refresh to finalize EOD bar
            if ($nyNow->greaterThanOrEqualTo($barSessionClose) && $retrievedNy->lessThan($barSessionClose)) {
                return true;
            }

            return $bar->retrieved_at->diffInMinutes(now()) >= 15;
        }

        // 2. Previous trading day(s) (e.g. yesterday):
        // If retrieved on or before its date prior to session close, it was only a preliminary candle and must finalize
        if ($retrievedNy->lessThan($barSessionClose)) {
            return true;
        }

        return false;
    }

    public function isMarketHoliday(CarbonInterface $date, string $symbol = ''): bool
    {
        $dt = CarbonImmutable::parse($date->toDateString(), 'America/New_York');
        $year = $dt->year;

        if ($dt->isWeekend()) {
            return false;
        }

        $isCanadian = str_ends_with(strtoupper($symbol), '.TO') || str_ends_with(strtoupper($symbol), '.V');

        // Easter-dependent holidays (Good Friday)
        $easterTimestamp = easter_date($year);
        $easter = CarbonImmutable::createFromTimestamp($easterTimestamp, 'America/New_York');
        $goodFriday = $easter->subDays(2);
        if ($dt->isSameDay($goodFriday)) {
            return true;
        }

        if ($isCanadian) {
            // Easter Monday
            if ($dt->isSameDay($easter->addDay())) {
                return true;
            }
            // Victoria Day: Monday before May 25
            $may25 = CarbonImmutable::create($year, 5, 25, 0, 0, 0, 'America/New_York');
            $victoriaDay = $may25->previous(\Carbon\CarbonInterface::MONDAY);
            if ($dt->isSameDay($victoriaDay)) {
                return true;
            }
            // Canada Day: July 1 (observed July 2 if Sunday)
            $canadaDay = CarbonImmutable::create($year, 7, 1, 0, 0, 0, 'America/New_York');
            if ($canadaDay->isSunday()) {
                $canadaDay = $canadaDay->addDay();
            }
            if ($dt->isSameDay($canadaDay)) {
                return true;
            }
            // Civic Holiday: 1st Monday in August
            $firstAug = CarbonImmutable::create($year, 8, 1, 0, 0, 0, 'America/New_York');
            $civic = $firstAug->isMonday() ? $firstAug : $firstAug->next(\Carbon\CarbonInterface::MONDAY);
            if ($dt->isSameDay($civic)) {
                return true;
            }
            // Labour Day: 1st Monday in September
            $firstSep = CarbonImmutable::create($year, 9, 1, 0, 0, 0, 'America/New_York');
            $labour = $firstSep->isMonday() ? $firstSep : $firstSep->next(\Carbon\CarbonInterface::MONDAY);
            if ($dt->isSameDay($labour)) {
                return true;
            }
            // Thanksgiving (Canada): 2nd Monday in October
            $firstOct = CarbonImmutable::create($year, 10, 1, 0, 0, 0, 'America/New_York');
            $firstOctMon = $firstOct->isMonday() ? $firstOct : $firstOct->next(\Carbon\CarbonInterface::MONDAY);
            $thanksgivingCa = $firstOctMon->addWeek();
            if ($dt->isSameDay($thanksgivingCa)) {
                return true;
            }
            // Christmas (Dec 25) & Boxing Day (Dec 26)
            $xmas = CarbonImmutable::create($year, 12, 25, 0, 0, 0, 'America/New_York');
            if ($xmas->isSaturday()) {
                $xmas = $xmas->subDay();
            } elseif ($xmas->isSunday()) {
                $xmas = $xmas->addDay();
            }
            if ($dt->isSameDay($xmas)) {
                return true;
            }
            $boxing = CarbonImmutable::create($year, 12, 26, 0, 0, 0, 'America/New_York');
            if ($boxing->isSunday()) {
                $boxing = $boxing->addDay();
            }
            if ($dt->isSameDay($boxing)) {
                return true;
            }

            return false;
        }

        // US Market Holidays (NYSE / NASDAQ)
        // 1. New Year's Day (Jan 1, observed Jan 2 if Sun)
        $nyd = CarbonImmutable::create($year, 1, 1, 0, 0, 0, 'America/New_York');
        if ($nyd->isSunday()) {
            $nyd = $nyd->addDay();
        }
        if ($dt->isSameDay($nyd)) {
            return true;
        }

        // 2. Martin Luther King Jr. Day (3rd Monday in January)
        $firstJan = CarbonImmutable::create($year, 1, 1, 0, 0, 0, 'America/New_York');
        $firstJanMon = $firstJan->isMonday() ? $firstJan : $firstJan->next(\Carbon\CarbonInterface::MONDAY);
        $mlk = $firstJanMon->addWeeks(2);
        if ($dt->isSameDay($mlk)) {
            return true;
        }

        // 3. Washington's Birthday / Presidents' Day (3rd Monday in February)
        $firstFeb = CarbonImmutable::create($year, 2, 1, 0, 0, 0, 'America/New_York');
        $firstFebMon = $firstFeb->isMonday() ? $firstFeb : $firstFeb->next(\Carbon\CarbonInterface::MONDAY);
        $presidents = $firstFebMon->addWeeks(2);
        if ($dt->isSameDay($presidents)) {
            return true;
        }

        // 4. Memorial Day (last Monday in May)
        $may31 = CarbonImmutable::create($year, 5, 31, 0, 0, 0, 'America/New_York');
        $memorial = $may31->isMonday() ? $may31 : $may31->previous(\Carbon\CarbonInterface::MONDAY);
        if ($dt->isSameDay($memorial)) {
            return true;
        }

        // 5. Juneteenth National Independence Day (June 19, starting 2021)
        if ($year >= 2021) {
            $juneteenth = CarbonImmutable::create($year, 6, 19, 0, 0, 0, 'America/New_York');
            if ($juneteenth->isSaturday()) {
                $juneteenth = $juneteenth->subDay();
            } elseif ($juneteenth->isSunday()) {
                $juneteenth = $juneteenth->addDay();
            }
            if ($dt->isSameDay($juneteenth)) {
                return true;
            }
        }

        // 6. Independence Day (July 4)
        $july4 = CarbonImmutable::create($year, 7, 4, 0, 0, 0, 'America/New_York');
        if ($july4->isSaturday()) {
            $july4 = $july4->subDay();
        } elseif ($july4->isSunday()) {
            $july4 = $july4->addDay();
        }
        if ($dt->isSameDay($july4)) {
            return true;
        }

        // 7. Labor Day (1st Monday in September)
        $firstSep = CarbonImmutable::create($year, 9, 1, 0, 0, 0, 'America/New_York');
        $labor = $firstSep->isMonday() ? $firstSep : $firstSep->next(\Carbon\CarbonInterface::MONDAY);
        if ($dt->isSameDay($labor)) {
            return true;
        }

        // 8. Thanksgiving Day (4th Thursday in November)
        $firstNov = CarbonImmutable::create($year, 11, 1, 0, 0, 0, 'America/New_York');
        $firstNovThu = $firstNov->isThursday() ? $firstNov : $firstNov->next(\Carbon\CarbonInterface::THURSDAY);
        $thanksgiving = $firstNovThu->addWeeks(3);
        if ($dt->isSameDay($thanksgiving)) {
            return true;
        }

        // 9. Christmas Day (Dec 25)
        $xmas = CarbonImmutable::create($year, 12, 25, 0, 0, 0, 'America/New_York');
        if ($xmas->isSaturday()) {
            $xmas = $xmas->subDay();
        } elseif ($xmas->isSunday()) {
            $xmas = $xmas->addDay();
        }
        if ($dt->isSameDay($xmas)) {
            return true;
        }

        return false;
    }

    public function hasTradingDays(CarbonInterface $from, CarbonInterface $to, string $symbol = ''): bool
    {
        $curr = CarbonImmutable::parse($from->toDateString(), 'America/New_York');
        $end = CarbonImmutable::parse($to->toDateString(), 'America/New_York');

        while ($curr->lessThanOrEqualTo($end)) {
            if (! $curr->isWeekend() && ! $this->isMarketHoliday($curr, $symbol)) {
                return true;
            }
            $curr = $curr->addDay();
        }

        return false;
    }

    protected function hasWeekdays(CarbonInterface $from, CarbonInterface $to): bool
    {
        return $this->hasTradingDays($from, $to);
    }

    protected function hasQueriedBarInterval(string $symbol, CarbonInterface $from, CarbonInterface $to): bool
    {
        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();
        $exactPeriod = $fromStr.'_'.$toStr;

        $payload = MarketDataPayload::where('symbol', $symbol)
            ->where('data_type', 'bars')
            ->where('period', $exactPeriod)
            ->first();

        if (! $payload) {
            $candidates = MarketDataPayload::where('symbol', $symbol)
                ->where('data_type', 'bars')
                ->where('period', 'like', '%_%')
                ->get();

            foreach ($candidates as $cand) {
                $parts = explode('_', $cand->period);
                if (count($parts) === 2 && $parts[0] <= $fromStr && $parts[1] >= $toStr) {
                    $payload = $cand;
                    break;
                }
            }
        }

        if (! $payload) {
            return false;
        }

        $nyNow = now('America/New_York');
        $toNy = CarbonImmutable::parse($to->toDateString(), 'America/New_York');
        $toSessionClose = $toNy->setTime(16, 15, 0);

        // If the interval includes today or future: allow refreshing if older than 15 minutes
        if ($toNy->greaterThanOrEqualTo($nyNow->startOfDay())) {
            return $payload->retrieved_at && $payload->retrieved_at->diffInMinutes(now()) < 15;
        }

        // If it was retrieved before session close on that day, and now is after session close,
        // it was preliminary and must be refreshed to finalize EOD bar
        if ($payload->retrieved_at && $payload->retrieved_at->copy()->setTimezone('America/New_York')->lessThan($toSessionClose) && $nyNow->greaterThanOrEqualTo($toSessionClose)) {
            return false;
        }

        // If the payload was empty (e.g. holiday, weekend, missing data),
        // do not permanently prevent retrieval. Allow re-checking after 24 hours.
        if (empty($payload->payload)) {
            return $payload->retrieved_at && $payload->retrieved_at->diffInHours(now()) < 24;
        }

        return true;
    }

    protected function fetchAndStoreBars(string $symbol, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($from->greaterThan($to)) {
            return [];
        }

        try {
            $fresh = $this->upstream->historicalDailyBars($symbol, $from, $to);
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.bars_fetch_failed', [
                'symbol' => $symbol,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $now = now();
        if (! empty($fresh)) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($fresh, $symbol, $now) {
                foreach ($fresh as $row) {
                    if (empty($row['date'])) {
                        continue;
                    }

                    // Filter out nulls to preserve previously stored historical prices
                    $attributes = array_filter([
                        'open' => $row['open'] ?? null,
                        'high' => $row['high'] ?? null,
                        'low' => $row['low'] ?? null,
                        'close' => $row['close'] ?? null,
                        'adj_close' => $row['adj_close'] ?? null,
                        'volume' => $row['volume'] ?? null,
                        'retrieved_at' => $now,
                    ], fn ($val) => $val !== null);

                    StockDailyBar::updateOrCreate(
                        ['symbol' => $symbol, 'bar_date' => $row['date']],
                        $attributes
                    );
                }
            });
        }

        // Archive raw payload for this queried interval only when bars were returned
        // or when the upstream request succeeded without provider errors (e.g. market holidays).
        // Never persist an empty bars payload when upstream failed with HTTP 401/402/403/429/5xx,
        // as doing so would suppress retries for 24 hours via hasQueriedBarInterval().
        if (! empty($fresh) || empty($this->lastErrors())) {
            MarketDataPayload::updateOrCreate(
                [
                    'symbol' => $symbol,
                    'data_type' => 'bars',
                    'period' => $from->toDateString().'_'.$to->toDateString(),
                ],
                [
                    'payload' => $fresh ?: [],
                    'retrieved_at' => $now,
                ]
            );
        }

        return $fresh;
    }

    public function profile(string $symbol): ?array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return null;
        }

        $v = self::getCacheVersion('profile', $symbol);
        $cacheKey = "duoasset.v{$v}.profile.{$symbol}";
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        // Check authoritative database
        $stored = CompanyProfile::where('symbol', $symbol)->first();
        if ($stored && $stored->retrieved_at && $stored->retrieved_at->diffInHours(now()) < 24) {
            $result = $this->getCompleteStoredProfile($symbol, $stored);
            $remainingSeconds = max(1, 86400 - $stored->retrieved_at->diffInSeconds(now()));
            Cache::put($cacheKey, $result, now()->addSeconds($remainingSeconds));

            return $result;
        }

        // Fetch from upstream if missing or older than 24 hours
        try {
            $fresh = $this->upstream->profile($symbol);
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.profile_fetch_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            $fresh = null;
        }

        if (is_array($fresh) && ! empty($fresh)) {
            $now = now();
            $fresh['is_actively_trading'] = $this->toBool($fresh['is_actively_trading'] ?? $fresh['isActivelyTrading'] ?? null);
            $fresh['is_etf'] = $this->toBool($fresh['is_etf'] ?? $fresh['isEtf'] ?? null);
            $fresh['is_fund'] = $this->toBool($fresh['is_fund'] ?? $fresh['isFund'] ?? null);
            $fresh['is_adr'] = $this->toBool($fresh['is_adr'] ?? $fresh['isAdr'] ?? null);
            $fresh['type'] = $fresh['type'] ?? $fresh['security_type'] ?? null;

            $freshShares = isset($fresh['shares_outstanding']) && is_numeric($fresh['shares_outstanding']) && (int) $fresh['shares_outstanding'] > 0
                ? (int) $fresh['shares_outstanding']
                : null;
            if ($freshShares === null) {
                $freshCap = isset($fresh['market_cap']) && is_numeric($fresh['market_cap']) ? (float) $fresh['market_cap'] : null;
                $freshPrice = isset($fresh['price']) && is_numeric($fresh['price']) ? (float) $fresh['price'] : null;
                if ($freshCap !== null && $freshCap > 0 && $freshPrice !== null && $freshPrice > 0) {
                    $derived = (int) round($freshCap / $freshPrice);
                    $freshShares = $derived > 0 ? $derived : null;
                } elseif ($stored && $stored->shares_outstanding !== null && (int) $stored->shares_outstanding > 0) {
                    $freshShares = (int) $stored->shares_outstanding;
                }
                if ($freshShares !== null) {
                    $fresh['shares_outstanding'] = $freshShares;
                }
            }

            $nonNullFields = CompanyProfile::filterPersistedAttributes(array_filter([
                'company_name' => $fresh['name'] ?? $fresh['company_name'] ?? null,
                'exchange' => $fresh['exchange'] ?? null,
                'currency' => $fresh['currency'] ?? null,
                'country' => $fresh['country'] ?? null,
                'sector' => $fresh['sector'] ?? null,
                'industry' => $fresh['industry'] ?? null,
                'sub_industry' => $fresh['sub_industry'] ?? null,
                'market_cap' => $fresh['market_cap'] ?? null,
                'price' => $fresh['price'] ?? null,
                'shares_outstanding' => $fresh['shares_outstanding'] ?? null,
                'float_shares' => $fresh['float_shares'] ?? null,
                'free_float' => $fresh['free_float'] ?? null,
                'is_actively_trading' => $fresh['is_actively_trading'],
                'is_etf' => $fresh['is_etf'],
                'is_fund' => $fresh['is_fund'],
                'is_adr' => $fresh['is_adr'],
                'type' => $fresh['type'],
            ], fn ($val) => $val !== null && $val !== ''));

            if ($stored) {
                $stored->update(array_merge($nonNullFields, ['retrieved_at' => $now]));
            } else {
                CompanyProfile::create(array_merge(['symbol' => $symbol], $nonNullFields, ['retrieved_at' => $now]));
            }

            // Latest-value caching
            MarketDataPayload::updateOrCreate(
                ['symbol' => $symbol, 'data_type' => 'profile', 'period' => 'latest'],
                ['payload' => $fresh, 'retrieved_at' => $now]
            );

            // Backward compatibility for existing queries
            MarketDataPayload::updateOrCreate(
                ['symbol' => $symbol, 'data_type' => 'profile', 'period' => 'default'],
                ['payload' => $fresh, 'retrieved_at' => $now]
            );

            // Historical archival snapshot so older snapshots aren't unintentionally lost (immutable)
            MarketDataPayload::firstOrCreate(
                ['symbol' => $symbol, 'data_type' => 'profile', 'period' => 'snapshot_'.$now->toDateString()],
                ['payload' => $fresh, 'retrieved_at' => $now]
            );

            Cache::put($cacheKey, $fresh, now()->addHours(24));

            return $fresh;
        }

        // If upstream failed, fallback to existing stored profile even if older than 24h
        if ($stored) {
            return $this->getCompleteStoredProfile($symbol, $stored);
        }

        return null;
    }

    protected function getCompleteStoredProfile(string $symbol, CompanyProfile $stored): array
    {
        $rawPayload = MarketDataPayload::where('symbol', $symbol)
            ->where('data_type', 'profile')
            ->whereIn('period', ['latest', 'default'])
            ->orderByDesc('retrieved_at')
            ->first();

        if ($rawPayload && is_array($rawPayload->payload) && ! empty($rawPayload->payload)) {
            $data = $rawPayload->payload;
            if ($stored->price !== null) {
                $data['price'] = (float) $stored->price;
            }
            if ($stored->market_cap !== null) {
                $data['market_cap'] = (int) $stored->market_cap;
                if (isset($data['mktCap'])) {
                    $data['mktCap'] = (int) $stored->market_cap;
                }
            }
            if (($data['shares_outstanding'] ?? null) === null || (int) $data['shares_outstanding'] <= 0) {
                if ($stored->shares_outstanding !== null && (int) $stored->shares_outstanding > 0) {
                    $data['shares_outstanding'] = (int) $stored->shares_outstanding;
                } elseif (isset($data['market_cap'], $data['price']) && (float) $data['market_cap'] > 0 && (float) $data['price'] > 0) {
                    $derived = (int) round((float) $data['market_cap'] / (float) $data['price']);
                    if ($derived > 0) {
                        $data['shares_outstanding'] = $derived;
                    }
                }
            }
            if (($data['float_shares'] ?? null) === null && $stored->float_shares !== null) {
                $data['float_shares'] = (int) $stored->float_shares;
            }
            if (($data['free_float'] ?? null) === null && $stored->free_float !== null) {
                $data['free_float'] = (float) $stored->free_float;
            }
            if (empty($data['currency']) && ! empty($stored->currency)) {
                $data['currency'] = $stored->currency;
            }
            if (! array_key_exists('is_actively_trading', $data)) {
                $data['is_actively_trading'] = $stored->is_actively_trading !== null ? (bool) $stored->is_actively_trading : null;
            }
            if (! array_key_exists('is_etf', $data)) {
                $data['is_etf'] = $stored->is_etf !== null ? (bool) $stored->is_etf : null;
            }
            if (! array_key_exists('is_fund', $data)) {
                $data['is_fund'] = $stored->is_fund !== null ? (bool) $stored->is_fund : null;
            }
            if (! array_key_exists('is_adr', $data)) {
                $data['is_adr'] = $stored->is_adr !== null ? (bool) $stored->is_adr : null;
            }
            if (! array_key_exists('type', $data)) {
                $data['type'] = $stored->type;
            }

            return $data;
        }

        return $this->formatStoredProfile($stored);
    }

    protected function formatStoredProfile(CompanyProfile $stored): array
    {
        $shares = ($stored->shares_outstanding !== null && (int) $stored->shares_outstanding > 0)
            ? (int) $stored->shares_outstanding
            : null;
        if ($shares === null && $stored->market_cap !== null && (float) $stored->market_cap > 0 && $stored->price !== null && (float) $stored->price > 0) {
            $derived = (int) round((float) $stored->market_cap / (float) $stored->price);
            $shares = $derived > 0 ? $derived : null;
        }

        return [
            'symbol' => $stored->symbol,
            'name' => $stored->company_name ?? '',
            'company_name' => $stored->company_name ?? '',
            'exchange' => $stored->exchange ?? '',
            'currency' => $stored->currency ?? '',
            'country' => $stored->country ?? '',
            'sector' => $stored->sector ?? '',
            'industry' => $stored->industry ?? '',
            'sub_industry' => $stored->sub_industry ?? '',
            'market_cap' => $stored->market_cap !== null ? (int) $stored->market_cap : null,
            'price' => $stored->price !== null ? (float) $stored->price : null,
            'shares_outstanding' => $shares,
            'float_shares' => $stored->float_shares !== null ? (int) $stored->float_shares : null,
            'free_float' => $stored->free_float !== null ? (float) $stored->free_float : null,
            'is_actively_trading' => $stored->is_actively_trading !== null ? (bool) $stored->is_actively_trading : null,
            'is_etf' => $stored->is_etf !== null ? (bool) $stored->is_etf : null,
            'is_fund' => $stored->is_fund !== null ? (bool) $stored->is_fund : null,
            'is_adr' => $stored->is_adr !== null ? (bool) $stored->is_adr : null,
            'type' => $stored->type,
        ];
    }

    public function quote(string $symbol): ?array
    {
        $symbol = $this->normalizeFuturesAlias($symbol);
        if ($symbol === '') {
            return null;
        }

        $v = self::getCacheVersion('quote', $symbol);
        $cacheKey = "duoasset.v{$v}.quote.{$symbol}";
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $storedPayload = MarketDataPayload::where('symbol', $symbol)
            ->where('data_type', 'quote')
            ->where('period', 'latest')
            ->first();

        $tradingService = app(TradingSessionService::class);
        $isTrading = $tradingService->isTradingHours(TradingSessionService::DEFAULT_TIMEZONE);
        $freshnessSeconds = $isTrading ? 60 : 900;

        if ($storedPayload && is_array($storedPayload->payload) && $storedPayload->retrieved_at) {
            $ageSeconds = $storedPayload->retrieved_at->diffInSeconds(now());
            if ($ageSeconds < $freshnessSeconds) {
                $remainingSeconds = max(1, $freshnessSeconds - $ageSeconds);
                Cache::put($cacheKey, $storedPayload->payload, now()->addSeconds($remainingSeconds));

                return $storedPayload->payload;
            }
        }

        // Fetch fresh quote from upstream
        try {
            $quote = $this->upstream->quote($symbol);
            if (is_array($quote) && ! empty($quote)) {
                $now = now();
                MarketDataPayload::updateOrCreate(
                    ['symbol' => $symbol, 'data_type' => 'quote', 'period' => 'latest'],
                    ['payload' => $quote, 'retrieved_at' => $now]
                );

                // Immutable historical snapshot
                MarketDataPayload::firstOrCreate(
                    ['symbol' => $symbol, 'data_type' => 'quote', 'period' => 'snapshot_'.$now->toDateString()],
                    ['payload' => $quote, 'retrieved_at' => $now]
                );

                if (isset($quote['price']) || isset($quote['market_cap'])) {
                    $quoteFields = array_filter([
                        'price' => $quote['price'] ?? null,
                        'market_cap' => $quote['market_cap'] ?? null,
                    ], fn ($val) => $val !== null && $val !== '');

                    if (! empty($quoteFields)) {
                        // Do NOT update retrieved_at on CompanyProfile!
                        // CompanyProfile.retrieved_at strictly tracks company profile freshness.
                        CompanyProfile::where('symbol', $symbol)->update($quoteFields);
                    }
                }

                Cache::put($cacheKey, $quote, now()->addSeconds($freshnessSeconds));

                return $quote;
            }
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.quote_fetch_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback to stored quote payload or profile
        if ($storedPayload && is_array($storedPayload->payload)) {
            return $storedPayload->payload;
        }

        $storedProfile = CompanyProfile::where('symbol', $symbol)->first();
        if ($storedProfile) {
            return [
                'symbol' => $storedProfile->symbol,
                'price' => $storedProfile->price !== null ? (float) $storedProfile->price : null,
                'market_cap' => $storedProfile->market_cap,
                'exchange' => $storedProfile->exchange,
                'company_name' => $storedProfile->company_name,
            ];
        }

        return null;
    }

    /**
     * Retrieve multiple quotes keyed by uppercase symbol, using batch cache/DB lookup
     * and isolating per-symbol errors.
     *
     * @param  list<string>  $symbols
     * @return array<string, array<string, mixed>|null>
     */
    public function quotes(array $symbols): array
    {
        $unique = array_values(array_unique(array_filter(array_map(fn ($s) => strtoupper(trim((string) $s)), $symbols))));
        $out = [];
        foreach ($unique as $sym) {
            try {
                $out[$sym] = $this->quote($sym);
            } catch (Throwable) {
                $out[$sym] = null;
            }
        }

        return $out;
    }

    protected function getFinancialRefreshAttempt(string $symbol, string $statementType): ?MarketDataPayload
    {
        return MarketDataPayload::where('symbol', "REFRESH_{$symbol}")
            ->where('data_type', "refresh_{$statementType}")
            ->where('period', 'latest')
            ->first();
    }

    protected function recordFinancialRefreshAttempt(string $symbol, string $statementType, bool $success, int $count, int $requestedLimit = 8): void
    {
        $prev = $this->getFinancialRefreshAttempt($symbol, $statementType);
        $prevData = $prev && is_array($prev->payload) ? $prev->payload : [];
        $prevLimit = (int) ($prevData['requested_limit'] ?? 0);

        MarketDataPayload::updateOrCreate(
            [
                'symbol' => "REFRESH_{$symbol}",
                'data_type' => "refresh_{$statementType}",
                'period' => 'latest',
            ],
            [
                'payload' => [
                    'attempted_at' => now()->toDateTimeString(),
                    'success' => $success,
                    'count' => $count,
                    'requested_limit' => max($requestedLimit, $prevLimit),
                    'has_no_history' => $success && $count === 0,
                    'has_reached_history_limit' => $success && $count < $requestedLimit,
                ],
                'retrieved_at' => now(),
            ]
        );
    }

    public function quarterlyIncomeStatements(string $symbol, int $limit = 8): array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return [];
        }

        if (! $this->isFinancialStorageEnabled()) {
            return $this->fetchUpstreamIncomeStatementsWithoutStorage($symbol, $limit);
        }

        $existing = CompanyFinancial::query()
            ->where('symbol', $symbol)
            ->where('statement_type', CompanyFinancial::TYPE_INCOME)
            ->orderByDesc('fiscal_date')
            ->take($limit)
            ->get();

        $lastAttempt = $this->getFinancialRefreshAttempt($symbol, CompanyFinancial::TYPE_INCOME);
        $attemptData = $lastAttempt && is_array($lastAttempt->payload) ? $lastAttempt->payload : null;
        $attemptedRecently = $lastAttempt && $lastAttempt->retrieved_at && $lastAttempt->retrieved_at->diffInHours(now()) < 24;
        $attemptFailedRecently = $attemptData && ! ($attemptData['success'] ?? true) && $lastAttempt && $lastAttempt->retrieved_at && $lastAttempt->retrieved_at->diffInHours(now()) < 1;

        $hasNoHistory = $attemptData && ($attemptData['has_no_history'] ?? false);
        if ($hasNoHistory && $attemptedRecently) {
            return [];
        }

        $prevRequestedLimit = (int) ($attemptData['requested_limit'] ?? 0);
        $hasReachedHistoryLimit = (bool) ($attemptData['has_reached_history_limit'] ?? false);

        $coverageSufficient = $existing->count() >= $limit
            || ($limit <= $prevRequestedLimit && ($attemptData['success'] ?? false))
            || ($hasReachedHistoryLimit && ($attemptData['success'] ?? false));

        $isFresh = $existing->isNotEmpty() && $coverageSufficient && (
            ($existing->count() >= $limit && $existing->first()->retrieved_at && $existing->first()->retrieved_at->diffInHours(now()) < 24)
            || ($attemptedRecently && ($attemptData['success'] ?? false))
            || $attemptFailedRecently
        );

        if ($isFresh) {
            return $existing->map(fn (CompanyFinancial $f) => [
                'date' => $f->fiscal_date->toDateString(),
                'revenue' => $f->revenue !== null ? (float) $f->revenue : null,
                'net_income' => $f->net_income !== null ? (float) $f->net_income : null,
                'eps' => $f->eps !== null ? (float) $f->eps : null,
                'operating_income' => isset($f->raw_metrics['operatingIncome']) && is_numeric($f->raw_metrics['operatingIncome'])
                    ? (float) $f->raw_metrics['operatingIncome']
                    : (isset($f->raw_metrics['operating_income']) && is_numeric($f->raw_metrics['operating_income']) ? (float) $f->raw_metrics['operating_income'] : null),
                'fiscal_year' => $f->fiscal_year ?? (isset($f->raw_metrics['fiscalYear']) && is_numeric($f->raw_metrics['fiscalYear']) ? (int) $f->raw_metrics['fiscalYear'] : null),
                'period' => $f->period !== 'quarter' ? $f->period : ($f->raw_metrics['period'] ?? $f->period),
                'filing_date' => $f->raw_metrics['fillingDate'] ?? $f->raw_metrics['filingDate'] ?? null,
                'reported_currency' => $f->reported_currency ?? ($f->raw_metrics['reportedCurrency'] ?? null),
                'raw' => $f->raw_metrics ?? [],
            ])->all();
        }

        // Fetch from upstream to discover new reports or backfill
        $success = false;
        try {
            $fresh = $this->upstream->quarterlyIncomeStatements($symbol, $limit);
            $success = true;
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.income_fetch_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            $fresh = [];
            $success = false;
        }

        $now = now();
        $this->recordFinancialRefreshAttempt($symbol, CompanyFinancial::TYPE_INCOME, $success, count($fresh), $limit);

        if (! empty($fresh)) {
            foreach ($fresh as $row) {
                if (empty($row['date'])) {
                    continue;
                }

                CompanyFinancial::updateOrCreate(
                    [
                        'symbol' => $symbol,
                        'statement_type' => CompanyFinancial::TYPE_INCOME,
                        'fiscal_date' => $row['date'],
                        'period' => $row['period'] ?? 'quarter',
                    ],
                    [
                        'revenue' => $row['revenue'] ?? null,
                        'net_income' => $row['net_income'] ?? null,
                        'eps' => $row['eps'] ?? null,
                        'fiscal_year' => isset($row['fiscal_year']) ? (int) $row['fiscal_year'] : (isset($row['fiscalYear']) ? (int) $row['fiscalYear'] : null),
                        'reported_currency' => $row['reported_currency'] ?? ($row['raw']['reportedCurrency'] ?? null),
                        'raw_metrics' => $row['raw'] ?? $row,
                        'retrieved_at' => $now,
                    ]
                );
            }

            MarketDataPayload::updateOrCreate(
                ['symbol' => $symbol, 'data_type' => 'income', 'period' => "limit_{$limit}"],
                ['payload' => $fresh, 'retrieved_at' => $now]
            );
        }

        return CompanyFinancial::query()
            ->where('symbol', $symbol)
            ->where('statement_type', CompanyFinancial::TYPE_INCOME)
            ->orderByDesc('fiscal_date')
            ->take($limit)
            ->get()
            ->map(fn (CompanyFinancial $f) => [
                'date' => $f->fiscal_date->toDateString(),
                'revenue' => $f->revenue !== null ? (float) $f->revenue : null,
                'net_income' => $f->net_income !== null ? (float) $f->net_income : null,
                'eps' => $f->eps !== null ? (float) $f->eps : null,
                'operating_income' => isset($f->raw_metrics['operatingIncome']) && is_numeric($f->raw_metrics['operatingIncome'])
                    ? (float) $f->raw_metrics['operatingIncome']
                    : (isset($f->raw_metrics['operating_income']) && is_numeric($f->raw_metrics['operating_income']) ? (float) $f->raw_metrics['operating_income'] : null),
                'fiscal_year' => $f->fiscal_year ?? (isset($f->raw_metrics['fiscalYear']) && is_numeric($f->raw_metrics['fiscalYear']) ? (int) $f->raw_metrics['fiscalYear'] : null),
                'period' => $f->period !== 'quarter' ? $f->period : ($f->raw_metrics['period'] ?? $f->period),
                'filing_date' => $f->raw_metrics['fillingDate'] ?? $f->raw_metrics['filingDate'] ?? null,
                'reported_currency' => $f->reported_currency ?? ($f->raw_metrics['reportedCurrency'] ?? null),
                'raw' => $f->raw_metrics ?? [],
            ])
            ->all();
    }

    public function quarterlyBalanceSheets(string $symbol, int $limit = 8): array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return [];
        }

        if (! $this->isFinancialStorageEnabled()) {
            return $this->fetchUpstreamBalanceSheetsWithoutStorage($symbol, $limit);
        }

        $existing = CompanyFinancial::query()
            ->where('symbol', $symbol)
            ->where('statement_type', CompanyFinancial::TYPE_BALANCE_SHEET)
            ->orderByDesc('fiscal_date')
            ->take($limit)
            ->get();

        $lastAttempt = $this->getFinancialRefreshAttempt($symbol, CompanyFinancial::TYPE_BALANCE_SHEET);
        $attemptData = $lastAttempt && is_array($lastAttempt->payload) ? $lastAttempt->payload : null;
        $attemptedRecently = $lastAttempt && $lastAttempt->retrieved_at && $lastAttempt->retrieved_at->diffInHours(now()) < 24;
        $attemptFailedRecently = $attemptData && ! ($attemptData['success'] ?? true) && $lastAttempt && $lastAttempt->retrieved_at && $lastAttempt->retrieved_at->diffInHours(now()) < 1;

        $hasNoHistory = $attemptData && ($attemptData['has_no_history'] ?? false);
        if ($hasNoHistory && $attemptedRecently) {
            return [];
        }

        $prevRequestedLimit = (int) ($attemptData['requested_limit'] ?? 0);
        $hasReachedHistoryLimit = (bool) ($attemptData['has_reached_history_limit'] ?? false);

        $coverageSufficient = $existing->count() >= $limit
            || ($limit <= $prevRequestedLimit && ($attemptData['success'] ?? false))
            || ($hasReachedHistoryLimit && ($attemptData['success'] ?? false));

        $isFresh = $existing->isNotEmpty() && $coverageSufficient && (
            ($existing->count() >= $limit && $existing->first()->retrieved_at && $existing->first()->retrieved_at->diffInHours(now()) < 24)
            || ($attemptedRecently && ($attemptData['success'] ?? false))
            || $attemptFailedRecently
        );

        if ($isFresh) {
            return $existing->map(fn (CompanyFinancial $f) => [
                'date' => $f->fiscal_date->toDateString(),
                'stockholders_equity' => $f->stockholders_equity !== null ? (float) $f->stockholders_equity : null,
                'fiscal_year' => $f->fiscal_year ?? (isset($f->raw_metrics['fiscalYear']) && is_numeric($f->raw_metrics['fiscalYear']) ? (int) $f->raw_metrics['fiscalYear'] : null),
                'period' => $f->period !== 'quarter' ? $f->period : ($f->raw_metrics['period'] ?? $f->period),
                'filing_date' => $f->raw_metrics['fillingDate'] ?? $f->raw_metrics['filingDate'] ?? null,
                'reported_currency' => $f->reported_currency ?? ($f->raw_metrics['reportedCurrency'] ?? null),
                'raw' => $f->raw_metrics ?? [],
            ])->all();
        }

        $success = false;
        try {
            $fresh = $this->upstream->quarterlyBalanceSheets($symbol, $limit);
            $success = true;
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.balance_fetch_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            $fresh = [];
            $success = false;
        }

        $now = now();
        $this->recordFinancialRefreshAttempt($symbol, CompanyFinancial::TYPE_BALANCE_SHEET, $success, count($fresh), $limit);

        if (! empty($fresh)) {
            foreach ($fresh as $row) {
                if (empty($row['date'])) {
                    continue;
                }

                CompanyFinancial::updateOrCreate(
                    [
                        'symbol' => $symbol,
                        'statement_type' => CompanyFinancial::TYPE_BALANCE_SHEET,
                        'fiscal_date' => $row['date'],
                        'period' => $row['period'] ?? 'quarter',
                    ],
                    [
                        'stockholders_equity' => $row['stockholders_equity'] ?? null,
                        'fiscal_year' => isset($row['fiscal_year']) ? (int) $row['fiscal_year'] : (isset($row['fiscalYear']) ? (int) $row['fiscalYear'] : null),
                        'reported_currency' => $row['reported_currency'] ?? ($row['raw']['reportedCurrency'] ?? null),
                        'raw_metrics' => $row['raw'] ?? $row,
                        'retrieved_at' => $now,
                    ]
                );
            }

            MarketDataPayload::updateOrCreate(
                ['symbol' => $symbol, 'data_type' => 'balance_sheet', 'period' => "limit_{$limit}"],
                ['payload' => $fresh, 'retrieved_at' => $now]
            );
        }

        return CompanyFinancial::query()
            ->where('symbol', $symbol)
            ->where('statement_type', CompanyFinancial::TYPE_BALANCE_SHEET)
            ->orderByDesc('fiscal_date')
            ->take($limit)
            ->get()
            ->map(fn (CompanyFinancial $f) => [
                'date' => $f->fiscal_date->toDateString(),
                'stockholders_equity' => $f->stockholders_equity !== null ? (float) $f->stockholders_equity : null,
                'fiscal_year' => $f->fiscal_year ?? (isset($f->raw_metrics['fiscalYear']) && is_numeric($f->raw_metrics['fiscalYear']) ? (int) $f->raw_metrics['fiscalYear'] : null),
                'period' => $f->period !== 'quarter' ? $f->period : ($f->raw_metrics['period'] ?? $f->period),
                'filing_date' => $f->raw_metrics['fillingDate'] ?? $f->raw_metrics['filingDate'] ?? null,
                'reported_currency' => $f->reported_currency ?? ($f->raw_metrics['reportedCurrency'] ?? null),
                'raw' => $f->raw_metrics ?? [],
            ])
            ->all();
    }

    public function quarterlyCashFlowStatements(string $symbol, int $limit = 8): array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return [];
        }

        if (! $this->isFinancialStorageEnabled()) {
            return $this->fetchUpstreamCashFlowStatementsWithoutStorage($symbol, $limit);
        }

        $existing = CompanyFinancial::query()
            ->where('symbol', $symbol)
            ->where('statement_type', CompanyFinancial::TYPE_CASH_FLOW)
            ->orderByDesc('fiscal_date')
            ->take($limit)
            ->get();

        $lastAttempt = $this->getFinancialRefreshAttempt($symbol, CompanyFinancial::TYPE_CASH_FLOW);
        $attemptData = $lastAttempt && is_array($lastAttempt->payload) ? $lastAttempt->payload : null;
        $attemptedRecently = $lastAttempt && $lastAttempt->retrieved_at && $lastAttempt->retrieved_at->diffInHours(now()) < 24;
        $attemptFailedRecently = $attemptData && ! ($attemptData['success'] ?? true) && $lastAttempt && $lastAttempt->retrieved_at && $lastAttempt->retrieved_at->diffInHours(now()) < 1;

        $hasNoHistory = $attemptData && ($attemptData['has_no_history'] ?? false);
        if ($hasNoHistory && $attemptedRecently) {
            return [];
        }

        $prevRequestedLimit = (int) ($attemptData['requested_limit'] ?? 0);
        $hasReachedHistoryLimit = (bool) ($attemptData['has_reached_history_limit'] ?? false);

        $coverageSufficient = $existing->count() >= $limit
            || ($limit <= $prevRequestedLimit && ($attemptData['success'] ?? false))
            || ($hasReachedHistoryLimit && ($attemptData['success'] ?? false));

        $isFresh = $existing->isNotEmpty() && $coverageSufficient && (
            ($existing->count() >= $limit && $existing->first()->retrieved_at && $existing->first()->retrieved_at->diffInHours(now()) < 24)
            || ($attemptedRecently && ($attemptData['success'] ?? false))
            || $attemptFailedRecently
        );

        if ($isFresh) {
            return $existing->map(fn (CompanyFinancial $f) => [
                'date' => $f->fiscal_date->toDateString(),
                'free_cash_flow' => $f->free_cash_flow !== null ? (float) $f->free_cash_flow : null,
                'fiscal_year' => $f->fiscal_year,
                'period' => $f->period,
                'reported_currency' => $f->reported_currency,
                'raw' => $f->raw_metrics ?? [],
            ])->all();
        }

        $success = false;
        try {
            $fresh = $this->upstream->quarterlyCashFlowStatements($symbol, $limit);
            $success = true;
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.cash_flow_fetch_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            $fresh = [];
            $success = false;
        }

        $now = now();
        $this->recordFinancialRefreshAttempt($symbol, CompanyFinancial::TYPE_CASH_FLOW, $success, count($fresh), $limit);

        if (! empty($fresh)) {
            foreach ($fresh as $row) {
                if (empty($row['date'])) {
                    continue;
                }

                CompanyFinancial::updateOrCreate(
                    [
                        'symbol' => $symbol,
                        'statement_type' => CompanyFinancial::TYPE_CASH_FLOW,
                        'fiscal_date' => $row['date'],
                        'period' => $row['period'] ?? 'quarter',
                    ],
                    [
                        'free_cash_flow' => $row['free_cash_flow'] ?? null,
                        'fiscal_year' => isset($row['fiscal_year']) ? (int) $row['fiscal_year'] : (isset($row['fiscalYear']) ? (int) $row['fiscalYear'] : null),
                        'reported_currency' => $row['reported_currency'] ?? null,
                        'raw_metrics' => $row['raw'] ?? $row,
                        'retrieved_at' => $now,
                    ]
                );
            }

            MarketDataPayload::updateOrCreate(
                ['symbol' => $symbol, 'data_type' => 'cash_flow', 'period' => "limit_{$limit}"],
                ['payload' => $fresh, 'retrieved_at' => $now]
            );
        }

        return CompanyFinancial::query()
            ->where('symbol', $symbol)
            ->where('statement_type', CompanyFinancial::TYPE_CASH_FLOW)
            ->orderByDesc('fiscal_date')
            ->take($limit)
            ->get()
            ->map(fn (CompanyFinancial $f) => [
                'date' => $f->fiscal_date->toDateString(),
                'free_cash_flow' => $f->free_cash_flow !== null ? (float) $f->free_cash_flow : null,
                'fiscal_year' => $f->fiscal_year,
                'period' => $f->period,
                'reported_currency' => $f->reported_currency,
                'raw' => $f->raw_metrics ?? [],
            ])
            ->all();
    }

    /**
     * Fetch income statements directly from upstream without database storage or caching.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchUpstreamIncomeStatementsWithoutStorage(string $symbol, int $limit = 8): array
    {
        try {
            $fresh = $this->upstream->quarterlyIncomeStatements($symbol, $limit);
            if (! is_array($fresh)) {
                return [];
            }

            return array_map(function ($row) {
                if (! is_array($row)) {
                    return $row;
                }

                return array_merge($row, [
                    'date' => isset($row['date']) ? substr((string) $row['date'], 0, 10) : null,
                    'revenue' => isset($row['revenue']) && is_numeric($row['revenue']) ? (float) $row['revenue'] : ($row['revenue'] ?? null),
                    'net_income' => isset($row['net_income']) && is_numeric($row['net_income']) ? (float) $row['net_income'] : ($row['net_income'] ?? null),
                    'eps' => isset($row['eps']) && is_numeric($row['eps']) ? (float) $row['eps'] : ($row['eps'] ?? null),
                    'raw' => $row['raw'] ?? $row,
                ]);
            }, array_slice($fresh, 0, $limit));
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.income_fetch_failed_nostorage', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Fetch balance sheets directly from upstream without database storage or caching.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchUpstreamBalanceSheetsWithoutStorage(string $symbol, int $limit = 8): array
    {
        try {
            $fresh = $this->upstream->quarterlyBalanceSheets($symbol, $limit);
            if (! is_array($fresh)) {
                return [];
            }

            return array_map(function ($row) {
                if (! is_array($row)) {
                    return $row;
                }

                return array_merge($row, [
                    'date' => isset($row['date']) ? substr((string) $row['date'], 0, 10) : null,
                    'stockholders_equity' => isset($row['stockholders_equity']) && is_numeric($row['stockholders_equity']) ? (float) $row['stockholders_equity'] : ($row['stockholders_equity'] ?? null),
                    'raw' => $row['raw'] ?? $row,
                ]);
            }, array_slice($fresh, 0, $limit));
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.balance_fetch_failed_nostorage', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Fetch cash flow statements directly from upstream without database storage or caching.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchUpstreamCashFlowStatementsWithoutStorage(string $symbol, int $limit = 8): array
    {
        try {
            $fresh = $this->upstream->quarterlyCashFlowStatements($symbol, $limit);
            if (! is_array($fresh)) {
                return [];
            }

            return array_map(function ($row) {
                if (! is_array($row)) {
                    return $row;
                }

                return array_merge($row, [
                    'date' => isset($row['date']) ? substr((string) $row['date'], 0, 10) : null,
                    'free_cash_flow' => isset($row['free_cash_flow']) && is_numeric($row['free_cash_flow']) ? (float) $row['free_cash_flow'] : ($row['free_cash_flow'] ?? null),
                    'fiscal_year' => isset($row['fiscal_year']) ? (int) $row['fiscal_year'] : (isset($row['fiscalYear']) ? (int) $row['fiscalYear'] : null),
                    'period' => $row['period'] ?? null,
                    'reported_currency' => $row['reported_currency'] ?? null,
                    'raw' => $row['raw'] ?? $row,
                ]);
            }, array_slice($fresh, 0, $limit));
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.cash_flow_fetch_failed_nostorage', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function analystEstimates(string $symbol, string $period = 'quarter'): array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return [];
        }

        $v = self::getCacheVersion('estimates', $symbol);
        $cacheKey = "duoasset.v{$v}.analyst_estimates.{$symbol}.{$period}";
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $stored = MarketDataPayload::where('symbol', $symbol)
            ->where('data_type', 'analyst_estimates')
            ->where('period', $period)
            ->first();

        if ($stored && is_array($stored->payload) && $stored->retrieved_at && $stored->retrieved_at->diffInHours(now()) < 24) {
            $remaining = max(1, 86400 - $stored->retrieved_at->diffInSeconds(now()));
            Cache::put($cacheKey, $stored->payload, now()->addSeconds($remaining));

            return $stored->payload;
        }

        try {
            $fresh = $this->upstream->analystEstimates($symbol, $period);
            if (! empty($fresh)) {
                MarketDataPayload::updateOrCreate(
                    ['symbol' => $symbol, 'data_type' => 'analyst_estimates', 'period' => $period],
                    ['payload' => $fresh, 'retrieved_at' => now()]
                );
                Cache::put($cacheKey, $fresh, now()->addHours(24));

                return $fresh;
            }
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.analyst_estimates_failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);
        }

        return $stored && is_array($stored->payload) ? $stored->payload : [];
    }

    public function earningsCalendar(CarbonInterface $from, CarbonInterface $to, bool $fresh = false): array
    {
        $fresh = $fresh || config('market_data.earnings_scanner.always_fresh', false);
        $key = $from->toDateString().'_'.$to->toDateString();
        $v = self::getCacheVersion('earnings', 'GLOBAL');
        $cacheKey = "duoasset.v{$v}.earnings_calendar.{$key}";

        if (! $fresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }

            $stored = MarketDataPayload::where('symbol', 'GLOBAL')
                ->where('data_type', 'earnings_calendar')
                ->where('period', $key)
                ->first();

            if ($stored && is_array($stored->payload) && $stored->retrieved_at && $stored->retrieved_at->diffInHours(now()) < 12) {
                $remaining = max(1, 43200 - $stored->retrieved_at->diffInSeconds(now()));
                Cache::put($cacheKey, $stored->payload, now()->addSeconds($remaining));

                return $stored->payload;
            }
        } else {
            $stored = MarketDataPayload::where('symbol', 'GLOBAL')
                ->where('data_type', 'earnings_calendar')
                ->where('period', $key)
                ->first();
        }

        try {
            $freshData = $this->upstream->earningsCalendar($from, $to);
            if (! empty($freshData)) {
                MarketDataPayload::updateOrCreate(
                    ['symbol' => 'GLOBAL', 'data_type' => 'earnings_calendar', 'period' => $key],
                    ['payload' => $freshData, 'retrieved_at' => now()]
                );
                Cache::put($cacheKey, $freshData, now()->addHours(12));

                return $freshData;
            }
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.earnings_calendar_failed', ['error' => $e->getMessage()]);
        }

        return $stored && is_array($stored->payload) ? $stored->payload : [];
    }

    public function earningsSurprises(CarbonInterface $from, CarbonInterface $to, bool $fresh = false): array
    {
        $fresh = $fresh || config('market_data.earnings_scanner.always_fresh', false);
        $key = $from->toDateString().'_'.$to->toDateString();
        $v = self::getCacheVersion('earnings', 'GLOBAL');
        $cacheKey = "duoasset.v{$v}.earnings_surprises.{$key}";

        if (! $fresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }

            $stored = MarketDataPayload::where('symbol', 'GLOBAL')
                ->where('data_type', 'earnings_surprises')
                ->where('period', $key)
                ->first();

            if ($stored && is_array($stored->payload) && $stored->retrieved_at && $stored->retrieved_at->diffInHours(now()) < 12) {
                $remaining = max(1, 43200 - $stored->retrieved_at->diffInSeconds(now()));
                Cache::put($cacheKey, $stored->payload, now()->addSeconds($remaining));

                return $stored->payload;
            }
        } else {
            $stored = MarketDataPayload::where('symbol', 'GLOBAL')
                ->where('data_type', 'earnings_surprises')
                ->where('period', $key)
                ->first();
        }

        try {
            $freshData = $this->upstream->earningsSurprises($from, $to);
            if (! empty($freshData)) {
                MarketDataPayload::updateOrCreate(
                    ['symbol' => 'GLOBAL', 'data_type' => 'earnings_surprises', 'period' => $key],
                    ['payload' => $freshData, 'retrieved_at' => now()]
                );
                Cache::put($cacheKey, $freshData, now()->addHours(12));

                return $freshData;
            }
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.earnings_surprises_failed', ['error' => $e->getMessage()]);
        }

        return $stored && is_array($stored->payload) ? $stored->payload : [];
    }

    public function companyScreener(array $filters = [], bool $fresh = false): array
    {
        $isFresh = $fresh || ! empty($filters['fresh']) || ! empty($filters['force']) || config('market_data.screener.always_fresh', false);
        $upstreamFilters = $filters;
        unset($upstreamFilters['fresh'], $upstreamFilters['force']);

        $filterHash = md5(json_encode($upstreamFilters));
        $v = self::getCacheVersion('screener', 'GLOBAL');
        $cacheKey = "duoasset.v{$v}.screener.{$filterHash}";

        if (! $isFresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }

            // Storage-first: check for cached screener results within 24 hours
            $stored = MarketDataPayload::where('symbol', 'GLOBAL')
                ->where('data_type', 'screener')
                ->where('period', $filterHash)
                ->first();

            if ($stored && is_array($stored->payload) && $stored->retrieved_at && $stored->retrieved_at->diffInHours(now()) < 24) {
                $remaining = max(1, 86400 - $stored->retrieved_at->diffInSeconds(now()));
                Cache::put($cacheKey, $stored->payload, now()->addSeconds($remaining));

                return $stored->payload;
            }
        } else {
            $stored = MarketDataPayload::where('symbol', 'GLOBAL')
                ->where('data_type', 'screener')
                ->where('period', $filterHash)
                ->first();
        }

        try {
            $results = $this->upstream->companyScreener($upstreamFilters);
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.screener_fetch_failed', ['error' => $e->getMessage()]);
            $results = [];
        }

        if (! empty($results)) {
            $now = now();
            foreach ($results as $item) {
                if (! empty($item['symbol'])) {
                    $sym = strtoupper(trim($item['symbol']));
                    $existing = CompanyProfile::where('symbol', $sym)->first();
                    $nonNullFields = CompanyProfile::filterPersistedAttributes(array_filter([
                        'company_name' => $item['company_name'] ?? null,
                        'exchange' => $item['exchange'] ?? null,
                        'currency' => ($existing && ! empty($existing->currency)) ? null : ($item['currency'] ?? null),
                        'market_cap' => $item['market_cap'] ?? null,
                        'price' => $item['price'] ?? null,
                        'shares_outstanding' => $item['shares_outstanding'] ?? null,
                        'float_shares' => $item['float_shares'] ?? null,
                        'free_float' => $item['free_float'] ?? null,
                        'is_actively_trading' => $this->toBool($item['is_actively_trading'] ?? $item['isActivelyTrading'] ?? null),
                        'is_etf' => $this->toBool($item['is_etf'] ?? $item['isEtf'] ?? null),
                        'is_fund' => $this->toBool($item['is_fund'] ?? $item['isFund'] ?? null),
                        'is_adr' => $this->toBool($item['is_adr'] ?? $item['isAdr'] ?? null),
                        'type' => $item['type'] ?? $item['security_type'] ?? null,
                    ], fn ($v) => $v !== null && $v !== ''));

                    if ($existing) {
                        // Do NOT overwrite retrieved_at on existing profile from partial screener results
                        $existing->update($nonNullFields);
                    } else {
                        CompanyProfile::create(array_merge(['symbol' => $sym], $nonNullFields, ['retrieved_at' => null]));
                    }
                }
            }

            // Archive complete upstream screener response without archive-key collisions
            MarketDataPayload::updateOrCreate(
                [
                    'symbol' => 'GLOBAL',
                    'data_type' => 'screener',
                    'period' => $filterHash,
                ],
                [
                    'payload' => $results,
                    'retrieved_at' => $now,
                ]
            );

            Cache::put($cacheKey, $results, now()->addHours(24));

            return $results;
        }

        // Fallback to stored payload if upstream failed or returned empty
        return $stored && is_array($stored->payload) ? $stored->payload : [];
    }

    public function historicalIntradayBars(string $symbol, string $interval, CarbonInterface $from, CarbonInterface $to): array
    {
        $symbol = $this->normalizeFuturesAlias($symbol);
        if ($symbol === '') {
            return [];
        }

        $interval = trim($interval) !== '' ? trim($interval) : '1hour';
        $dataType = "intraday_{$interval}";
        $v = self::getCacheVersion('intraday', $symbol);

        // Memory cache key distinguishes different time ranges within the same day
        $fromKey = $from->format('Y-m-d_H-i-s');
        $toKey = $to->format('Y-m-d_H-i-s');
        $cacheKey = "duoasset.v{$v}.{$dataType}.{$symbol}.{$fromKey}.{$toKey}";

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $fromDay = $from->toDateString();
        $toDay = $to->toDateString();
        $isHistorical = $toDay < now()->toDateString();
        $hasTime = $from->format('H:i:s') !== '00:00:00' || $to->format('H:i:s') !== '00:00:00';

        // Distinguish time-window periods from full day queries
        $period = $hasTime
            ? ($from->format('Y-m-d_H:i').'_'.$to->format('Y-m-d_H:i'))
            : ($fromDay === $toDay ? $fromDay : "{$fromDay}_{$toDay}");

        // 1. Check exact period payload
        $stored = MarketDataPayload::where('symbol', $symbol)
            ->where('data_type', $dataType)
            ->where('period', $period)
            ->first();

        // 2. If not found by exact period and has time, check day-level composite payload
        if (! $stored && $hasTime && $fromDay === $toDay) {
            $stored = MarketDataPayload::where('symbol', $symbol)
                ->where('data_type', $dataType)
                ->where('period', $fromDay)
                ->first();
        }

        $existingCandles = $stored && is_array($stored->payload) ? $stored->payload : [];

        $sessionDate = CarbonImmutable::parse($toDay, 'America/New_York');
        $sessionClose = $sessionDate->setTime(16, 15, 0); // 4:15 PM Eastern

        $wasRetrievedBeforeClose = $stored && $stored->retrieved_at && $stored->retrieved_at->copy()->setTimezone('America/New_York')->lessThan($sessionClose);
        $isSessionFinishedNow = now('America/New_York')->greaterThanOrEqualTo($sessionClose);

        // A session is partially collected if it was retrieved before close and the session is now finished
        $isPartiallyCollected = $wasRetrievedBeforeClose && $isSessionFinishedNow;

        // Check if existing stored candles sufficiently cover the requested time window
        $coversWindow = false;
        if ($stored && ! empty($existingCandles) && ! $isPartiallyCollected) {
            if (! $hasTime) {
                $coversWindow = true;
            } else {
                $firstCandleDate = $existingCandles[0]['date'] ?? '';
                $lastCandleDate = $existingCandles[count($existingCandles) - 1]['date'] ?? '';

                $reqFromStr = $from->format('Y-m-d H:i:s');
                $reqToStr = $to->format('Y-m-d H:i:s');

                // Stored candles must start at or before requested start:
                $headCovered = $firstCandleDate <= $reqFromStr;

                // Stored candles must reach the requested end time, OR if requested end time is after
                // regular market close (16:00), reach the final regular session candle (>= 15:30).
                $tailCovered = $lastCandleDate >= $reqToStr
                    || (substr($reqToStr, 11) >= '16:00:00' && substr($lastCandleDate, 0, 10) === $toDay && substr($lastCandleDate, 11) >= '15:30:00');

                if ($headCovered && $tailCovered) {
                    $coversWindow = true;
                }
            }
        }

        $isFresh = $coversWindow && ($isHistorical || ($stored && $stored->retrieved_at && $stored->retrieved_at->diffInMinutes(now()) < 5));

        if ($isFresh) {
            $filtered = array_values(array_filter($existingCandles, function ($c) use ($from, $to, $hasTime) {
                $cDate = $c['date'] ?? '';
                if (! $hasTime) {
                    return $cDate >= $from->toDateString() && $cDate <= ($to->toDateString().' 23:59:59');
                }

                return $cDate >= $from->format('Y-m-d H:i:s') && $cDate <= $to->format('Y-m-d H:i:s');
            }));

            if (empty($filtered) && ! empty($existingCandles) && ! $hasTime) {
                $filtered = $existingCandles;
            }

            $ttl = $isHistorical && ! $isPartiallyCollected
                ? now()->addHours(12)
                : now()->addSeconds(max(1, 300 - ($stored && $stored->retrieved_at ? $stored->retrieved_at->diffInSeconds(now()) : 0)));
            Cache::put($cacheKey, $filtered, $ttl);

            return $filtered;
        }

        $fetchFrom = $from;
        $fetchTo = $to;

        // If we already have stored candles, prefer incremental retrieval of missing candles
        if (! empty($existingCandles) && $hasTime) {
            $firstCandleDate = $existingCandles[0]['date'] ?? '';
            $lastCandleDate = $existingCandles[count($existingCandles) - 1]['date'] ?? '';
            $reqFromStr = $from->format('Y-m-d H:i:s');
            $reqToStr = $to->format('Y-m-d H:i:s');

            // If stored candles overlap into the requested window from the start:
            if ($firstCandleDate <= $reqFromStr && $lastCandleDate >= $reqFromStr && $lastCandleDate < $reqToStr) {
                $fetchFrom = CarbonImmutable::parse($lastCandleDate);
            }
            // If stored candles overlap into the requested window from the end:
            elseif ($lastCandleDate >= $reqToStr && $firstCandleDate <= $reqToStr && $firstCandleDate > $reqFromStr) {
                $fetchTo = CarbonImmutable::parse($firstCandleDate);
            }
        }

        // Fetch fresh candles from upstream to fill missing interval
        try {
            $fresh = $this->upstream->historicalIntradayBars($symbol, $interval, $fetchFrom, $fetchTo);
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.intraday_fetch_failed', [
                'symbol' => $symbol,
                'interval' => $interval,
                'from' => $from->toDateTimeString(),
                'to' => $to->toDateTimeString(),
                'error' => $e->getMessage(),
            ]);
            $fresh = [];
        }

        // Incremental merging: merge fresh candles into existing stored candles without losing previous candles
        $candleMap = [];
        foreach ($existingCandles as $c) {
            if (! empty($c['date'])) {
                $candleMap[$c['date']] = $c;
            }
        }
        foreach ($fresh as $c) {
            if (! empty($c['date'])) {
                $candleMap[$c['date']] = $c;
            }
        }

        ksort($candleMap);
        $mergedAll = array_values($candleMap);

        if (! empty($mergedAll)) {
            MarketDataPayload::updateOrCreate(
                ['symbol' => $symbol, 'data_type' => $dataType, 'period' => $period],
                ['payload' => $mergedAll, 'retrieved_at' => now()]
            );

            // Also maintain day-level composite payload if query was on a single day with time
            if ($hasTime && $fromDay === $toDay) {
                $dayStored = MarketDataPayload::where('symbol', $symbol)
                    ->where('data_type', $dataType)
                    ->where('period', $fromDay)
                    ->first();
                $dayCandles = $dayStored && is_array($dayStored->payload) ? $dayStored->payload : [];
                $dayMap = [];
                foreach ($dayCandles as $c) {
                    if (! empty($c['date'])) {
                        $dayMap[$c['date']] = $c;
                    }
                }
                foreach ($mergedAll as $c) {
                    if (! empty($c['date'])) {
                        $dayMap[$c['date']] = $c;
                    }
                }
                ksort($dayMap);
                MarketDataPayload::updateOrCreate(
                    ['symbol' => $symbol, 'data_type' => $dataType, 'period' => $fromDay],
                    ['payload' => array_values($dayMap), 'retrieved_at' => now()]
                );
            }
        }

        // Filter for requested time window
        $filtered = array_values(array_filter($mergedAll, function ($c) use ($from, $to, $hasTime) {
            $cDate = $c['date'] ?? '';
            if (! $hasTime) {
                return $cDate >= $from->toDateString() && $cDate <= ($to->toDateString().' 23:59:59');
            }

            return $cDate >= $from->format('Y-m-d H:i:s') && $cDate <= $to->format('Y-m-d H:i:s');
        }));

        if (empty($filtered) && ! empty($mergedAll) && ! $hasTime) {
            $filtered = $mergedAll;
        }

        $ttl = $isHistorical ? now()->addHours(12) : now()->addMinutes(5);
        Cache::put($cacheKey, $filtered, $ttl);

        return $filtered;
    }

    public function lastErrors(): array
    {
        $upstreamErrors = [];
        if (method_exists($this->upstream, 'lastErrors')) {
            try {
                $upstreamErrors = $this->upstream->lastErrors() ?? [];
            } catch (Throwable) {
                $upstreamErrors = [];
            }
        }

        return array_values(array_merge($this->localErrors, $upstreamErrors));
    }

    public function clearErrors(): void
    {
        $this->localErrors = [];
        if (method_exists($this->upstream, 'clearErrors')) {
            try {
                $this->upstream->clearErrors();
            } catch (Throwable) {
                // Ignore strict mocks without clearErrors expectations
            }
        }
    }

    protected function toBool(mixed $v): ?bool
    {
        if (! is_scalar($v) || $v === '') {
            return null;
        }

        return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
