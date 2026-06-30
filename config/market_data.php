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
        'exchanges' => ['NYSE', 'NASDAQ', 'TSX', 'TSXV', 'AMEX', 'OTC'],
        // Soft cap on screener size per run; FMP company-screener can
        // return many thousands of rows. Set to 0 to disable the cap.
        'max_symbols_per_run' => env('EPS_REVISION_MAX_SYMBOLS', 4000),
    ],

    /*
    |----------------------------------------------------------------------
    | Stock Buy Setup Scanner
    |----------------------------------------------------------------------
    | Looks for a rare recent volume spike after a tight consolidation base.
    | The command queues one per-symbol job unless --sync is used.
    */
    'buy_setup_scanner' => [
        'enabled' => env('BUY_SETUP_SCANNER_ENABLED', true),
        'min_market_cap' => env('BUY_SETUP_MIN_MARKET_CAP', env('EARNINGS_SCANNER_MIN_MARKET_CAP', 100000000)),
        'exchanges' => array_filter(array_map('trim', explode(',', env('BUY_SETUP_EXCHANGES', 'NYSE,NASDAQ,TSX,TSXV,AMEX,OTC')))),
        'max_symbols_per_run' => env('BUY_SETUP_MAX_SYMBOLS', 1000),
        'history_lookback_days' => env('BUY_SETUP_HISTORY_LOOKBACK_DAYS', 504),
        'benchmark_symbols' => array_filter(array_map('trim', explode(',', env('BUY_SETUP_BENCHMARK_SYMBOLS', 'SPY,IWM')))),
        'recent_spike_window_days' => env('BUY_SETUP_RECENT_SPIKE_WINDOW_DAYS', 42),
        'max_spike_age_days' => env('BUY_SETUP_MAX_SPIKE_AGE_DAYS', 84),
        'min_base_days' => env('BUY_SETUP_MIN_BASE_DAYS', 60),
        'max_base_days' => env('BUY_SETUP_MAX_BASE_DAYS', 120),
        'max_range_compression_pct' => env('BUY_SETUP_MAX_RANGE_COMPRESSION_PCT', 25),
        'max_atr_ratio' => env('BUY_SETUP_MAX_ATR_RATIO', 0.85),
        'min_setup_score' => env('BUY_SETUP_MIN_SETUP_SCORE', 0),
        'notify_min_setup_score' => env('BUY_SETUP_NOTIFY_MIN_SETUP_SCORE', env('BUY_SETUP_MIN_HEARTBEAT_SCORE', 50)),
        'min_heartbeat_score' => env('BUY_SETUP_MIN_HEARTBEAT_SCORE', 50),
        'setup_types' => [
            'heartbeat_consolidation_spike' => [
                'enabled' => env('BUY_SETUP_TYPE_HEARTBEAT_CONSOLIDATION_SPIKE_ENABLED', true),
                'label' => 'Heartbeat consolidation + spike',
            ],
            'range_compression_breakout' => [
                'enabled' => env('BUY_SETUP_TYPE_RANGE_COMPRESSION_BREAKOUT_ENABLED', false),
                'label' => 'Range compression breakout',
            ],
            'floor_reversal_accumulation' => [
                'enabled' => env('BUY_SETUP_TYPE_FLOOR_REVERSAL_ACCUMULATION_ENABLED', false),
                'label' => 'Floor reversal / accumulation',
            ],
            'early_breakout_followthrough' => [
                'enabled' => env('BUY_SETUP_TYPE_EARLY_BREAKOUT_FOLLOWTHROUGH_ENABLED', false),
                'label' => 'Early breakout follow-through',
            ],
        ],
        'sleepy_volume_penalties' => [
            'large' => env('BUY_SETUP_SLEEPY_VOLUME_LARGE_CAP_PENALTY_PCT', 40),
            'medium' => env('BUY_SETUP_SLEEPY_VOLUME_MEDIUM_CAP_PENALTY_PCT', 30),
            'small' => env('BUY_SETUP_SLEEPY_VOLUME_SMALL_CAP_PENALTY_PCT', 20),
            'micro' => env('BUY_SETUP_SLEEPY_VOLUME_MICRO_CAP_PENALTY_PCT', 15),
        ],
        'score_weights' => [
            'spike_rarity' => env('BUY_SETUP_SCORE_SPIKE_RARITY_WEIGHT', env('BUY_SETUP_SCORE_SPIKE_RARITY_MAX', 25)),
            'base_duration' => env('BUY_SETUP_SCORE_BASE_DURATION_WEIGHT', env('BUY_SETUP_SCORE_BASE_DURATION_MAX', 10)),
            'range_compression' => env('BUY_SETUP_SCORE_RANGE_COMPRESSION_WEIGHT', env('BUY_SETUP_SCORE_RANGE_COMPRESSION_MAX', 15)),
            'atr_contraction' => env('BUY_SETUP_SCORE_ATR_CONTRACTION_WEIGHT', env('BUY_SETUP_SCORE_ATR_CONTRACTION_MAX', 10)),
            'volume_dry_up' => env('BUY_SETUP_SCORE_VOLUME_DRY_UP_WEIGHT', env('BUY_SETUP_SCORE_VOLUME_DRY_UP_MAX', 10)),
            'breakout_distance' => env('BUY_SETUP_SCORE_BREAKOUT_DISTANCE_WEIGHT', env('BUY_SETUP_SCORE_BREAKOUT_DISTANCE_MAX', 10)),
            'ma_alignment' => env('BUY_SETUP_SCORE_MA_ALIGNMENT_WEIGHT', env('BUY_SETUP_SCORE_MA_ALIGNMENT_MAX', 10)),
            'relative_strength' => env('BUY_SETUP_SCORE_RELATIVE_STRENGTH_WEIGHT', env('BUY_SETUP_SCORE_RELATIVE_STRENGTH_MAX', 10)),
            'earnings_acceleration' => env('BUY_SETUP_SCORE_EARNINGS_ACCELERATION_WEIGHT', env('BUY_SETUP_SCORE_EARNINGS_ACCELERATION_MAX', 5)),
            'sales_acceleration' => env('BUY_SETUP_SCORE_SALES_ACCELERATION_WEIGHT', env('BUY_SETUP_SCORE_SALES_ACCELERATION_MAX', 5)),
        ],
        'notification_email' => env('BUY_SETUP_NOTIFICATION_EMAIL'),
    ],

];
