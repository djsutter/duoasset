# DuoAsset — Futures Support & Interactive News Timeline

## Overview

DuoAsset's instrument dashboard (`App\Livewire\Stocks\Show` and `resources/views/livewire/stocks/show.blade.php`) serves both equities and commodity futures on a shared architecture powered by `LuxAlgo/Vela` (`@luxalgo/vela` v0.9.0) and Financial Modeling Prep (`FmpMarketDataProvider` / `PersistentMarketDataProvider`).

### Canonical Routing & Permanent Redirects

| Route | Behavior |
|---|---|
| `/stock/{symbol}` & `/stocks/{symbol}` | Displays the equity dashboard for stocks (`aapl`, `nbis`, `arvn`, `rare`, `feo.v`). Permanently redirects (`301`) to `/futures/{lowercase_symbol}` when `{symbol}` is classified as a futures contract. |
| `/futures/{symbol}` | Displays the futures dashboard (`gcusd`, `siusd`, `clusd`, `ngusd`, `hgusd`, etc.). Permanently redirects (`301`) to `/stock/{lowercase_symbol}` if `{symbol}` is classified as an equity. |
| `/{stock\|stocks\|futures}/{symbol}/candles` | Returns normalized OHLCV candles (`1min`, `5min`, `15min`, `30min`, `1hour`, `4hour`, `1day`, `1week`, `1month`). |
| `/{stock\|stocks\|futures}/{symbol}/news` | Returns relevance-filtered, deduplicated news events and Vela timeline markers for the requested date/time window or candle investigation window. |
| `/{stock\|stocks\|futures}/{symbol}/news-impact` | Returns observed historical price changes across `5m`, `15m`, `30m`, `60m`, and `4h` horizons following an event timestamp, alongside related market comparisons. |

---

## FMP Gold Futures (`GCUSD`) Market Data Verification & Limitations

1. **Instrument Discovery**:
   - FMP's `/stable/commodities-list` returns 40 futures contracts (including `GCUSD`, `MGCUSD`, `SIUSD`, `SILUSD`, `CLUSD`, `BZUSD`, `NGUSD`, `HGUSD`, `PLUSD`, `PAUSD`, `ESUSD`, `NQUSD`, `ZBUSD`, `ZNUSD`, `DXUSD`, etc.) with `symbol`, `name`, `tradeMonth`, and `currency`.
   - FMP's `/stable/quote?symbol=GCUSD` returns `exchange: "COMMODITY"` with live/delayed price, change, volume, day/year high-low, and open/previous close.
   - FMP's `/stable/profile?symbol=GCUSD` returns `[]` because futures contracts do not have corporate profiles; `FmpMarketDataProvider::quote()` skips redundant `/profile` and `/stock-price-change` requests when `exchange === "COMMODITY"`.

2. **Historical Depth & Intraday Retention**:
   - **Daily (`1day`)**: `/stable/historical-price-eod/full?symbol=GCUSD` provides 5,000+ daily bars per request (dating back to August 2007 in a single call).
   - **Intraday (`1min`, `5min`, `15min`, `30min`, `1hour`, `4hour`)**: All six intraday intervals are supported on the FMP Premium subscription. Because CME Globex futures trade 23 hours/day (Sunday 18:00 ET – Friday 17:00 ET with a daily 17:00–18:00 ET maintenance break), a single intraday API response contains:
     - `1min`: ~3,700–4,100 bars (~3 trading sessions per request window).
     - `5min`: ~2,100–2,200 bars (~8–10 trading sessions per request window).
     - `15min`: ~3,000 bars (~6 weeks per request window).
     - `30min`: ~1,000 bars (~4 weeks per request window).
     - `1hour`: ~1,500 bars (~3 months per request window).
     - `4hour`: ~765 bars (~6 months per request window).
   - **Most useful intervals**: `5m`, `15m`, `1h`, `4h`, and `1D` provide the best balance of intraday granularity and historical coverage for futures analysis.

3. **Continuous Contract & Rollover Behavior**:
   - `GCUSD` represents an **unadjusted front-month continuous contract** on COMEX (quoted in `USD / troy oz`, standard contract size `100 troy oz`).
   - When the active front delivery month rolls (typically late in the month preceding Feb, Apr, Jun, Aug, Oct, Dec delivery months), FMP splices the new active contract without back-adjusting prior historical bars. The dashboard explicitly surfaces the active `Front Contract` month and rollover note.

---

## LuxAlgo/Vela v0.9.0 Integration Capabilities

`resources/js/stock-chart.js` integrates with the installed `@luxalgo/vela` v0.9.0 public APIs without modifying Vela internals:

- **Timeline Markers (`ws.chart.marks`)**:
  - Uses `chart.marks.defineGroup({ id: 'news', label: 'News Events', visible: true })`, `chart.marks.set(marks)`, and `chart.marks.setGroupVisible('news', visible)`.
  - Vela natively clusters overlapping markers that land on the same bar or overlap at the current zoom level.
  - Markers omit inline popup `content` so clicking any glyph fires `chart.on('mark:click', ...)` directly into DuoAsset's news timeline and price-impact panel.
- **Viewport Synchronization (`ws.chart.on('viewport:changed', ...)` & `setVisibleRange`)**:
  - Panning or zooming the chart emits `viewport:changed` with `{ from, to }` in epoch milliseconds. When **Follow Chart Range** is enabled, the news timeline automatically narrows to the visible window and fetches historical news when scrolling into earlier periods.
  - Clicking a news item calls `chart.setVisibleRange({ from, to })` to frame a window around the event timestamp and highlights the event candle via `chart.renderer.setExternalCrosshair(timeMs, closePrice)`.
- **Candle Click Investigation (`ws.chart.renderer.onClick`)**:
  - Clicking any candle on the price chart captures the bar's timestamp and price move, revealing the **Show related news** action to search for stories published before/during that movement alongside a clearly badged **Look-Ahead (+2h)** window.

---

## Audit Root-Cause Summary & Repairs (November 2026)

1. **Primary Root Cause — Missing Delegation on `PersistentMarketDataProvider`**:
   - `MarketDataServiceProvider` binds `MarketDataProvider::class` to `PersistentMarketDataProvider(new FmpMarketDataProvider(...))` in development and production (`MARKET_DATA_PROVIDER=fmp`).
   - While `FmpMarketDataProvider` and `FakeMarketDataProvider` implemented `stockNews()`, `forexNews()`, `generalNews()`, `pressReleases()`, and `economicCalendar()`, `PersistentMarketDataProvider` did not implement those five methods.
   - Because `InstrumentNewsService` checked `method_exists($this->provider, ...)`, all five FMP news methods silently evaluated to `false` in production/development, returning `0` articles for all stocks (`NBIS`, `MASS`, `CRON`, `AAPL`) and skipping all FMP commodity/forex/macro feeds for futures (`GCUSD`).
   - **Fix**: Added all five delegation methods to `PersistentMarketDataProvider`, added upstream fallback and `missing_provider_method` error reporting in `InstrumentNewsService`, and updated `FmpMarketDataProvider` so upstream `null` error responses are never cached as empty arrays.

2. **Secondary Root Causes — Profile Resolution, Lookback Window & Disambiguation**:
   - `InstrumentClassifier::resolve()` previously did not load company names from local `company_profiles`/`stocks` or `provider->profile()` when called without pre-fetched profile arguments, leaving `$instrument['name']` null during news scoring.
   - The default 30-day lookback window discarded August and September company releases (such as `MASS` and `CRON` Q2 earnings and conferences) even though the default Vela chart range is `1Y` (365 days).
   - FMP's symbol feeds contained cross-exchange ticker collisions (`Masivo Silver Corp. (TSXV: MASS)` under `MASS`), entity name collisions (`Baker Hughes` *Cronos Gas* project under `CRON`), untargeted market roundups under `NBIS`, and routine foreign FX pair forecasts (`EUR/USD`, `AUD/USD`) under futures.
   - **Fix**: Updated `InstrumentClassifier::resolve()` to load company metadata, widened `default_lookback_days` to `365`, implemented foreign-exchange ticker collision guards, company/ticker identity verification, and per-commodity relevance profiles (`gold`, `silver`, `crude_oil`, `natural_gas`, `copper`).

---

## Local Ollama LLM Model Evaluation

We benchmarked the local Ollama instance on a representative 6-headline test suite containing genuine corporate/commodity stories alongside real FMP false positives (`Baker Hughes ... Cronos Gas` for `CRON`, `Masivo Silver (TSXV: MASS)` for `MASS`, and `EUR/USD is headed towards 1.1300` for `GCUSD`):

| Model | VRAM Size | Accuracy | False-Positive Rate | False-Negative Rate | Batch Latency (6 items) | Notes & Recommendation |
|---|---|---|---|---|---|---|
| `qwen3:8b` (`think: false`) | 5.23 GB | **100% (6/6)** | **0%** | **0%** | **9.4 s** (~1.5 s/item) | **Recommended default** when optional AI enrichment is enabled. Best latency-to-accuracy ratio and low VRAM footprint. |
| `ministral-3:14b` | 9.08 GB | **100% (6/6)** | **0%** | **0%** | **15.3 s** (~2.5 s/item) | Accurate disambiguation and concise rationales; ~60% slower than `qwen3:8b`. |
| `qwen3.8:27b-q4_K_M` (`think: false`) | 17.74 GB | **100% (6/6)** | **0%** | **0%** | **20.6 s** (~3.4 s/item) | High-quality rationales (`fallback_model`), requires `"think": false` so token budget is not consumed by internal reasoning tokens. |
| `granite4:3b` | 2.10 GB | 83.3% (5/6) | 16.7% | 0% | 5.1 s | Fast, but hallucinated a business connection between Baker Hughes's *Cronos Gas* project and *Cronos Group Inc.* |
| `gemma4:12b` | 7.56 GB | N/A | N/A | N/A | 16.7 s | Emits repetitive `"thought"` JSON keys under strict `format: "json"` mode without thinking suppression. |
| `granite4.1:30b` | 17.49 GB | Timeout | N/A | N/A | >30.0 s | Exceeded 30-second batch timeout on cold load. |

**Design conclusion**: Deterministic disambiguation and relevance scoring in `InstrumentNewsService` executes in `< 5 ms` with 100% accuracy on these collisions and is used as the primary, zero-dependency engine. `OllamaNewsEnricher` (`INSTRUMENT_NEWS_AI_ENABLED=false` by default) is provided as an optional asynchronous enrichment layer (`qwen3:8b` / `qwen3.8:27b-q4_K_M` with `think: false`) that caches article-level rationales in shared storage without ever blocking timeline rendering.
