# DuoAsset: FCF and operating-margin improvement guard (2026-10-08)

## Why

The original TTM margin-expansion formula was mathematically correct:

`(current_ttm_flow / current_ttm_revenue - prior_ttm_flow / prior_ttm_revenue) * 10000`

But rapid revenue growth could make a negative margin improve even while the
company spent **more cash** (or lost **more operating income**) in dollars.
Such companies could earn the maximum FCF/operating-margin expansion points.

## What changed

- `StockFundamentalsAnalyzer::fcfMarginExpansion()` still calculates the exact
  raw margin change, now returned as `raw_fcf_margin_expansion_bps`.
  The existing *score-facing* `fcf_margin_expansion_bps` is capped at 0 when
  `current_ttm_fcf < 0` **and** `current_ttm_fcf < prior_ttm_fcf`.
- `operatingMarginExpansion()` applies the equivalent safeguard to negative
  operating income and returns `raw_operating_margin_expansion_bps` alongside
  the score-facing `operating_margin_expansion_bps`.
- The existing keys and field mappings used by persisted alerts remain intact;
  **no database migration** is needed. The raw keys are available in the
  analyzer output but are not automatically persisted or shown in the UI.
- Score breakdown labels clarify the quality gate; when both negative margins
  look better but the score-facing expansion is zero, the UI explains the
  increased operating loss or cash burn.
- Latest-eight-quarter validation no longer skips an invalid/missing recent
  observation and substitutes an older one. FCF calculations also verify
  consistent currencies across income and cash-flow statement sources.

### Example

| Metric | Prior TTM | Current TTM |
|---|---:|---:|
| Revenue | $10M | $40M |
| FCF | -$20M | -$30M |
| FCF margin | -200% | -75% |

Raw margin expansion: **+12,500 bps** (retained for diagnostics).
Score-facing FCF improvement: **0 bps / 0 points** (cash burn worsened).

When FCF changes from -$20M to -$15M, the margin-improvement metric still
receives credit, even though cash flow remains negative.

## Install

Unzip the archive at the Laravel project root, preserving paths. Overwrite the
three modified source files and add the regression test. No schema change.

Run in the **DuoAsset project terminal**:

`php artisan test --filter=StockFundamentalsAnalyzerTest`

Re-run the setup scanner and regenerate or refresh previously persisted alerts:
old TNXP (and other) scores are **not** retroactively recalculated just by
replacing the PHP source. The archive contains only the files provided in the
request, so this package cannot modify the application's alert-refresh job.

## Files changed

- `app/Services/Stocks/StockFundamentalsAnalyzer.php`
- `app/Services/Stocks/StockBuySetupScorer.php`
- `tests/Unit/Services/Stocks/StockFundamentalsAnalyzerTest.php` (new)

## Scope / caveats

This correction prevents a specific false-positive expansion score; it does
not purport to measure financing runway, dilution, intellectual property risks,
commercial quality, or other fundamentals outside these metrics. It preserves
existing 0-100 weighting and the growth-synergy mechanism. A full Laravel
integration suite was not available in the supplied subset; nine standalone
PHP regressions passed against the analyzer and interpolation scorer.
