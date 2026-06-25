
# DuoAsset

## Market Data Updates

In order to trigger alpha vantage stock updates, run this:

```bash
php artisan market-watch:update-quotes
```

## EPS Surprise Tracker

DuoAsset includes an automated earnings screener that finds companies on **NYSE, NASDAQ, TSX, and TSXV** reporting large EPS beats and raises an alert as soon as new qualifying earnings data appears. Financial Modeling Prep (FMP) is the primary data provider.

### 1. Configuration

Add the following keys to your `.env` (see `.env.example` for defaults):

```dotenv
FMP_API_KEY=your-fmp-api-key
MARKET_DATA_PROVIDER=fmp
EARNINGS_SCANNER_ENABLED=true
EARNINGS_SCANNER_MIN_MARKET_CAP=100000000
EARNINGS_SCANNER_MIN_EPS_SURPRISE_PERCENT=90
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
