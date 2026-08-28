# Stock Buy Setup — Algorithm & Scoring Guide

This is a companion to the ["Stock Buy Setup Scanner"](README.md#stock-buy-setup-scanner)
section of the main README. It goes one level deeper into **how each detection
algorithm works** and **how to tune the scoring weights** for a given setup type,
since both are configurable independently of each other. If you haven't read the
main README section yet, start there for the overall pipeline (screener → job →
alert → notification → watchlist); this document assumes that context.

## Contents

- [1. Setup type vs. algorithm](#1-setup-type-vs-algorithm)
- [2. The four algorithms](#2-the-four-algorithms)
- [3. Choosing an algorithm for a setup type](#3-choosing-an-algorithm-for-a-setup-type)
- [4. Scoring: how `setup_score` is built](#4-scoring-how-setup_score-is-built)
- [5. Algorithm-aware scoring (and what was "intentionally deferred")](#5-algorithm-aware-scoring-and-what-was-intentionally-deferred)
- [6. Weight-tuning playbook](#6-weight-tuning-playbook)
- [7. Code map](#7-code-map)
- [8. Testing your changes](#8-testing-your-changes)

## 1. Setup type vs. algorithm

A **setup type** (`heartbeat_consolidation_spike`, `range_compression_breakout`,
`floor_reversal_accumulation`, `early_breakout_followthrough`, or any custom type you
add in the Web UI) is a *named configuration bundle*: its own enabled flag, base-length
thresholds, sleepy-volume penalties, market-cap range, score weights, and — the part
this guide is about — its own **`algorithm`** value.

The `algorithm` value selects which detection logic actually runs, via
`App\Services\Stocks\Algorithms\BuySetupAlgorithmRegistry`. This is deliberately
decoupled from the setup type's own key/label:

- A saved config with no `algorithm` (or an unrecognized one) falls back to
  `heartbeat_consolidation_spike` — the original, always-available detector — so older
  configs keep behaving exactly as before.
- You can point a **custom** setup type at any of the four built-in algorithms. For
  example, you could create a setup type called `"Small Cap Reversals"` that runs the
  `floor_reversal_accumulation` algorithm but with its own market-cap range and score
  weights, completely independent of the built-in `floor_reversal_accumulation` setup
  type.
- New custom setup types created via the "+ Add Setup Type" button in the config modal
  inherit the `heartbeat_consolidation_spike` template's algorithm by default — change
  it via the "Detection Algorithm" dropdown after creating the type.

## 2. The four algorithms

All four algorithms operate on the same daily OHLCV bars already fetched by
`EvaluateStockBuySetup` (no extra FMP endpoint), require **≥ 252 bars** (~52 weeks) of
history as a hard gate, and apply the setup type's `min_market_cap`/`max_market_cap`
range as a second hard gate. Everything else described below is a **scored signal, not
a rejection gate** — a near-miss still produces a result with a lower score rather than
disappearing.

### 2.1 `heartbeat_consolidation_spike` — Heartbeat Consolidation + Spike

The original algorithm (lives in `StockBuySetupScanner::evaluate()`, wrapped by
`HeartbeatConsolidationSpikeAlgorithm`). Looks for a **rare high-volume day** (a
52-week or 104-week volume record) following a **tight, multi-week base** near the top
of its recent range.

| Config key | Default | Meaning |
| --- | --- | --- |
| `recent_spike_window_days` | 60 | How far back to look for the anchor day when no qualifying spike exists. |
| `max_spike_age_days` | 84 | How far back to search for a *qualifying* 52w/104w spike (capped at 504 bars internally). |
| `min_base_days` / `max_base_days` | 45 / 120 | Consolidation-base length bounds immediately before the spike. |
| `max_range_compression_pct` | 40.0 | Used by the config UI's validation, not a hard gate in detection itself — the actual range % is scored via `score_weights.range_compression`. |
| `max_atr_ratio` | 0.85 | Same — informational ceiling, scored via `score_weights.atr_contraction`. |

Best fit: momentum breakouts near highs — the "textbook" pattern.

### 2.2 `range_compression_breakout` — Range Compression Breakout

A pure volatility-squeeze breakout (TTM-Squeeze style) with **no volume-record
requirement**. Instead of a rare spike, it looks for the base's range/ATR compression
sitting at a *self-relative* extreme (percentile-ranked against the stock's own last
~252 days), breaking out on only moderately elevated volume.

| Config key | Default | Meaning |
| --- | --- | --- |
| `min_base_days` / `max_base_days` | 45 / 120 | Same as Heartbeat. |
| `squeeze_percentile` | 20.0 | The base's compression must rank in the bottom N% of its own trailing history to qualify as a "squeeze". Lower = stricter/rarer. |
| `breakout_volume_multiplier` | 1.3 | Breakout-day volume must be ≥ this × the base's own sleepy average — deliberately much lower than Heartbeat's "historic record" bar. |
| `recent_spike_window_days` | 60 | Search window for the breakout day. |

> ⚠️ `squeeze_percentile` and `breakout_volume_multiplier` are **not yet exposed** in
> the config UI or `.env` — they currently only have hardcoded defaults inside
> `RangeCompressionBreakoutAlgorithm`. If you need to tune them per setup type, add the
> keys directly to that setup type's saved JSON config (`settings` table, key
> `buy_setup_config`) or extend the config service/UI to expose them (a natural
> follow-up, not yet built).

Best fit: tighter, more frequent setups than Heartbeat — catches names that squeeze
often but never post a historic-record volume day.

### 2.3 `floor_reversal_accumulation` — Floor Reversal / Accumulation

The structural opposite of Heartbeat: a **bottoming pattern after a decline**, not a
plateau near highs. No spike is required at all — accumulation here is meant to be
quiet.

| Config key | Default | Meaning |
| --- | --- | --- |
| `min_base_days` / `max_base_days` | 45 / 120 | Length of the "floor" base window. |
| `decline_lookback_days` | 90 | How far back (before the base) to measure the prior decline. |
| `min_decline_pct` | 15.0 | Decline % (from the lookback's high to the base's low) needed to call this a "qualifying" reversal — scored in buckets, not a hard gate. |
| `floor_touch_tolerance_pct` | 3.0 | A bar's low counts as a "floor touch" if it's within this % of the base's own low. |
| `floor_touch_min_gap_days` | 5 | Minimum bar gap between two counted touches, so one long dip isn't double-counted. |
| `recent_spike_window_days` | 60 | Window searched for a confirmation/recovery day (closing back above the base high on above-average volume) — if found, it becomes the "anchor day"; otherwise the algorithm still returns a result anchored to the base's last bar with 0 confirmation credit. |

> ⚠️ Same caveat as above: `decline_lookback_days`, `min_decline_pct`,
> `floor_touch_tolerance_pct`, and `floor_touch_min_gap_days` are hardcoded defaults
> inside `FloorReversalAccumulationAlgorithm`, not yet exposed via the config UI/`.env`.

The 0–7 point score (`spikeRarityPoints`, reused for compatibility with
`StockBuySetupScorer`) combines: prior-decline strength, floor-touch count,
up-volume/down-volume accumulation ratio, plus small bonus points for a confirmed
recovery day and/or a bullish RSI/price divergence.

Best fit: value/turnaround style names — see [§5](#5-algorithm-aware-scoring-and-what-was-intentionally-deferred)
for how scoring itself is adapted for this algorithm.

### 2.4 `early_breakout_followthrough` — Early Breakout Follow-Through

O'Neil-style **follow-through-day** logic: catches a move in its **first 1–3 days**
rather than after a mature multi-week base. Uses a shorter/looser base, an "undercut"
day (a fresh short-term low), then a follow-through day within a few sessions.

| Config key | Default | Meaning |
| --- | --- | --- |
| `min_base_days` / `max_base_days` | 45 / 120 | Base window preceding the undercut day. |
| `undercut_lookback_days` | 10 | The undercut day's low must be ≤ the minimum low of this many preceding bars. |
| `followthrough_max_days` | 4 | The follow-through day must occur within this many sessions *after* the undercut day. |
| `followthrough_min_gain_pct` | 1.5 | Minimum close-over-close gain % for the follow-through day. |
| `followthrough_volume_multiplier` | 1.25 | Follow-through volume must be ≥ this × its own trailing 50-bar average. |
| `recent_spike_window_days` | 60 | How far back to search for a qualifying undercut day. |

If no qualifying undercut+follow-through pair is found, the algorithm still returns a
"still forming, no follow-through confirmed yet" result (0 rarity points) rather than
rejecting, anchored to the most recent base window.

> ⚠️ Same caveat: `undercut_lookback_days`, `followthrough_max_days`,
> `followthrough_min_gain_pct`, and `followthrough_volume_multiplier` are hardcoded
> defaults inside `EarlyBreakoutFollowThroughAlgorithm`, not yet exposed via the config
> UI/`.env`.

Best fit: catching moves early, at the cost of more false starts than a mature-base
algorithm.

## 3. Choosing an algorithm for a setup type

1. Open the **Buy Setup Configuration** modal (gear icon on `/watchlist/stock-buy-setups`).
2. Select (or create) a setup type.
3. Use the **"Detection Algorithm"** dropdown to pick one of the four algorithms above —
   independent of the setup type's own name.
4. Tune that setup type's `min_base_days`/`max_base_days`, market-cap range, sleepy-volume
   penalties, and score weights as usual. Algorithm-specific knobs marked with ⚠️ above
   are not yet in this modal — see the note in each subsection.
5. Save. The very next scan run (`stocks:scan-buy-setups`) uses the new algorithm for
   that setup type.

Programmatically: `BuySetupConfigService::getSetupAlgorithm($setupType)` resolves the
effective algorithm key (with the safe fallback described in §1), and
`StockBuySetupScanner::evaluateAll()` dispatches to
`BuySetupAlgorithmRegistry::resolve($key)->detect(...)` for every enabled setup type.

## 4. Scoring: how `setup_score` is built

Regardless of which algorithm produced a `StockBuySetupResult`, exactly one scorer —
`App\Services\Stocks\StockBuySetupScorer` — turns it into a 0–100 `setup_score`. This
is intentional: the four algorithms only differ in *how they compute the raw technical
fields* (spike/anchor day, base window, range/ATR/volume-dry-up numbers, MA alignment,
relative strength); the scoring formula, weights, and thresholds are 100% shared and
configured per setup type via `score_weights`.

`breakdown()` computes `{points, max}` for each component below; `scoreFromBreakdown()`
sums all `points` and all `max` and normalizes: `round(sum(points) / sum(max) * 100)`.
This means weights can total 80, 100, 130, or anything else — the displayed score
always stays 0–100.

| Component | Default weight | Enabled by default | What it scores |
| --- | --- | --- | --- |
| `spike_rarity` | 25 | ✅ | The algorithm's own 0–7 "how rare/strong is the anchor event" score, scaled to the weight. |
| `base_duration` | 10 | ✅ | Longer bases score higher (full at ≥90 days). |
| `range_compression` | 15 | ✅ | Tighter base range % scores higher (full at ≤10%). |
| `atr_contraction` | 10 | ✅ | ATR shrinking late-base vs early-base scores higher (full at ≤0.60 ratio). |
| `volume_dry_up` | 10 | ✅ | Lower base volume vs the 60 days before it scores higher. |
| `breakout_distance` | 10 | ✅ | Closer to the base high (either side) scores higher. |
| `ma_alignment` | 10 | ✅ | See [§5](#5-algorithm-aware-scoring-and-what-was-intentionally-deferred) — algorithm-aware. |
| `relative_strength` | 10 | ✅ | 6-month return vs benchmark (SPY/IWM) — higher is better. |
| `earnings_acceleration` | 5 | ✅ | Logarithmic curve, scale configurable (`acceleration_scales.earnings_acceleration`, default 75). |
| `sales_acceleration` | 5 | ✅ | Same, scale default 3000; also subject to the prior-year-revenue penalty tiers. |
| `operating_margin_expansion` | 10 | ✅ (heartbeat only) | TTM YoY operating-margin expansion, interpolated from `operating_margin_expansion_thresholds` (bps). |
| `fcf_margin_expansion` | 10 | ✅ (heartbeat only) | Same, for free-cash-flow margin. Enabling this adds one extra FMP cash-flow-statement call per symbol per scan (`BuySetupConfigService::isCashFlowDataNeeded()`). |

On top of the normalized 0–100 score, `StockBuySetupLiquidityPenalty` discounts illiquid
("sleepy volume") names by up to the setup type's configured per-market-cap-bucket
penalty, and an optional **Growth Synergy Bonus** (disabled by default) can then add a
few points back (capped at 100 overall) when sales acceleration, operating-margin
expansion, and FCF-margin expansion all confirm strong growth quality simultaneously.

## 5. Algorithm-aware scoring (and what was "intentionally deferred")

When the four-algorithm system was first designed, the scoring formula was deliberately
kept **uniform** across all algorithms for a given setup type, to keep that first phase
scoped. The known rough edge, called out at the time as *intentionally deferred*, was:

> `ma_alignment` rewards a bullish `50>150>200` moving-average stack with price above
> its 50-day average. That's the right signal for a *continuation* setup (Heartbeat,
> Range Compression Breakout, Early Breakout Follow-Through — all occur near/above
> prior highs), but it's backwards for `floor_reversal_accumulation`: that algorithm's
> entire premise is a decline *followed by* a base, so price legitimately sits *below*
> its long-term averages. Under the old uniform formula, a textbook floor-reversal
> setup could never earn full `ma_alignment` points, capping its achievable score below
> what an equally strong Heartbeat setup could reach.

**This has now been un-deferred.** `StockBuySetupScorer::maAlignmentPoints()` is
algorithm-aware via `BuySetupAlgorithmRegistry::isReversalStyle($algorithm)`:

- **Trend-following algorithms** (the default — Heartbeat, Range Compression Breakout,
  Early Breakout Follow-Through, and any future/custom algorithm not explicitly flagged
  as reversal-style) keep the original rule: full points require the full bullish
  `50>150>200, price>50` stack; a `50>200` cross without full alignment earns half
  credit; anything else earns zero.
- **Reversal-style algorithms** (currently just `floor_reversal_accumulation`) score
  their own, more meaningful signal instead: **full points** for price simply
  reclaiming its **50-day** average (`price>50`), regardless of the longer-term stack —
  this is the earliest, most direct sign the decline is reversing. A `50>200` cross
  *without* `price>50` still earns half credit (medium-term trend improving but
  unconfirmed). A still-declining alignment with price below its 50-day average earns
  zero, same as before.

This is implemented purely in the scorer (`app/Services/Stocks/StockBuySetupScorer.php`)
and the registry (`app/Services/Stocks/Algorithms/BuySetupAlgorithmRegistry.php`) — no
detection logic changed, so existing Heartbeat/Range Compression/Early Breakout scores
are completely unaffected. Coverage lives in
`tests/Unit/Stocks/StockBuySetupScorerMaAlignmentTest.php`.

### A related component that is *not* yet algorithm-aware: `relative_strength`

`relative_strength` has the same semantic tension as `ma_alignment` did: it rewards a
stock that has already **outperformed** its benchmark over the last 6 months
(full points at RS ≥ +10). A beaten-down name in the middle of a `floor_reversal_accumulation`
setup will often still show *negative* relative strength — the decline hasn't fully
reversed into outperformance yet — so this component will frequently score 0 for
genuinely valid floor-reversal setups.

This was **not** changed in this pass (only `ma_alignment` was the item explicitly
flagged as deferred). If you're running `floor_reversal_accumulation` in production and
find `relative_strength` is dragging otherwise-strong setups down, the recommended
mitigation today is the manual one in [§6](#6-weight-tuning-playbook) below
(lower/disable the weight for that setup type) rather than a code change — a future
`isReversalStyle()`-aware adjustment to `relative_strength` (e.g. scoring "least
negative"/"improving" RS instead of "positive" RS) would be a reasonable follow-up if
this turns out to matter in practice.

## 6. Weight-tuning playbook

Practical, setup-type-specific advice — remember every weight below is edited per
setup type in the config modal, so these are independent per type:

- **Any custom setup type running `floor_reversal_accumulation`:** consider lowering or
  disabling `relative_strength` (see §5) since a valid reversal setup often still shows
  negative RS. `ma_alignment` no longer needs adjustment (§5 fixed it).
- **`range_compression_breakout`:** since its "spike" is a moderate breakout, not a
  historic volume record, consider giving `spike_rarity` a *lower* weight than
  Heartbeat's default 25 and shifting weight toward `range_compression`/`atr_contraction`
  (the squeeze itself), which are more central to this algorithm's thesis.
- **`early_breakout_followthrough`:** because it fires 1–3 days into a move, `base_duration`
  is less meaningful here (bases are intentionally shorter/looser) — consider lowering
  its weight and raising `spike_rarity` (which here encodes follow-through strength:
  gain % and volume multiple) and `volume_dry_up`.
- **Fundamentals components** (`earnings_acceleration`, `sales_acceleration`,
  `operating_margin_expansion`, `fcf_margin_expansion`) are 100% algorithm-independent —
  they come from `$context` (earnings/fundamentals data), not anything an algorithm
  derives from price/volume bars. Feel free to tune these the same way regardless of
  which algorithm a setup type runs.
- **Always check the "Active Weight Sum" indicator** in the config modal after changing
  weights — it's informational only (the formula normalizes to 0–100 regardless of the
  total), but it helps you reason about each component's *relative* influence on the
  final score.
- After changing weights, re-run `stocks:scan-buy-setups --symbol=TICKER --sync -vv`
  against a few known symbols to sanity-check the new score breakdown before trusting it
  in production.

## 7. Code map

| Concern | File |
| --- | --- |
| Algorithm contract | `app/Services/Stocks/Algorithms/BuySetupAlgorithm.php` |
| Algorithm registry (key → class, fallback, reversal-style flag) | `app/Services/Stocks/Algorithms/BuySetupAlgorithmRegistry.php` |
| Shared detection helpers (market-cap gate, MA alignment string, relative strength, fundamentals paragraph) | `app/Services/Stocks/Algorithms/Concerns/SharedDetectionHelpers.php` |
| Heartbeat Consolidation + Spike | `app/Services/Stocks/Algorithms/HeartbeatConsolidationSpikeAlgorithm.php` (thin wrapper around `StockBuySetupScanner::evaluate()`) |
| Range Compression Breakout | `app/Services/Stocks/Algorithms/RangeCompressionBreakoutAlgorithm.php` |
| Floor Reversal / Accumulation | `app/Services/Stocks/Algorithms/FloorReversalAccumulationAlgorithm.php` |
| Early Breakout Follow-Through | `app/Services/Stocks/Algorithms/EarlyBreakoutFollowThroughAlgorithm.php` |
| Scanner dispatch (`evaluateAll()`) | `app/Services/Stocks/StockBuySetupScanner.php` |
| Scoring (all algorithms, shared) | `app/Services/Stocks/StockBuySetupScorer.php` |
| Liquidity ("sleepy volume") penalty | `app/Services/Stocks/StockBuySetupLiquidityPenalty.php` |
| DB-backed config (setup types, score weights, algorithm selection) | `app/Services/Stocks/BuySetupConfigService.php` |
| Config UI (Livewire component + Blade view) | `app/Livewire/Watchlists/StockBuySetups.php`, `resources/views/livewire/watchlists/stock-buy-setups.blade.php` |

## 8. Testing your changes

```bash
# Everything covered in this guide:
./vendor/bin/pest tests/Unit/Stocks tests/Unit/Stocks/Algorithms
./vendor/bin/pest tests/Feature/Watchlists/StockBuySetupConfigModalTest.php
./vendor/bin/pest tests/Feature/Commands/ScanBuySetupsDynamicConfigTest.php

# Specifically the algorithm-aware ma_alignment scoring:
./vendor/bin/pest tests/Unit/Stocks/StockBuySetupScorerMaAlignmentTest.php
./vendor/bin/pest tests/Unit/Stocks/Algorithms/BuySetupAlgorithmRegistryTest.php
```

If you add a new algorithm (or a new algorithm-specific config knob), also update:

- `App\Services\Stocks\Algorithms\BuySetupAlgorithmRegistry::ALGORITHMS` (registration)
  and, if the algorithm is reversal-style, `REVERSAL_STYLE_ALGORITHMS`.
- The algorithm comparison table in the main [README.md](README.md#stock-buy-setup-scanner)
  and the relevant section in this guide.
- `tests/Unit/Stocks/Algorithms/BuySetupAlgorithmRegistryTest.php` and a new
  `tests/Unit/Stocks/Algorithms/{Name}AlgorithmTest.php`.
