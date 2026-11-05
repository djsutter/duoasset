<?php

namespace App\Services\Stocks;

use App\Services\MarketData\FakeMarketDataProvider;
use App\Services\MarketData\MarketDataProvider;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class InstrumentNewsService
{
    public function __construct(
        protected MarketDataProvider $provider,
        protected InstrumentClassifier $classifier,
    ) {}

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
     *     category?: string|null
     * }  $options
     * @return array{
     *     symbol: string,
     *     asset_type: string,
     *     from: string,
     *     to: string,
     *     count: int,
     *     markers: array<int, array<string, mixed>>,
     *     items: array<int, array<string, mixed>>,
     *     available_categories: array<int, array{key: string, label: string}>,
     *     investigation?: array<string, mixed>|null
     * }
     */
    public function getNews(string $symbol, array $options = []): array
    {
        $instrument = $this->classifier->resolve($symbol);
        $upperSymbol = $instrument['symbol'];
        $assetType = $instrument['asset_type'];

        $limit = max(1, min(150, (int) ($options['limit'] ?? config('market_data.news.default_limit', 50))));
        $aroundTimestamp = isset($options['around_timestamp']) && is_numeric($options['around_timestamp'])
            ? (int) $options['around_timestamp']
            : null;

        $preWindowMinutes = max(15, min(2880, (int) ($options['pre_window_minutes'] ?? 720))); // default 12h before candle
        $lookAheadMinutes = max(0, min(1440, (int) ($options['look_ahead_minutes'] ?? 120))); // default 2h look-ahead

        if ($aroundTimestamp !== null && $aroundTimestamp > 0) {
            $centerUtc = Carbon::createFromTimestamp($aroundTimestamp, 'UTC');
            $from = $centerUtc->copy()->subMinutes($preWindowMinutes);
            $to = $centerUtc->copy()->addMinutes($lookAheadMinutes);
        } else {
            $to = $this->parseBound($options['to'] ?? null, isEnd: true) ?? Carbon::now('UTC');
            $from = $this->parseBound($options['from'] ?? null, isEnd: false) ?? $to->copy()->subDays(30);
        }

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy(), $from->copy()];
        }

        $rawItems = $this->collectRawItems($instrument, $from, $to, $limit);
        $normalized = $this->normalizeFilterAndDeduplicate($rawItems, $instrument, $from, $to, $aroundTimestamp);

        if (! empty($options['category']) && is_string($options['category']) && $options['category'] !== 'all') {
            $catFilter = strtolower(trim($options['category']));
            $normalized = array_values(array_filter($normalized, function (array $item) use ($catFilter) {
                foreach ($item['category_keys'] ?? [] as $k) {
                    if (strtolower((string) $k) === $catFilter) {
                        return true;
                    }
                }

                return strtolower((string) ($item['primary_category_key'] ?? '')) === $catFilter;
            }));
        }

        $items = array_slice($normalized, 0, $limit);
        $markers = $this->buildChartMarkers($items);
        $availableCategories = $this->getAvailableCategories($assetType);

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

        return [
            'symbol' => $upperSymbol,
            'asset_type' => $assetType,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'from_timestamp' => $from->timestamp,
            'to_timestamp' => $to->timestamp,
            'count' => count($items),
            'markers' => $markers,
            'items' => $items,
            'available_categories' => $availableCategories,
            'investigation' => $investigation,
        ];
    }

    /**
     * Collect raw news rows from configured FMP endpoints and optional Fed RSS.
     *
     * @param  array<string, mixed>  $instrument
     * @return array<int, array<string, mixed>>
     */
    protected function collectRawItems(array $instrument, CarbonInterface $from, CarbonInterface $to, int $limit): array
    {
        $symbol = $instrument['symbol'];
        $assetType = $instrument['asset_type'];
        $raw = [];

        $fetchLimit = max(50, min(150, $limit * 2));

        if ($assetType === InstrumentClassifier::TYPE_FUTURES) {
            $configured = (array) config("market_data.futures.instruments.{$symbol}", []);
            $forexProxies = $configured['news_proxies']['forex'] ?? ($symbol === 'GCUSD' ? ['XAUUSD'] : []);
            $stockProxies = $configured['news_proxies']['stock'] ?? ($symbol === 'GCUSD' ? ['GLD', 'IAU', 'USO', 'TLT', 'UUP'] : []);

            // 1. FMP Forex News (e.g. XAUUSD for Gold Futures)
            if (method_exists($this->provider, 'forexNews')) {
                if (! empty($forexProxies)) {
                    $forexRows = $this->provider->forexNews($forexProxies, $from, $to, $fetchLimit);
                    foreach ($forexRows as $row) {
                        if (is_array($row)) {
                            $row['_source_channel'] = 'fmp_forex';
                            $row['_direct_proxy'] = true;
                            $raw[] = $row;
                        }
                    }
                }
                // Also include broader forex/commodity headlines
                $latestForex = $this->provider->forexNews([], $from, $to, 40);
                foreach ($latestForex as $row) {
                    if (is_array($row)) {
                        $row['_source_channel'] = 'fmp_forex_latest';
                        $raw[] = $row;
                    }
                }
            }

            // 2. FMP Stock/ETF Proxy News (e.g. GLD, IAU, USO, TLT)
            if (! empty($stockProxies) && method_exists($this->provider, 'stockNews')) {
                $proxyRows = $this->provider->stockNews($stockProxies, $from, $to, $fetchLimit);
                foreach ($proxyRows as $row) {
                    if (is_array($row)) {
                        $row['_source_channel'] = 'fmp_proxy_news';
                        $raw[] = $row;
                    }
                }
            }

            // 3. FMP General Financial & Geopolitical News
            if (method_exists($this->provider, 'generalNews')) {
                $generalRows = $this->provider->generalNews($from, $to, $fetchLimit);
                foreach ($generalRows as $row) {
                    if (is_array($row)) {
                        $row['_source_channel'] = 'fmp_general';
                        $raw[] = $row;
                    }
                }
            }

            // 4. FMP Economic Calendar (High-impact US / Fed / Inflation / Rate releases)
            if (method_exists($this->provider, 'economicCalendar')) {
                $econRows = $this->provider->economicCalendar($from, $to);
                foreach ($econRows as $row) {
                    if (is_array($row)) {
                        $converted = $this->convertEconomicCalendarRow($row);
                        if ($converted !== null) {
                            $raw[] = $converted;
                        }
                    }
                }
            }

            // 5. Federal Reserve Monetary Policy RSS Feed (when enabled and not using un-mocked FakeMarketDataProvider)
            foreach ($this->fetchFederalReserveRss($from, $to) as $fedRow) {
                $raw[] = $fedRow;
            }
        } else {
            // Stock instrument news
            if (method_exists($this->provider, 'stockNews')) {
                $stockRows = $this->provider->stockNews([$symbol], $from, $to, $fetchLimit);
                foreach ($stockRows as $row) {
                    if (is_array($row)) {
                        $row['_source_channel'] = 'fmp_stock';
                        $row['_direct_symbol'] = true;
                        $raw[] = $row;
                    }
                }
            }

            if (method_exists($this->provider, 'pressReleases')) {
                $pressRows = $this->provider->pressReleases([$symbol], $from, $to, 25);
                foreach ($pressRows as $row) {
                    if (is_array($row)) {
                        $row['_source_channel'] = 'fmp_press_release';
                        $row['_direct_symbol'] = true;
                        $raw[] = $row;
                    }
                }
            }

            if (method_exists($this->provider, 'generalNews')) {
                $generalRows = $this->provider->generalNews($from, $to, 30);
                foreach ($generalRows as $row) {
                    if (is_array($row)) {
                        $row['_source_channel'] = 'fmp_general';
                        $raw[] = $row;
                    }
                }
            }
        }

        return $raw;
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
        $profileConfig = (array) config("market_data.news.relevance_profiles.{$assetType}", []);
        $minScore = (int) ($profileConfig['min_score'] ?? 15);
        $themes = (array) ($profileConfig['themes'] ?? []);

        $fromTs = $from->copy()->startOfMinute()->timestamp;
        // If `to` was passed as a date with 00:00:00, treat as endOfDay
        $toTs = ($to->format('H:i:s') === '00:00:00')
            ? $to->copy()->endOfDay()->timestamp
            : $to->timestamp;

        $byFingerprint = [];
        $byUrl = [];

        foreach ($rawItems as $raw) {
            $title = trim(strip_tags((string) ($raw['title'] ?? $raw['headline'] ?? '')));
            if ($title === '') {
                continue;
            }

            $pubRaw = $raw['publishedDate'] ?? $raw['published_at'] ?? $raw['date'] ?? null;
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

            $summary = trim(strip_tags((string) ($raw['text'] ?? $raw['summary'] ?? $raw['content'] ?? '')));
            if (mb_strlen($summary) > 360) {
                $summary = rtrim(mb_substr($summary, 0, 357)).'...';
            }

            $url = trim((string) ($raw['url'] ?? $raw['link'] ?? ''));
            $publisher = trim((string) ($raw['publisher'] ?? $raw['site'] ?? $raw['source'] ?? 'Financial News'));
            $site = trim((string) ($raw['site'] ?? parse_url($url, PHP_URL_HOST) ?? ''));

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

            $item = [
                'id' => $itemId,
                'symbol' => (string) ($raw['symbol'] ?? $symbol),
                'title' => $title,
                'summary' => $summary,
                'source' => $publisher !== '' ? $publisher : ($site !== '' ? $site : 'Market News'),
                'site' => $site,
                'url' => $url !== '' ? $url : null,
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
                // Keep earliest publication timestamp so we never post-date first publication
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

        // Sort newest publication timestamp first (or closest to investigated candle when investigating)
        usort($items, fn (array $a, array $b) => $b['published_timestamp'] <=> $a['published_timestamp']);

        return $items;
    }

    /**
     * Score relevance (0-100) and return matched category tags.
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
        $nameLower = strtolower((string) ($instrument['name'] ?? ''));

        $titleLower = strtolower($title);
        $bodyLower = strtolower($summary);
        $rawSymbol = strtoupper(trim((string) ($raw['symbol'] ?? '')));

        $score = 0;
        $matched = [];

        if (! empty($raw['_direct_symbol']) || $rawSymbol === $symbol) {
            $score += 45;
        } elseif (! empty($raw['_direct_proxy']) || ($symbol === 'GCUSD' && in_array($rawSymbol, ['XAUUSD', 'GLD', 'IAU'], true))) {
            $score += 35;
        }

        if ($nameLower !== '' && strlen($nameLower) >= 4) {
            $cleanCompany = trim((string) preg_replace('/\b(inc\.?|corp\.?|ltd\.?|plc|futures)\b/i', '', $nameLower));
            if ($cleanCompany !== '' && strlen($cleanCompany) >= 3) {
                if (str_contains($titleLower, $cleanCompany)) {
                    $score += 30;
                } elseif (str_contains($bodyLower, $cleanCompany)) {
                    $score += 15;
                }
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

        if ($assetType === InstrumentClassifier::TYPE_STOCK && empty($matched) && $score >= 25) {
            $matched[] = [
                'key' => 'company_news',
                'label' => 'Company News',
            ];
        }

        return [min(100, $score), $matched];
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

        // Prioritize highest relevance while keeping chronological distribution
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
                'gold_precious_metals', 'central_bank_gold' => '#d97706', // amber-600
                'fed_monetary_policy', 'inflation_rates' => '#7c3aed',    // violet-600
                'geopolitics', 'oil_supply' => '#e11d48',                 // rose-600
                'earnings_financials' => '#0284c7',                       // sky-600
                default => '#0284c7',                                     // sky-600
            };

            $letter = match ($catKey) {
                'gold_precious_metals', 'central_bank_gold' => 'G',
                'fed_monetary_policy' => 'F',
                'inflation_rates' => 'I',
                'geopolitics' => '!',
                'oil_supply' => 'O',
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
        $relevantMacro = ['fed', 'fomc', 'cpi', 'inflation', 'pce', 'nonfarm', 'payroll', 'ppi', 'gdp', 'treasury', 'interest rate', 'powell', 'gold', 'crude oil', 'jobless'];
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

        $rssUrl = (string) config('market_data.news.fed_rss_url', 'https://www.federalreserve.gov/feeds/press_monetary.xml');
        if ($rssUrl === '') {
            return [];
        }

        return Cache::remember('duoasset.news.fed_rss', now()->addMinutes(30), function () use ($rssUrl) {
            try {
                $resp = Http::timeout(6)->get($rssUrl);
                if (! $resp->successful()) {
                    return [];
                }

                $xml = @simplexml_load_string($resp->body(), 'SimpleXMLElement', LIBXML_NOCDATA);
                if (! $xml || ! isset($xml->channel->item)) {
                    return [];
                }

                $out = [];
                foreach ($xml->channel->item as $item) {
                    $title = trim((string) ($item->title ?? ''));
                    $link = trim((string) ($item->link ?? ''));
                    $desc = trim((string) ($item->description ?? ''));
                    $pubDate = trim((string) ($item->pubDate ?? ''));

                    if ($title === '' || $pubDate === '') {
                        continue;
                    }

                    $parsed = $this->parseNewsTimestamp($pubDate);
                    if (! $parsed) {
                        continue;
                    }

                    $out[] = [
                        'symbol' => 'FED',
                        'title' => 'Federal Reserve: '.$title,
                        'text' => $desc !== '' ? $desc : $title,
                        'publishedDate' => $parsed->toIso8601String(),
                        'event_at' => $parsed->toIso8601String(),
                        'publisher' => 'Federal Reserve Board',
                        'site' => 'federalreserve.gov',
                        'url' => $link,
                        '_source_channel' => 'fed_rss',
                        '_direct_proxy' => true,
                    ];
                }

                return $out;
            } catch (\Throwable) {
                return [];
            }
        });
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    public function getAvailableCategories(string $assetType): array
    {
        $themes = (array) config("market_data.news.relevance_profiles.{$assetType}.themes", []);
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
