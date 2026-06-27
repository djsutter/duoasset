
# DuoAsset

## Market Data Updates

In order to trigger alpha vantage stock updates, run this:

```bash
php artisan market-watch:update-quotes
```

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

The scanner is wired into `routes/console.php` and runs automatically once the Laravel scheduler is active:

- Every **5 minutes** on weekdays between **06:00 – 18:00 America/Toronto**.
- A final sweep at **20:30 America/Toronto** to catch after-hours releases.

Enable it via cron:

```cron
* * * * * cd /path/to/duoasset && php artisan schedule:run >> /dev/null 2>&1
```

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

on weekdays.

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
