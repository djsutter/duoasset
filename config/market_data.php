<?php

return [
    'provider' => env('MARKET_DATA_PROVIDER', 'fmp'),

    'fmp' => [
        'base_url' => env('FMP_BASE_URL', 'https://financialmodelingprep.com/stable'),
        'api_key' => env('FMP_API_KEY'),
    ],

    /*
    |----------------------------------------------------------------------
    | EPS Earnings Surprise Scanner
    |----------------------------------------------------------------------
    | Compares ACTUAL EPS vs ESTIMATED EPS after earnings are released.
    | Alerts when the surprise percent crosses either threshold:
    |   - positive_threshold (e.g. >= +90  → "EPS Earnings Beat")
    |   - negative_threshold (e.g. <= -30  → "EPS Earnings Miss")
    */
    'earnings_scanner' => [
        'enabled' => env('EARNINGS_SCANNER_ENABLED', true),
        'min_market_cap' => env('EARNINGS_SCANNER_MIN_MARKET_CAP', 100000000),

        // Back-compat: legacy single "min" threshold still respected when
        // the explicit positive/negative pair is not set in env.
        'min_eps_surprise_percent' => env('EARNINGS_SCANNER_MIN_EPS_SURPRISE_PERCENT', 90),

        'positive_threshold' => env('EPS_EARNINGS_POSITIVE_THRESHOLD', 90),
        'negative_threshold' => env('EPS_EARNINGS_NEGATIVE_THRESHOLD', -30),

        'exchanges' => ['NYSE', 'NASDAQ', 'TSX', 'TSXV'],
        'notification_email' => env('EARNINGS_SCANNER_NOTIFICATION_EMAIL'),
    ],

    /*
    |----------------------------------------------------------------------
    | EPS Revision Scanner
    |----------------------------------------------------------------------
    | Compares the latest analyst consensus EPS for the next quarter
    | against the previously stored value (per symbol + period). Alerts
    | when the revision percent crosses either threshold:
    |   - positive_threshold (e.g. >= +20 → "EPS Target Raised")
    |   - negative_threshold (e.g. <= -20 → "EPS Target Cut")
    |
    | The symbol universe is pulled from FMP's company-screener pre-filtered
    | by `min_market_cap` and the configured exchanges, so we never poll
    | analyst-estimates for tickers below the cap.
    */
    'revision_scanner' => [
        'enabled' => env('EPS_REVISION_SCANNER_ENABLED', true),
        'min_market_cap' => env('EPS_REVISION_MIN_MARKET_CAP', env('EARNINGS_SCANNER_MIN_MARKET_CAP', 100000000)),
        'positive_threshold' => env('EPS_REVISION_POSITIVE_THRESHOLD', 20),
        'negative_threshold' => env('EPS_REVISION_NEGATIVE_THRESHOLD', -20),
        'exchanges' => ['NYSE', 'NASDAQ', 'TSX', 'TSXV'],
        // Soft cap on screener size per run; FMP company-screener can
        // return many thousands of rows. Set to 0 to disable the cap.
        'max_symbols_per_run' => env('EPS_REVISION_MAX_SYMBOLS', 2000),
    ],
];
