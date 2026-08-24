# Sector Money Flows

The Sector Money Flows engine estimates where institutional money is rotating by
monitoring the major North American **sector ETFs**. It is a top-down companion to
the existing bottom-up scanners (Relative Strength, Earnings/Revenue Acceleration,
Buy Setup) and produces a persisted, historical record of sector leadership.

> **This is a money-flow _proxy_.** It is derived entirely from sector ETF price
> and volume behaviour. It is **not** verified ETF net creations/redemptions or
> actual fund inflows. Treat it as a directional signal, not an accounting fact.

## Terminology

| Term | Meaning |
|------|---------|
| **Sector** | A money-flow grouping (e.g. `technology`), measured with ~5 provider ETFs. Config-driven; distinct from the app's GICS `sectors` taxonomy table (see `existing_sector_slug`). |
| **Timeframe** | The look-back window a metric is measured over: **hourly**, **daily**, **weekly**, **monthly**. Windows are in trading **bars/sessions**, never calendar days. |
| **Strength** | Composite 0–100 absolute score, blended across timeframes. Anchored to each ETF's own history — not to the other sectors. |
| **Velocity** | Δ score vs the previous same-cadence snapshot (per timeframe + composite). |
| **Acceleration** | Δ velocity vs the previous same-cadence snapshot. |
| **Rank / Percentile** | Cross-sectional standing across the sectors in one run. Kept separate from `strength` so a sector that is merely "less weak" than peers is not mistaken for strong. |
| **Issuer breadth** | % of a sector's valid ETFs outperforming the benchmark — agreement across issuers. |
| **Confidence** | Derived from how many of the ~5 ETFs produced usable data. |
| **Direction** | `accelerating` / `improving` / `stable` / `cooling` / `weakening`, from a pure classifier over strength + velocity + acceleration. |

## ETF configuration

The single source of truth is `config('market_data.sector_etfs')`. Each of the 11
GICS sectors lists ~5 issuer ETFs with a `weight` (so imperfect equivalents can be
down-weighted) and an optional `existing_sector_slug` mapping into the app's
taxonomy. Example:

```php
'technology' => [
    'label' => 'Technology',
    'existing_sector_slug' => 'technology',
    'etfs' => [
        'spdr'     => ['symbol' => 'XLK',  'weight' => 1.0],
        'vanguard' => ['symbol' => 'VGT',  'weight' => 1.0],
        'ishares'  => ['symbol' => 'IYW',  'weight' => 1.0],
        'invesco'  => ['symbol' => 'RSPT', 'weight' => 1.0],
        'fidelity' => ['symbol' => 'FTEC', 'weight' => 1.0],
    ],
],
```

Engine settings live under `config('market_data.moneyflow')`: benchmark symbol
(`SPY`), history lookback, market timezone, per-timeframe `periods`, intraday
settings, scoring/normalization weights, confidence floors, and direction bands.
All are env-overridable (`MONEYFLOW_*`).

## Command usage

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

Options: `--sector=*`, `--interval=eod|hourly`, `--force`, `--verbose-table`.
The command runs the whole-market pass **synchronously** (no per-symbol job
fan-out) because ranking and benchmarking are cross-sectional. It returns a
non-zero exit code only when it could not publish any sector.

## Scheduler

Registered in `routes/console.php`, all in the market timezone (`America/New_York`):

- **Hourly** intraday captures, weekdays 10:00–16:00 ET (a few minutes past the
  hour so each 1-hour bar has closed).
- **End-of-day** authoritative capture, weekdays 17:15 ET, after U.S. market data
  is finalized.

## How a snapshot is built

One capture per sector per trading date per slot
(`UNIQUE(sector, snapshot_date, captured_slot)`; `interval` = `eod` or `hourly`).

1. **Fetch** daily bars (all timeframes) and 1-hour intraday bars (hourly
   timeframe) for each ETF and the benchmark, via the existing
   `FmpMarketDataProvider` (no duplicate HTTP client).
2. **Per ETF** (`SectorEtfMetricsCalculator`): for each timeframe compute the
   period return, relative strength vs the benchmark, relative volume and
   relative dollar volume, then normalize each into a 0–100 component score
   against the ETF's **own** history (return vs own volatility; volume vs own
   average) and blend into a per-timeframe score.
3. **Aggregate** (`SectorFlowAggregator`): weighted-average the ETFs into one
   sector result per timeframe, plus issuer breadth, confidence, and a
   `constituents` breakdown (including which ETF failed and why).
4. **Compose** (`SectorFlowScorer`): blend timeframe scores into `strength`.
5. **Rank** cross-sectionally across the sectors in the run (`rank`,
   `percentile_rank`) — kept separate from the absolute scores.
6. **Motion**: velocity (Δscore) and acceleration (Δvelocity) vs the previous
   same-cadence snapshot; each timeframe independent. First snapshot → null
   velocity; velocity present but no prior velocity → null acceleration.
7. **Classify** (`SectorFlowDirectionClassifier`) and **persist** transactionally
   (`SectorFlowSnapshotRepository`).

Sectors with fewer than `confidence.min_etfs_to_publish` valid ETFs (default 3)
are **skipped**, not published with a misleadingly thin score.

## Dashboard, widget & data source

- **Dashboard** — `App\Livewire\MoneyFlows\Index` at `/money-flows`
  (`money-flows.index`). Sortable table: Sector, Strength, 1H/1D/1W/1M, Velocity,
  Acceleration, Breadth, Direction, Rank. Toggle between end-of-day and hourly.
- **Widget** — `App\Livewire\MoneyFlows\Widget`, embed with
  `<livewire:money-flows.widget />`. Shows leading / accelerating / cooling
  sectors and the latest capture time; clicking opens a modal with the full
  ranking.

Both read **only persisted snapshots** — they never recalculate market data or
call FMP at render/poll time.

## Limitations

- Money-flow **proxy** from price/volume, not verified fund flows.
- ETF weights and windows are heuristics; tune via config as real scores are
  observed.
- Cross-sectional percentile can rate a sector highly for being the least weak in
  a broadly weak tape — always read `strength` (absolute) alongside `rank`.
- Hourly metrics depend on the FMP intraday endpoint being available on the plan;
  when it is not, hourly timeframes are simply absent and the other timeframes
  still publish.
- Phase 1 is North American, predominantly U.S.-listed ETFs; Canadian sector ETFs
  are a future phase. Email alerts are intentionally deferred until real score
  thresholds are observed.
