
# DuoAsset

## Contents

- [Market Data Updates](#market-data-updates)
- [Stock Analysis Pages (Vela)](#stock-analysis-pages-vela)
- [Interactive News Timeline](#interactive-news-timeline)
  - See also: [docs/futures-and-news-timeline.md](docs/futures-and-news-timeline.md) — futures market data depth, Vela v0.9.0 API integration & local LLM evaluation
- [Stock Buy Setup Scanner](#stock-buy-setup-scanner)
  - See also: [README_setup.md](README_setup.md) — algorithm selection & scoring-weight tuning guide
- [EPS Surprise Tracker (bidirectional)](#eps-surprise-tracker-bidirectional)
- [EPS Revision Tracker](#eps-revision-tracker)
- [Sector Money Flows](#sector-money-flows)
- [Scripts](#scripts)

## Market Data Updates

In order to trigger alpha vantage stock updates, run this:

```bash
php artisan market-watch:update-quotes
```

## Stock Analysis Pages (Vela)

DuoAsset includes an authenticated, TradingView-style **stock analysis page** at
`/stocks/{symbol}` (named route `stocks.show`), including ticker symbols with exchange
suffixes such as `FEO.V`. The page uses the existing Laravel/Livewire UI and FMP market
data provider; it does **not** make browser-to-FMP requests or execute the expensive
setup scanner when a page is viewed.

> **Implementation status (October 28, 2026):** The routes, UI, backend candle adapter,
> and automated PHP feature tests have been added. An isolated Vela build and a full
> `npm ci --include=dev;npm run build` to build.
> Browser acceptance and PHP test execution remain outstanding; see below. A successful
> asset build alone does not confirm Vela renders/interacts correctly in the browser.

### Features implemented in code

- **Stock identity and quote header:** symbol, company name, exchange, latest available
  price, change and percent change, volume, market cap, a price-source badge, and a
  market-data date/timestamp fallback. Missing fields show an unavailable value.
- **Interactive chart shell:** LuxAlgo Vela (`@luxalgo/vela`, locked at `0.9.0`) in
  single-chart workspace mode. The initial dataset is approximately one year of daily
  candlesticks. The chart integration configures volume, OHLC status information,
  crosshair/zoom/drawings through Vela, an indicator picker, app theme integration,
  and an expanded/fullscreen control. Browser behavior still needs acceptance testing.
- **Eight selectable intervals:** `1min`, `5min`, `15min`, `30min`, `1hour`, `1day`,
  `1week`, `1month`. Weekly/monthly bars are aggregated from daily OHLCV.
- **Six historical range choices:** `1M`, `3M`, `6M`, `1Y`, `5Y`, `All`. Intraday queries
  are capped by interval to limit payload size; `All` is currently implemented as a
  **20-year lookback**, not an exchange-listing-date discovery mechanism.
- **Chart preferences:** the selected interval/range and Vela workspace state have
  per-user, per-symbol localStorage keys (`duoasset_chart_prefs_*` and
  `duoasset_vela_*`). Indicator/drawing restoration must still be verified in a browser.
- **DuoAsset setup score:** shows the most recent persisted `StockBuySetupAlert` for the
  symbol, including the stored score, setup classification, raw/heartbeat scores,
  selected pattern metrics, calculation date, score-component breakdown, and reason
  summary. Missing scores show a clear unavailable state. The scanner is **not**
  invoked on page load. With multiple configured setup types, the current page shows
  only one latest alert, not a score card for each type.
- **Ticker navigation:** reusable `<x-ticker-link :symbol="..." />` component using the
  `stocks.show` named route, applied to stock listings, setup scanner results, EPS
  surprise/revision screens, watchlists, and the admin setup-run report. Autocomplete
  selection UI remains a selection control rather than a navigation link.

### Routes, data handling, and key files

| Area | Route / file | Purpose |
| --- | --- | --- |
| Stock page | `GET /stocks/{symbol}` — `stocks.show` | Authenticated Livewire view; stock domain and verified-user middleware apply. |
| Chart JSON | `GET /stocks/{symbol}/candles` — `stocks.candles` | Normalized OHLCV JSON; query parameters: `interval`, optional ISO date `from` / `to`, or `range`. |
| Page component | `app/Livewire/Stocks/Show.php` | Loads the profile, quote, saved setup alert, and initial daily bars. |
| Candle controller | `app/Http/Controllers/StockCandlesController.php` | Validates symbols and handles interval/range requests. |
| Chart adapter | `app/Services/Stocks/StockChartService.php` | Reuses `MarketDataProvider`, normalizes/sorts/deduplicates candles, aggregates daily bars, and caches chart responses. |
| Vela integration | `resources/js/stock-chart.js` | Creates the Vela workspace, handles theme and state integration. |
| UI markup | `resources/views/livewire/stocks/show.blade.php` | Header, Alpine interval/range controls, chart, and setup-score panel. |
| Ticker component | `resources/views/components/ticker-link.blade.php` | Shared link from existing symbol lists. |
| Feature tests | `tests/Feature/Stocks/StockAnalysisPageTest.php` | Auth, tickers, JSON schema, intervals, aggregation, range parameters, and rendered controls. |

Candle responses include `symbol`, `interval`, `from`, `to`, `count`, and ascending
`candles`, each containing `time` (Unix seconds), `open`, `high`, `low`, `close`, and
`volume`. The frontend converts timestamps to **epoch milliseconds** for Vela.

The chart-service cache currently lasts **one hour** for daily/weekly/monthly data
and **five minutes** for intraday data. The underlying FMP provider also caches daily
bars for **12 hours** and intraday bars for **15 minutes**; the effective data freshness
may therefore be longer than the chart-service TTL. Profiles are cached for 24 hours.

### Setup and verification

```bash
npm ci
npm run build
php artisan route:list --name=stocks
php artisan test --filter=StockAnalysisPageTest
# or: ./vendor/bin/pest tests/Feature/Stocks/StockAnalysisPageTest.php
```

`php artisan` requires installed Composer dependencies (`vendor/`).
verified a successful Vite 6.3.5 build (156 modules, 4.04 seconds).

A browser smoke
check is also required for `ARVN`, `RARE`, `GPUS`, and `FEO.V`, with the Vite assets
built and a functioning FMP configuration. Verify 1D, intraday and weekly/monthly
intervals; range switching; volume/indicators; page navigation; theme/fullscreen;
mobile widths; persistence after reload; and no browser console/network errors.

### Completed improvements (October 28, 2026 pass)

1. **Separated chart-container lifecycle from overlay states:** Mounted Vela into a
   dedicated child node (`data-vela-mount` / `x-ref="velaMount"`) while keeping loading,
   error, and empty-state overlays on the parent container. Neither `stock-chart.js`
   nor Alpine error handling clears the overlay markup.
2. **Bounded initialization polling and failure surfacing:** `initChart()` now bounds
   script polling to a 5-second window and displays an actionable UI error if the
   chart bundle fails to load or constructor throws, rather than spinning indefinitely.
   Added teardown cleanup on component unmount and `wire:navigate`.
3. **Supported Vela 0.9.0 configuration:** Replaced unsupported `statusLine` object
   with the supported `statusline: true` boolean toggle.
4. **Asynchronous request race protection:** `fetchCandles()` uses an `AbortController`
   and request-sequence counter so rapid interval or range switching cancels in-flight
   requests and prevents stale responses from overwriting newer chart selections.
5. **Strict input validation:** `StockCandlesController` validates `interval`, `range`,
   `from`, and `to` formats, returning explicit HTTP 422 responses on malformed inputs
   or when `from > to`, rather than silently defaulting.
6. **Data bounds, timezone, and OHLC integrity:** `StockChartService` enforces exact
   requested `from`/`to` bounds, rejects future bars against actual current time, parses
   intraday session timestamps in exchange Eastern Time (`America/New_York`) for proper
   DST alignment, rejects negative volume while preserving legitimate zero-volume bars,
   and normalizes geometric OHLC invariants.
7. **Multi-setup algorithm support:** The setup score panel now exposes all detected
   setup types for the symbol (`setupAlertsByType`), displaying setup badges and scores
   alongside the primary overall setup alert.

### Outstanding validation and notes

1. **Browser visual smoke test:** Run manual or browser-level checks for `ARVN`, `RARE`,
   `GPUS`, and `FEO.V` with live FMP credentials to verify visual candlestick rendering,
   interactive drawing tools, indicator library settings persistence across page reloads,
   and responsive mobile breakpoints.
2. **Provider caching hierarchy:** Documented that `StockChartService` caches normalized
   daily candles for 1 hour and intraday candles for 5 minutes, backed by FMP provider
   caches (12 hours daily, 15 minutes intraday).

**Deferred by the original specification:** Pine Script execution/editor, strategy
backtesting, full historical scanner annotations, multi-chart workspaces, streaming,
and price alerts. Keep AGPL-licensed PineTS/Vela-pinets out of the initial feature.
Preserve Vela's required on-screen attribution and review licensing before adding a
scripting engine. See `resources/js/stock-chart.js` for the initial extension note.

## Interactive News Timeline

DuoAsset includes an **Interactive News Timeline** synchronized with the LuxAlgo/Vela
chart across both equities (`/stock/{symbol}`, `/stocks/{symbol}`) and commodity futures
(`/futures/{symbol}`, such as `/futures/gcusd`, `/futures/siusd`, `/futures/clusd`,
`/futures/ngusd`, and `/futures/hgusd`). Detailed notes on FMP futures historical depth,
Vela v0.9.0 marker APIs, and local LLM benchmarks live in
[`docs/futures-and-news-timeline.md`](docs/futures-and-news-timeline.md).

### Architecture

1. **Provider Chain (`MarketDataServiceProvider` → `PersistentMarketDataProvider` → `FmpMarketDataProvider`)**:
   - `MarketDataProvider` resolves to `PersistentMarketDataProvider` wrapping `FmpMarketDataProvider`.
   - `PersistentMarketDataProvider` delegates `stockNews()`, `forexNews()`, `generalNews()`, `pressReleases()`, and `economicCalendar()` to the upstream FMP client and records a structured `501` diagnostic error if an upstream driver lacks a required method.
   - `FmpMarketDataProvider` caches valid upstream array responses while refusing to cache `null` error responses (HTTP `401`/`402`/`403`/`429`/`5xx`), ensuring upstream failures are surfaced immediately rather than masked as empty feeds.
2. **Aggregation, Disambiguation & Relevance Scoring (`InstrumentNewsService`)**:
   - `InstrumentClassifier::resolve()` enriches stocks with company name, exchange, sector, and industry from local database profiles (`company_profiles` / `stocks`) or FMP `/profile`, and resolves futures metadata from `config/market_data.php` and `/commodities-list`.
   - Deterministic scoring (`scoreRelevance()`) enforces strict company/ticker identity matching for equities, rejects cross-exchange ticker collisions (e.g., `TSXV: MASS` *Masivo Silver* when viewing `NASDAQ: MASS` *908 Devices*), rejects project/entity name collisions (e.g., *Cronos Gas* when viewing `NASDAQ: CRON` *Cronos Group*), and filters untargeted market roundups.
   - For futures, `InstrumentNewsService` applies per-commodity relevance profiles (`gold`, `silver`, `crude_oil`, `natural_gas`, `copper`), separates direct commodity proxies (`XAUUSD`, `GLD`, `IAU`) from indirect macro/forex proxies (`EURUSD`, `USDJPY`), and excludes routine foreign-pair technical forecasts unless they directly reference the target commodity.
   - Syndicated duplicates are merged by canonical URL and headline fingerprint while preserving the **earliest verifiable publication timestamp** (`published_timestamp`) and separating explicit earlier `event_timestamp` values when reported in the text.
3. **Shared Caching, Persistent Archive & Concurrency Locking**:
   - Normalized results are cached per `(symbol, window)` in Laravel's shared cache (`duoasset.news.v2.{symbol}.{windowKey}`) and archived in the `instrument_news_articles` table (`App\Models\InstrumentNewsArticle`, unique on `(symbol, article_uid)`).
   - Concurrent requests for the same symbol and window coordinate via `Cache::lock()`, preventing redundant external searches when multiple users view the same ticker simultaneously.
4. **Vela Chart Synchronization & Event Price-Impact (`NewsEventImpactService`)**:
   - Up to `INSTRUMENT_NEWS_MAX_MARKERS` (default `25`) high-relevance markers are rendered on the Vela v0.9.0 chart (`chart.marks`).
   - Selecting a headline centers the Vela chart on the publication timestamp and computes observed `5m`, `15m`, `30m`, `60m`, and `4h` post-event price changes alongside related market comparisons (`CLUSD`, `SIUSD`, `GCUSD`). Sub-hour horizons (`5m`, `15m`, `30m`) strictly require `5min` candle resolution and post-event bars.
   - Panning or zooming the chart backward or forward in time updates the visible news window and merges historical articles from `instrument_news_articles` and upstream sources, using `AbortController` and request-sequence validation so stale responses never overwrite newer selections.

### News Sources

| Priority | Source | Default State | Purpose |
| --- | --- | --- | --- |
| 1 | **FMP Stock News & Press Releases** (`/stable/news/stock`, `/stable/news/press-releases`) | Enabled | Direct company news, earnings releases, M&A, and corporate filings for equities and commodity ETF proxies. |
| 2 | **FMP Forex & Commodity News** (`/stable/news/forex`, `/stable/news/forex-latest`) | Enabled | Spot gold (`XAUUSD`), silver (`XAGUSD`), and macro FX coverage for futures instruments. |
| 3 | **FMP General News & Economic Calendar** (`/stable/news/general-latest`, `/stable/economic-calendar`) | Enabled | Geopolitical developments, oil supply disruptions, and high-impact U.S. macroeconomic releases (CPI, NFP, FOMC, GDP). |
| 4 | **Federal Reserve & Official RSS Feeds** (`https://www.federalreserve.gov/feeds/press_monetary.xml`) | Enabled | Official Federal Reserve monetary policy announcements and configurable issuer/regulatory RSS feeds (`market_data.news.sources.rss_feeds`). |
| 5 | **SearXNG Discovery Fallback** (`http://searxng:8080/search?q=<query>&format=json`) | Enabled (fallback when `< 5` primary articles) | Secondary discovery for recent articles when primary coverage is sparse. Never fabricates timestamps for undated search results. |
| 6 | **Optional Local LLM Enrichment** (`OllamaNewsEnricher` via Ollama `/api/generate`) | Disabled (`INSTRUMENT_NEWS_AI_ENABLED=false`) | Asynchronous, article-cached relevance verification and concise *"Why it matters"* rationale (`AI Note (Unverified)`). |

### Configuration

All settings live in `config/market_data.php` under the `'news'` key and are controlled via `.env`:

```dotenv
# Core FMP credentials & provider
MARKET_DATA_PROVIDER=fmp
FMP_API_KEY=your-fmp-api-key
FMP_BASE_URL=https://financialmodelingprep.com/stable
FMP_RATE_LIMIT_PER_MINUTE=300

# Interactive News Timeline core settings
INSTRUMENT_NEWS_ENABLED=true
INSTRUMENT_NEWS_DEFAULT_LIMIT=50
INSTRUMENT_NEWS_MAX_LIMIT=150
INSTRUMENT_NEWS_MAX_MARKERS=25
INSTRUMENT_NEWS_DEFAULT_LOOKBACK_DAYS=365
INSTRUMENT_NEWS_STOCK_MIN_SCORE=25
INSTRUMENT_NEWS_FUTURES_MIN_SCORE=25

# Shared caching, persistent DB storage & concurrency locks
INSTRUMENT_NEWS_PERSISTENT_STORAGE_ENABLED=true
INSTRUMENT_NEWS_CACHE_TTL_RECENT=600
INSTRUMENT_NEWS_CACHE_TTL_HISTORICAL=43200
INSTRUMENT_NEWS_PROVIDER_CACHE_TTL=600
INSTRUMENT_NEWS_RETENTION_DAYS=730
INSTRUMENT_NEWS_LOCK_TIMEOUT=15
INSTRUMENT_NEWS_LOCK_WAIT=3

# Asynchronous retrieval & queue settings
INSTRUMENT_NEWS_ASYNC_ON_LOAD=false
INSTRUMENT_NEWS_QUEUE_CONNECTION=database
INSTRUMENT_NEWS_QUEUE_NAME=default

# Individual source toggles
INSTRUMENT_NEWS_FMP_STOCK_ENABLED=true
INSTRUMENT_NEWS_FMP_PRESS_ENABLED=true
INSTRUMENT_NEWS_FMP_FOREX_ENABLED=true
INSTRUMENT_NEWS_FMP_GENERAL_ENABLED=true
INSTRUMENT_NEWS_FMP_ECON_CALENDAR_ENABLED=true
INSTRUMENT_NEWS_FED_RSS_ENABLED=true
INSTRUMENT_NEWS_FED_RSS_URL=https://www.federalreserve.gov/feeds/press_monetary.xml

# Secondary SearXNG discovery fallback
INSTRUMENT_NEWS_SEARXNG_ENABLED=true
SEARXNG_URL=http://searxng:8080
SEARXNG_TIMEOUT=5
SEARXNG_FALLBACK_MIN_ARTICLES=5

# Optional local Ollama LLM enrichment (basic news works without AI)
INSTRUMENT_NEWS_AI_ENABLED=false
INSTRUMENT_NEWS_AI_ASYNC=true
OLLAMA_URL=http://127.0.0.1:11434
INSTRUMENT_NEWS_OLLAMA_MODEL=qwen3:8b
INSTRUMENT_NEWS_OLLAMA_FALLBACK_MODEL=qwen3.8:27b-q4_K_M
INSTRUMENT_NEWS_OLLAMA_TIMEOUT=15
INSTRUMENT_NEWS_OLLAMA_BATCH_SIZE=8
INSTRUMENT_NEWS_AI_CACHE_TTL_DAYS=30
```

### Background Processing

- **Shared Cache & Database Persistence**: Recent windows cache for `10 minutes` (`INSTRUMENT_NEWS_CACHE_TTL_RECENT=600`), while historical windows older than 24 hours cache for `12 hours` (`INSTRUMENT_NEWS_CACHE_TTL_HISTORICAL=43200`). Normalized articles are upserted into `instrument_news_articles` so historical chart navigation does not repeat external API queries.
- **Queued Refresh & AI Jobs**:
  - `App\Jobs\RefreshInstrumentNewsJob` (`ShouldBeUnique`): Dispatched when `INSTRUMENT_NEWS_ASYNC_ON_LOAD=true` and a cold cache is encountered; the frontend displays `Refreshing...` and polls up to 4 times (every 1.5s) until the shared cache is populated.
  - `App\Jobs\EnrichInstrumentNewsJob` (`ShouldBeUnique`): Dispatched when `INSTRUMENT_NEWS_AI_ENABLED=true` and `INSTRUMENT_NEWS_AI_ASYNC=true` so genuine news renders immediately while Ollama inference runs in the background and caches results per article (`duoasset.news.ai.v1.{symbol}.{article_id}` and `instrument_news_articles.ai_enrichment`).
- **Ubuntu Production Queue Worker**:
  Ensure a queue worker is running under `systemd` or `Supervisor` on Ubuntu production servers:
  ```bash
  php artisan queue:work --queue=default --tries=2 --timeout=60
  ```

### Verification

Administrators can verify the entire news pipeline for any stock or futures symbol using the built-in Artisan diagnostic command:

```bash
# Verify stock news (Nebius Group, 908 Devices, Cronos Group)
php artisan news:verify NBIS --fresh --limit=10
php artisan news:verify MASS --fresh --limit=10
php artisan news:verify CRON --fresh --limit=10

# Verify gold futures news & macro sources
php artisan news:verify GCUSD --fresh --limit=10

# Test SearXNG discovery fallback and optional Ollama AI enrichment
php artisan news:verify NBIS --fresh --searxng --ai
```

To **distinguish a backend provider problem from a frontend display problem**:

1. Run `php artisan news:verify NBIS --fresh`. If `Status : available` and `Retained Articles > 0` are printed, the backend provider chain, FMP credentials, and relevance filters are working properly.
2. Query the JSON endpoint directly (authenticated session or via Tinker):
   ```bash
   php artisan tinker --execute="dump(app(\App\Services\Stocks\InstrumentNewsService::class)->getNews('NBIS', ['limit' => 5]));"
   ```
3. If the backend returns `count > 0` but the browser shows 0 items:
   - Check the **Follow Chart Range** badge in the news panel header — if the chart is zoomed into a narrow historical window with no news on those dates, click **Follow Chart Range: On** (to toggle it off) or **Show All Loaded News**.
   - Rebuild frontend assets on the server (`npm run build && php artisan view:clear`).

### Troubleshooting

| Symptom | Cause & Actionable Resolution |
| --- | --- |
| **No articles displayed** | Run `php artisan news:verify <SYMBOL> --fresh` to inspect per-source counts and `status`. Check `storage/logs/laravel.log` for `instrument_news.retrieved` or `instrument_news.retrieval_degraded`. |
| **FMP authentication failures (`status: auth_error`, HTTP 401)** | `FMP_API_KEY` is missing or invalid in `.env`. Verify with `php artisan config:clear` and `php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo strlen((string) config("market_data.fmp.api_key"))."\n";'`. |
| **Insufficient FMP subscription permissions (`status: auth_error`, HTTP 402/403)** | The configured FMP plan does not permit `/stable/news/*` endpoints. Verify directly with `curl -sS "https://financialmodelingprep.com/stable/news/stock?symbols=AAPL&limit=5&apikey=$FMP_API_KEY"`. |
| **Missing provider methods (`failure_category: missing_provider_method`)** | Custom `MarketDataProvider` binding does not implement `stockNews()`, `forexNews()`, `generalNews()`, `pressReleases()`, or `economicCalendar()`. Run `php artisan news:verify NBIS` to check `Provider News Methods : OK`. |
| **Empty provider responses (`status: empty`)** | Upstream sources returned `200 []` for the symbol and window. For thinly covered micro-caps, enable SearXNG fallback (`INSTRUMENT_NEWS_SEARXNG_ENABLED=true`) or widen `INSTRUMENT_NEWS_DEFAULT_LOOKBACK_DAYS`. |
| **News relevance filters excluding articles (`raw_count > 0`, `retained_count = 0`)** | Lower `INSTRUMENT_NEWS_STOCK_MIN_SCORE` or `INSTRUMENT_NEWS_FUTURES_MIN_SCORE` in `.env`, or add company aliases in `config/market_data.php` under `news.symbol_disambiguation`. |
| **Chart-range filtering hiding articles** | When `Follow Chart Range: On` is active, only articles inside the Vela chart's visible date range are shown. The empty state displays *"X relevant articles are loaded for SYMBOL, but none match the current chart range"* — click **Show All Loaded News** or zoom out (`1Y` / `All`). |
| **SearXNG unavailable (`searxng.status: unreachable`)** | Verify the SearXNG container/service is reachable from the app host: `curl -sS "http://searxng:8080/search?q=NBIS&format=json"`. Update `SEARXNG_URL` in `.env` if hosted on a different address or port. |
| **SearXNG JSON disabled (`searxng.status: json_disabled_or_invalid`)** | SearXNG defaults to HTML-only unless `json` is enabled in SearXNG's `/etc/searxng/settings.yml` under `search: formats: [html, json]`. Enable `json` in `settings.yml` and restart the SearXNG container. |
| **RSS parsing errors (`instrument_news.rss_parse_error`)** | Check outbound HTTPS connectivity to `https://www.federalreserve.gov/feeds/press_monetary.xml` (`curl -I https://www.federalreserve.gov/feeds/press_monetary.xml`) and ensure the `php-xml` (`SimpleXML`) extension is installed (`php -m \| grep SimpleXML`). |
| **Queue workers not running** | If `INSTRUMENT_NEWS_ASYNC_ON_LOAD=true` or `INSTRUMENT_NEWS_AI_ASYNC=true`, check pending jobs via `php artisan queue:monitor default` or `SELECT count(*) FROM jobs;` and restart the worker (`sudo systemctl restart duoasset-queue` or `php artisan queue:work --stop-when-empty`). |
| **Ollama unavailable (`instrument_news.ollama_unavailable`)** | Basic news retrieval continues unaffected. To diagnose Ollama, test `curl -sS "$OLLAMA_URL/api/tags"` and verify the configured model (`qwen3:8b` or `qwen3.8:27b-q4_K_M`) is pulled (`ollama list`). |
| **Chart markers missing** | Confirm the **News Markers: On** button in the chart toolbar is active, `npm run build` has been executed, and retained articles have timestamps that fall within the loaded candle history range. |
| **Incorrect publication timestamps** | FMP news timestamps without explicit timezone offsets are reported in `America/New_York` (ET) and normalized to `UTC` by `InstrumentNewsService::parseNewsTimestamp()`. Ensure server system time (`timedatectl`) is synchronized via NTP. |

### Ubuntu Production Deployment & Maintenance Commands

```bash
cd /path/to/duoasset
php artisan migrate --force
php artisan config:clear
php artisan cache:clear
npm ci && npm run build
php artisan news:verify NBIS --fresh
php artisan news:verify GCUSD --fresh
```

## Stock Buy Setup Scanner

DuoAsset also scans for a **Stock Buy Setup**: a tight, multi-week consolidation base
followed by a rare high-volume breakout spike (a 52-week or 104-week high-volume day).
It follows the same overall shape as the EPS scanners below (FMP screener → per-symbol
job → alert row → opt-in notification → watchlist) and reuses the same
`App\Services\MarketData\MarketDataProvider` (FMP) — no duplicate HTTP client.

> Detection is a **scored heuristic**, not a hard pass/fail gate. Every candidate that
> clears the minimal history/market-cap checks gets a `setup_score` (0–100); how that
> score is used (display, notification, watchlist propagation) is controlled by
> separate thresholds below.

### 1. Configuration

Unlike the EPS scanners, Buy Setup configuration is **stored in the database**
(`settings` table, key `buy_setup_config`) via `App\Services\Stocks\BuySetupConfigService`,
and editable live from the Web UI's config modal (see "Web UI" below). The `.env`
values and `config/market_data.php` are only the **fallback defaults** used before any
configuration has been saved from the UI — once saved, the database value always wins
over `.env`.

Seed the following into `.env` (see `.env.example` for the complete, current list):

```dotenv
BUY_SETUP_SCANNER_ENABLED=true
BUY_SETUP_MIN_MARKET_CAP=100000000
BUY_SETUP_MAX_MARKET_CAP=1000000000000
BUY_SETUP_MAX_SYMBOLS=4000
BUY_SETUP_EXCHANGES=NYSE,NASDAQ,TSX,TSXV,AMEX,OTC
BUY_SETUP_HISTORY_LOOKBACK_DAYS=504
BUY_SETUP_BENCHMARK_SYMBOLS=SPY,IWM

# Setup score gates: MIN_SETUP_SCORE is a UI filter only (every match is still saved).
# NOTIFY_MIN_SETUP_SCORE gates email delivery + "Setup" watchlist propagation.
BUY_SETUP_MIN_SETUP_SCORE=0
BUY_SETUP_NOTIFY_MIN_SETUP_SCORE=50

# Which of the 4 built-in setup types are active (only heartbeat ships enabled):
BUY_SETUP_TYPE_HEARTBEAT_CONSOLIDATION_SPIKE_ENABLED=true
BUY_SETUP_TYPE_RANGE_COMPRESSION_BREAKOUT_ENABLED=false
BUY_SETUP_TYPE_FLOOR_REVERSAL_ACCUMULATION_ENABLED=false
BUY_SETUP_TYPE_EARLY_BREAKOUT_FOLLOWTHROUGH_ENABLED=false

# Consolidation-base / spike thresholds:
BUY_SETUP_RECENT_SPIKE_WINDOW_DAYS=42
BUY_SETUP_SPIKE_LOOKBACK_DAYS=84
BUY_SETUP_MIN_BASE_DAYS=60
BUY_SETUP_MAX_BASE_DAYS=120
BUY_SETUP_MAX_RANGE_COMPRESSION_PCT=25
BUY_SETUP_MAX_ATR_RATIO=0.85

# "Sleepy volume" liquidity penalty per market-cap bucket, and an extra notification
# recipient in addition to per-user opt-ins — see .env.example for score-weight tuning:
BUY_SETUP_SLEEPY_VOLUME_LARGE_CAP_PENALTY_PCT=40
BUY_SETUP_SLEEPY_VOLUME_MEDIUM_CAP_PENALTY_PCT=30
BUY_SETUP_SLEEPY_VOLUME_SMALL_CAP_PENALTY_PCT=20
BUY_SETUP_SLEEPY_VOLUME_MICRO_CAP_PENALTY_PCT=15
BUY_SETUP_NOTIFICATION_EMAIL=
```

Every default above is also exposed in the **Buy Setup Configuration** modal in the Web
UI — per-setup-type thresholds, score weights (with a running "Active Weight Sum"),
sleepy-volume penalties, prior-year-revenue penalty tiers, Operating/FCF Margin
Expansion thresholds, the Growth Synergy Bonus, exchanges, benchmarks and the
notification email — with changes saved immediately and a "Reset to Defaults" action.

### 2. Database

Run the migrations to create the `stock_daily_bars` (per-symbol OHLCV history) and
`stock_buy_setup_alerts` tables:

```bash
php artisan migrate
```

Users opt in to Buy Setup emails and automatic watchlist propagation with the
`users.notify_stock_buy_setup` flag (toggle on the profile/settings page — mirrors
`notify_eps_earnings` / `notify_eps_revisions`).

### 3. Running the Scanner

```bash
# full screener sweep (all configured exchanges, up to the max-symbols cap)
php artisan stocks:scan-buy-setups

# restrict to one or more symbols (skips the screener entirely)
php artisan stocks:scan-buy-setups --symbol=AAPL --symbol=MSFT

# restrict the screener to specific exchange(s)
php artisan stocks:scan-buy-setups --exchange=NYSE
php artisan stocks:scan-buy-setups --exchange=NYSE,NASDAQ

# restrict the screener to symbols starting with a letter — used to slice a large
# universe into small, fast batches (see scripts/duoasset-setup-scan.sh below)
php artisan stocks:scan-buy-setups --exchange=NASDAQ --letter=A

# override the per-run symbol cap
php artisan stocks:scan-buy-setups --limit=500

# debug: process synchronously with a verbose per-symbol breakdown (spike rarity,
# base/range/ATR metrics, liquidity penalty, full score breakdown)
php artisan stocks:scan-buy-setups --symbol=AAPL --sync -vv
```

The command:

1. Loads the FMP company-screener, pre-filtered by market cap (widened to span every
   *enabled* setup type) and the configured exchanges — unless `--symbol` is given.
2. Dispatches one `EvaluateStockBuySetup` job per symbol (or runs it inline with
   `--sync`).
3. Each job incrementally fetches only the missing days of daily OHLCV bars (persisted
   in `stock_daily_bars`, keyed on `(symbol, bar_date)`, so re-runs stay cheap), loads a
   benchmark series (`SPY`/`IWM`) for relative strength, and — only when a configured
   score component actually needs it — 8 quarters of income/balance-sheet/cash-flow
   statements.
4. Runs `StockBuySetupScanner` over the bars for every enabled setup type and scores
   each match with `StockBuySetupScorer` plus a liquidity ("sleepy volume") penalty.
5. Idempotently upserts a `StockBuySetupAlert`, keyed on
   `(source, symbol, setup_type, spike_date)` — rescans refresh the score without
   duplicating rows.
6. When `setup_score >= notify_min_setup_score` (default 50), provisions the `Stock`
   row and appends it to the **"Setup"** watchlist (auto-created on first match) of
   every user with `notify_stock_buy_setup = true`, and — only the first time that
   alert is created — dispatches `SendStockBuySetupAlert` to deliver a
   `StockBuySetupDetected` notification.

Because the heavy work is queued by default, make sure a worker is running (or pass
`--sync` for a foreground/debug pass):

```bash
php artisan queue:work
```

### 4. Detection & Scoring

`StockBuySetupScanner` requires at least 252 daily bars (~52 weeks) and looks for a
trading day — within `recent_spike_window_days`/`spike_lookback_days` of today — whose
volume is a **52-week or 104-week high**, following a tight base of
`min_base_days`–`max_base_days` prior sessions. The spike is a *scored bonus, not a hard
gate*: if no qualifying 52w/104w high is found, the highest-volume day in the recent
window is still used as an anchor with 0 rarity points, so near-misses still surface
for review.

`StockBuySetupScorer` blends the technicals (spike rarity, base duration, price-range
compression, ATR contraction, volume dry-up, distance to breakout, moving-average
alignment, relative strength vs the benchmark) and fundamentals (EPS/sales
acceleration, Operating/FCF margin expansion) into a weighted 0–100 `setup_score`.
`StockBuySetupLiquidityPenalty` then discounts illiquid ("sleepy volume", i.e. low
turnover vs float) names by up to the configured per-market-cap-bucket penalty, before
an optional Growth Synergy Bonus is added back on top (capped at 100 overall).

> **Algorithm-aware scoring.** Scoring is shared across all four algorithms per setup
> type, but the `ma_alignment` component reinterprets its signal based on which
> algorithm the setup type is running (`BuySetupAlgorithmRegistry::isReversalStyle()`):
> trend-following algorithms still require the full bullish `50>150>200` stack, while
> `floor_reversal_accumulation` — a bottoming pattern that starts out *below* its
> long-term averages by design — instead earns full points for price simply reclaiming
> its 50-day average. See **[README_setup.md](README_setup.md)** for the full algorithm
> and scoring-weight tuning guide, including which other components (e.g.
> `relative_strength`) are worth de-weighting for reversal-style custom setup types.

Market-cap buckets used throughout: `micro` (< $300M), `small` (< $2B), `mid` (< $10B),
`large` (< $200B), `mega` (≥ $200B) — each setup type can define its own eligible
`min_market_cap`/`max_market_cap` range.

**Detection algorithm per setup type.** Each setup type selects *which* detection
algorithm actually runs via its own `algorithm` config value — independent of the
type's own key/label, so a custom setup type can run any of the four built-in
algorithms (or a saved config with no `algorithm` set falls back to Heartbeat, keeping
older configs behaving exactly as before). See `App\Services\Stocks\Algorithms\BuySetupAlgorithmRegistry`
and the "Detection Algorithm" dropdown in the config modal (Web UI, below):

| Algorithm | Idea | Distinct from Heartbeat by |
| --- | --- | --- |
| `heartbeat_consolidation_spike` (default) | Tight base + a rare 52w/104w high-volume spike day. | — |
| `range_compression_breakout` | A pure volatility squeeze (self-relative to the stock's own trailing-year range), breaking out on moderately elevated (not record) volume. | No historic-volume-record requirement; compression is percentile-ranked against the stock's own history, not a fixed cutoff. |
| `floor_reversal_accumulation` | A bottom after a decline: a tested "floor" with quiet up-volume > down-volume accumulation, optionally with bullish RSI/price divergence. | No spike at all — looks for a decline + floor + accumulation instead of a plateau near highs. Its `ma_alignment` score is also reinterpreted (see the callout above) since price legitimately sits below its long-term averages here. |
| `early_breakout_followthrough` | An O'Neil-style undercut day followed within a few sessions by a follow-through day (a solid % gain on above-average volume). | Catches a move in its first 1-3 days off a shorter base, instead of waiting for a mature multi-week base. |

See **[README_setup.md](README_setup.md)** for a deeper walkthrough of each algorithm's
detection logic and concrete advice on tuning `score_weights` per setup type.

### 5. Scheduler

`stocks:scan-buy-setups` is **not** registered in `routes/console.php`'s in-app
scheduler. It runs from the standalone `scripts/duoasset-setup-scan.sh` cron script,
which walks every configured exchange × letter A–Z
(`--exchange=X --letter=Y --sync -vv`) so each invocation only screens a small, fast
slice of the universe. See [Scripts](#scripts) below for the recommended crontab entry.

### 6. Web UI

Authenticated users can browse detected setups at:

```
/watchlist/stock-buy-setups
```

Features:

- Auto-refreshes every 30 seconds (`wire:poll.30s`).
- Filters: setup type, min score, min market cap, market-cap category, exchange,
  detected-date range, symbol/company search, "unwatched only".
- Columns: symbol, setup type, score, company, exchange, price, market cap, spike date,
  spike volume, base days, range %, detected time. Click a row for the full technical,
  fundamentals and score-breakdown detail.
- **Add to Watchlist** action — creates/reuses the "Setup" watchlist and appends the
  stock with a note summarizing the setup type, score and reason.
- A gear icon opens the **Buy Setup Configuration** modal described above.

### 7. Tests

```bash
./vendor/bin/pest tests/Feature/Commands/ScanBuySetupsDynamicConfigTest.php
./vendor/bin/pest tests/Unit/Stocks
./vendor/bin/pest tests/Feature/Watchlists/StockBuySetupConfigModalTest.php tests/Feature/Watchlists/StockBuySetupsSymbolTest.php
```

Covers: enabled/disabled scanner gating, per-setup-type market-cap ranges (inclusive
boundaries and the $50M–$1T fallback range), reason-summary text, score-component math
and dynamic per-type weights (including the algorithm-aware `ma_alignment` scoring in
`StockBuySetupScorerMaAlignmentTest`), the Growth Synergy Bonus, the cash-flow-fetch
optimization (only fetched when a configured weight needs it), the watchlist/config-modal
UI, and (under `tests/Unit/Stocks/Algorithms`) each of the four detection algorithms plus
`BuySetupAlgorithmRegistry`'s key resolution/fallback/reversal-style behavior.

## EPS Surprise Tracker (bidirectional)

DuoAsset includes two automated EPS scanners that watch companies on **NYSE, NASDAQ, TSX, and TSXV** via Financial Modeling Prep (FMP):

| Scanner | What it compares | Alerts |
| --- | --- | --- |
| **EPS Earnings Surprise** | Actual EPS vs estimated EPS *after* earnings | 🚀 EPS Earnings Beat (positive) / ⚠️ EPS Earnings Miss (negative) |
| **EPS Revision** | Latest analyst consensus EPS vs previously stored estimate *before* earnings | 📈 EPS Target Raised (positive) / 📉 EPS Target Cut (negative) |

Both scanners support **upside and downside** alerts driven by configurable positive / negative thresholds.

### 1. Configuration

Add the following keys to your `.env` (see `.env.example` for defaults):

```dotenv
FMP_API_KEY=your-fmp-api-key
MARKET_DATA_PROVIDER=fmp

# EPS Earnings Surprise scanner
EARNINGS_SCANNER_ENABLED=true
EARNINGS_SCANNER_MIN_MARKET_CAP=100000000
EARNINGS_SCANNER_MIN_EPS_SURPRISE_PERCENT=90
EPS_EARNINGS_POSITIVE_THRESHOLD=90      # Beat when surprise% >= this
EPS_EARNINGS_NEGATIVE_THRESHOLD=-30     # Miss when surprise% <= this

# EPS Revision scanner
EPS_REVISION_SCANNER_ENABLED=true
EPS_REVISION_MIN_MARKET_CAP=100000000
EPS_REVISION_POSITIVE_THRESHOLD=20      # Raised when revision% >= this
EPS_REVISION_NEGATIVE_THRESHOLD=-20     # Cut when revision% <= this
EPS_REVISION_MAX_SYMBOLS=2000           # soft cap on screener universe per run
```

All tunables live in `config/market_data.php`. The `min_eps_surprise_percent` and `min_market_cap` values are the gates the scanner uses before creating an alert; the configured exchange list (`NYSE`, `NASDAQ`, `TSX`, `TSXV`) is also enforced.

### 2. Database

Run the migrations to create the `earnings_events` and `earnings_alerts` tables:

```bash
php artisan migrate
```

### 3. Running the Scanner

Trigger the scanner manually:

```bash
# default window: yesterday → tomorrow
php artisan earnings:scan-surprises

# explicit date window
php artisan earnings:scan-surprises --from=2026-06-20 --to=2026-06-26

# reprocess existing rows (idempotent; will not double-alert)
php artisan earnings:scan-surprises --force
```

The command:

1. Pulls FMP earnings surprises (and the calendar as a fallback) for the window.
2. Upserts rows into `earnings_events` keyed by `(source, symbol, report_date)`.
3. Dispatches `EnrichEarningsEvent` jobs that fetch profile + quote, apply the market-cap / exchange / threshold gates, score the event, and create an `earnings_alert` row.
4. Dispatches `SendEarningsAlert` jobs which deliver an `EarningsSurpriseDetected` notification on the `database` and `mail` channels.

Because the heavy work is queued, make sure a worker is running:

```bash
php artisan queue:work
```

### 4. Scheduler

The scanner is wired into `routes/console.php` and runs automatically once Laravel's
scheduler is active (`php artisan schedule:run` on cron):

- Every **5 minutes** on weekdays between **06:00 – 18:00 America/Toronto**.
- A final sweep at **20:30 America/Toronto** to catch after-hours releases.

In production this currently runs instead via the standalone
`scripts/duoasset-earnings-scan.sh` cron script — see [Scripts](#scripts) for the full
crontab and why only one of the two mechanisms should be enabled at a time.

### 5. Health Check

```bash
php artisan earnings:scanner-health
```

Reports: presence of `FMP_API_KEY`, queue connection, provider reachability, latest scan time, and today's event/alert counts.

### 6. Web UI

Authenticated users can browse alerts at:

```
/watchlist/earnings-surprises
```

Features:

- Auto-refreshes every 30 seconds (`wire:poll.30s`).
- Filters: min EPS surprise %, min market cap, exchange, date range, "alerted only" toggle.
- Columns: detected time, symbol, company, exchange, market cap, EPS estimate / actual / surprise %, revenue surprise %, relative volume, score.
- **Add to Watchlist** action — finds-or-creates the `Stock` row (currency inferred from exchange) and appends it to the user's default `Watchlist` with a note:
  `Added from EPS surprise scanner: +X% EPS beat on YYYY-MM-DD.`
  Already-watched symbols show an **Already watched** badge.

### 7. Scoring

`App\Services\Earnings\EarningsSurpriseScorer` produces a prioritization score (alerts are still created for *every* qualifying event ≥ threshold and ≥ min market cap):

| Signal | Points |
| --- | --- |
| EPS surprise ≥ 88% | +40 |
| EPS surprise ≥ 150% | +15 |
| EPS surprise ≥ 300% | +15 |
| Market cap ≥ $100M | +10 |
| Market cap ≥ $1B | +10 |
| Revenue surprise > 0 | +10 |
| Revenue surprise ≥ 5% | +10 |
| Relative volume ≥ 2× | +10 |
| Relative volume ≥ 5× | +10 |

### 8. Tests

```bash
./vendor/bin/pest tests/Feature/EarningsSurpriseScannerTest.php
```

Covers EPS surprise math, zero-estimate handling, market-cap / exchange filters, duplicate-alert prevention, and threshold gating (all FMP calls are stubbed with `Http::fake`).

## EPS Revision Tracker

The EPS Revision scanner watches **analyst consensus EPS estimates for the next quarter** and alerts when the latest value crosses the configured positive/negative threshold versus the previously stored value.

### Symbol universe

Driven by the FMP **company-screener** endpoint, pre-filtered by `EPS_REVISION_MIN_MARKET_CAP` and the configured exchanges. No per-symbol API calls are made for tickers below the market-cap floor. The first run for each new symbol only stores a baseline snapshot in `eps_estimate_history`; alerts only start firing on subsequent runs once a previous value exists to compare against.

### Running the scanner

```bash
# poll the full universe (or whatever EPS_REVISION_MAX_SYMBOLS caps at)
php artisan earnings:scan-revisions

# restrict to specific symbols (skips the screener)
php artisan earnings:scan-revisions --symbol=AAPL --symbol=MSFT

# debug: process synchronously (no queue worker required)
php artisan earnings:scan-revisions --sync
```

The command dispatches one `CheckEpsRevisionForSymbol` job per qualifying symbol. Each job:

1. Fetches `/analyst-estimates?symbol=SYM&period=quarter`, picks the **next-quarter** row.
2. Looks up the prior stored estimate for the same `(symbol, period)`.
3. Computes `revision% = ((latest - previous) / |previous|) * 100`. **Skips when `previous` is null or `0`.**
4. If `revision% >= EPS_REVISION_POSITIVE_THRESHOLD` → creates an **EPS Target Raised** alert. If `<= EPS_REVISION_NEGATIVE_THRESHOLD` → creates an **EPS Target Cut** alert. Idempotent on the unique index `(source, symbol, next_quarter_end_date, alert_type, direction)`.
5. Always refreshes the `eps_estimate_history` snapshot so future runs compare against the latest observed value.
6. Dispatches `SendEpsRevisionAlert` to deliver the `EpsTargetRevised` notification on the `database` + `mail` channels.

### Scheduler

The revision scanner is scheduled twice per day in `routes/console.php`:

- **07:00 America/Toronto** (pre-market)
- **17:00 America/Toronto** (post-close)

on weekdays. Unlike the other scanners, there is **no standalone `scripts/*.sh` wrapper**
for `earnings:scan-revisions` (see [Scripts](#scripts)), so these alerts only fire if
`php artisan schedule:run` is actually enabled in cron.

### Web UI

Authenticated users can browse revision alerts at:

```
/watchlist/eps-revisions
```

Features:

- Auto-refreshes every 30 seconds.
- Filters: min |revision %|, min market cap, exchange, detected-date range, **direction (Both / Positive / Negative)**.
- Columns: detected time, symbol, company, exchange, period, previous estimate, latest estimate, label, revision %, market cap.
- Same **Add to Watchlist** behavior as the EPS Surprise page (auto-creates `Stock` + appends to default `Watchlist` with a contextual note).

The EPS Surprise page (`/watchlist/earnings-surprises`) also gained a **Direction** filter and a **Label** column showing 🚀 Beat or ⚠️ Miss per row.

### Tests

```bash
./vendor/bin/pest tests/Feature/EpsRevisionScannerTest.php
```

Covers: first-sight no-op, positive-direction alert, negative-direction alert, between-threshold no-op, duplicate-alert prevention, and zero previous-estimate handling. All FMP calls are stubbed with `Http::fake`.

## Sector Money Flows

DuoAsset also tracks where institutional money is rotating across sectors, using the
major North American sector ETFs as a top-down, price/volume-based proxy — a companion
to the bottom-up scanners above. Full details (terminology, the ETF universe, the
scoring pipeline, and known limitations) live in
[`docs/sector-money-flows.md`](docs/sector-money-flows.md); this section only covers
the day-to-day commands.

```bash
# End-of-day authoritative capture (all sectors)
php artisan moneyflow:update

# Intraday hourly capture (for intraday traders)
php artisan moneyflow:update --interval=hourly

# One or more sectors, with a per-sector result table
php artisan moneyflow:update --sector=technology --sector=energy --verbose-table

# Run even if disabled in config
php artisan moneyflow:update --force
```

Scheduled in `routes/console.php` (`America/New_York`): hourly intraday captures on
weekdays between 10:00–16:00 ET, plus an authoritative end-of-day capture at 17:15 ET.
In production this currently runs instead via the standalone
`scripts/duoasset-moneyflow-hourly.sh` / `scripts/duoasset-moneyflow.sh` cron scripts —
see [Scripts](#scripts).

- **Dashboard** — `App\Livewire\MoneyFlows\Index` at `/money-flows`, sortable by
  sector/strength/timeframe/velocity/acceleration/breadth/direction/rank.
- **Widget** — `App\Livewire\MoneyFlows\Widget`, embed with
  `<livewire:money-flows.widget />`.

## Troubleshooting

If your mail isn't going out, reset password will not work via email, you will want this:

`php artisan auth:reset-password <email> --password=<pass>`

## Scripts

The `scripts/` folder holds the standalone shell wrappers that drive the daily
production automation via cron, as an alternative to Laravel's `Schedule::` facade in
`routes/console.php`. Each one `cd`s into the app directory and calls `php artisan ...`
directly, so none of them depend on `php artisan schedule:run` being enabled.

| Script | Command it runs | Purpose |
| --- | --- | --- |
| `duoasset-setup-scan.sh` | `stocks:scan-buy-setups --exchange=X --letter=Y --sync -vv` looped over NYSE/NASDAQ/TSX/TSXV × A–Z | Full Stock Buy Setup sweep, one exchange/letter slice at a time so each screener call stays small and fast. |
| `duoasset-earnings-scan.sh` | `earnings:scan-surprises -vvv --from=<today-70d> --to=<today>` | EPS Earnings Surprise scan over a rolling 70-day window. |
| `duoasset-quote.sh` | `market-watch:update-quotes -vvv` | Refreshes Alpha Vantage quote data. |
| `duoasset-moneyflow-hourly.sh` | `moneyflow:update --interval=hourly -vv` | Intraday Sector Money Flow capture. |
| `duoasset-moneyflow.sh` | `moneyflow:update -vv` (defaults to `--interval=eod`) | End-of-day authoritative Sector Money Flow capture. |

Suggested crontab (adjust `/path/to/duoasset` and log paths; the hours below assume a
UTC server clock — shift them if your server uses a different timezone):

```cron
41 7 * * * /path/to/duoasset/scripts/duoasset-setup-scan.sh >> /path/to/duoasset/storage/logs/duoasset-buy-setups.log 2>&1
30 8 * * * /path/to/duoasset/scripts/duoasset-earnings-scan.sh >> /path/to/duoasset/storage/logs/duoasset-earnings-scan.log 2>&1
30 9 * * * /path/to/duoasset/scripts/duoasset-quote.sh >> /path/to/duoasset/storage/logs/duoasset-quotes.log 2>&1
30 13-20 * * 1-5 /path/to/duoasset/scripts/duoasset-moneyflow-hourly.sh >> /path/to/duoasset/storage/logs/duoasset-moneyflow-hourly.log 2>&1
30 20 * * 1-5 /path/to/duoasset/scripts/duoasset-moneyflow.sh >> /path/to/duoasset/storage/logs/duoasset-moneyflow.log 2>&1
#* * * * * cd /path/to/duoasset && php artisan schedule:run >> /dev/null 2>&1
```

> **Don't enable both mechanisms for the same command.** `routes/console.php` also
> registers `earnings:scan-surprises` and `moneyflow:update` on Laravel's own scheduler
> (see each feature's "Scheduler" section above), which only fires if
> `php artisan schedule:run` is enabled in cron (commented out above). Since the
> scripts in the table call the same artisan commands directly, pick **either** the
> per-script cron line **or** `schedule:run` for those two commands, not both, to avoid
> double-scanning and duplicate alerts. `stocks:scan-buy-setups` and
> `market-watch:update-quotes` have **no** `Schedule::` entry at all — they only run via
> their script line above. `earnings:scan-revisions` is the opposite: it has **no**
> script — it only runs if `schedule:run` is enabled.
