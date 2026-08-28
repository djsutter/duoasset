
# DuoAsset

## Contents

- [Market Data Updates](#market-data-updates)
- [Stock Buy Setup Scanner](#stock-buy-setup-scanner)
- [EPS Surprise Tracker (bidirectional)](#eps-surprise-tracker-bidirectional)
- [EPS Revision Tracker](#eps-revision-tracker)
- [Sector Money Flows](#sector-money-flows)
- [Scripts](#scripts)

## Market Data Updates

In order to trigger alpha vantage stock updates, run this:

```bash
php artisan market-watch:update-quotes
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
| `floor_reversal_accumulation` | A bottom after a decline: a tested "floor" with quiet up-volume > down-volume accumulation, optionally with bullish RSI/price divergence. | No spike at all — looks for a decline + floor + accumulation instead of a plateau near highs. |
| `early_breakout_followthrough` | An O'Neil-style undercut day followed within a few sessions by a follow-through day (a solid % gain on above-average volume). | Catches a move in its first 1-3 days off a shorter base, instead of waiting for a mature multi-week base. |

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
and dynamic per-type weights, the Growth Synergy Bonus, the cash-flow-fetch
optimization (only fetched when a configured weight needs it), the watchlist/config-modal
UI, and (under `tests/Unit/Stocks/Algorithms`) each of the four detection algorithms plus
`BuySetupAlgorithmRegistry`'s key resolution/fallback behavior.

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
