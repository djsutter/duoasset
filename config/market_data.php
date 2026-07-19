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
        'min_market_cap' => env('EARNINGS_SCANNER_MIN_MARKET_CAP', 25000000),

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
        'min_market_cap' => env('EPS_REVISION_MIN_MARKET_CAP', env('EARNINGS_SCANNER_MIN_MARKET_CAP', 25000000)),
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
        'min_market_cap' => env('BUY_SETUP_MIN_MARKET_CAP', env('EARNINGS_SCANNER_MIN_MARKET_CAP', 25000000)),
        'exchanges' => array_filter(array_map('trim', explode(',', env('BUY_SETUP_EXCHANGES', 'NYSE,NASDAQ,TSX,TSXV,AMEX,OTC')))),
        'max_symbols_per_run' => env('BUY_SETUP_MAX_SYMBOLS', 1000),
        'history_lookback_days' => env('BUY_SETUP_HISTORY_LOOKBACK_DAYS', 504),
        'benchmark_symbols' => array_filter(array_map('trim', explode(',', env('BUY_SETUP_BENCHMARK_SYMBOLS', 'SPY,IWM')))),
        'recent_spike_window_days' => env('BUY_SETUP_RECENT_SPIKE_WINDOW_DAYS', 42),
        'spike_lookback_days' => env('BUY_SETUP_SPIKE_LOOKBACK_DAYS', 504),
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
        'acceleration_scales' => [
            'earnings_acceleration' => env('BUY_SETUP_EPS_ACCELERATION_SCALE', 75),
            'sales_acceleration' => env('BUY_SETUP_SALES_ACCELERATION_SCALE', 3000),
        ],
        'score_weights' => [
            'spike_rarity' => env('BUY_SETUP_SCORE_SPIKE_RARITY_WEIGHT', env('BUY_SETUP_SCORE_SPIKE_RARITY_MAX', 7)),
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

    /*
    |----------------------------------------------------------------------
    | Sector Money Flows — ETF universe
    |----------------------------------------------------------------------
    | The single source of truth for the sector ETF groupings used by the
    | Sector Money Flows engine (see docs/sector-money-flows.md, Phase 5).
    |
    | Each sector is measured with ~5 representative provider ETFs. Combining
    | multiple issuers reduces provider-specific noise. Each ETF carries a
    | `weight` so imperfect sector equivalents can be down-weighted later
    | without code changes; all default to 1.0.
    |
    | `existing_sector_slug` optionally maps a money-flow sector to a slug in
    | the existing `sectors` taxonomy table. It is NOT a database relationship
    | in Phase 1 — it is a hint that makes later sector-to-stock confirmation
    | easier. Note the app's taxonomy has fewer sectors than GICS, so several
    | money-flow sectors intentionally map to the same slug (both consumer
    | sectors -> "consumer") or to a differently-named slug (communication
    | services -> "telecommunications", real estate -> "real-estate").
    |
    | Sector keys are canonical and must be used consistently everywhere.
    | IMPORTANT: IYW belongs to Technology only; Communication Services uses
    | XLC/VOX/IYZ/RSPC/FCOM.
    */
    'sector_etfs' => [

        'technology' => [
            'label' => 'Technology',
            'existing_sector_slug' => 'technology',
            'etfs' => [
                'spdr' => ['symbol' => 'XLK', 'weight' => 1.0],
                'vanguard' => ['symbol' => 'VGT', 'weight' => 1.0],
                'ishares' => ['symbol' => 'IYW', 'weight' => 1.0],
                'invesco' => ['symbol' => 'RSPT', 'weight' => 1.0],
                'fidelity' => ['symbol' => 'FTEC', 'weight' => 1.0],
            ],
        ],

        'financials' => [
            'label' => 'Financials',
            'existing_sector_slug' => 'financials',
            'etfs' => [
                'spdr' => ['symbol' => 'XLF', 'weight' => 1.0],
                'vanguard' => ['symbol' => 'VFH', 'weight' => 1.0],
                'ishares' => ['symbol' => 'IYF', 'weight' => 1.0],
                'invesco' => ['symbol' => 'RSPF', 'weight' => 1.0],
                'fidelity' => ['symbol' => 'FNCL', 'weight' => 1.0],
            ],
        ],

        'healthcare' => [
            'label' => 'Healthcare',
            'existing_sector_slug' => 'healthcare',
            'etfs' => [
                'spdr' => ['symbol' => 'XLV', 'weight' => 1.0],
                'vanguard' => ['symbol' => 'VHT', 'weight' => 1.0],
                'ishares' => ['symbol' => 'IYH', 'weight' => 1.0],
                'invesco' => ['symbol' => 'RSPH', 'weight' => 1.0],
                'fidelity' => ['symbol' => 'FHLC', 'weight' => 1.0],
            ],
        ],

        'communication_services' => [
            'label' => 'Communication Services',
            'existing_sector_slug' => 'telecommunications',
            'etfs' => [
                'spdr' => ['symbol' => 'XLC', 'weight' => 1.0],
                'vanguard' => ['symbol' => 'VOX', 'weight' => 1.0],
                'ishares' => ['symbol' => 'IYZ', 'weight' => 1.0],
                'invesco' => ['symbol' => 'RSPC', 'weight' => 1.0],
                'fidelity' => ['symbol' => 'FCOM', 'weight' => 1.0],
            ],
        ],

        'consumer_discretionary' => [
            'label' => 'Consumer Discretionary',
            'existing_sector_slug' => 'consumer',
            'etfs' => [
                'spdr' => ['symbol' => 'XLY', 'weight' => 1.0],
                'vanguard' => ['symbol' => 'VCR', 'weight' => 1.0],
                'ishares' => ['symbol' => 'IYC', 'weight' => 1.0],
                'invesco' => ['symbol' => 'RSPD', 'weight' => 1.0],
                'fidelity' => ['symbol' => 'FDIS', 'weight' => 1.0],
            ],
        ],

        'consumer_staples' => [
            'label' => 'Consumer Staples',
            'existing_sector_slug' => 'consumer',
            'etfs' => [
                'spdr' => ['symbol' => 'XLP', 'weight' => 1.0],
                'vanguard' => ['symbol' => 'VDC', 'weight' => 1.0],
                'ishares' => ['symbol' => 'IYK', 'weight' => 1.0],
                'invesco' => ['symbol' => 'RSPS', 'weight' => 1.0],
                'fidelity' => ['symbol' => 'FSTA', 'weight' => 1.0],
            ],
        ],

        'industrials' => [
            'label' => 'Industrials',
            'existing_sector_slug' => 'industrials',
            'etfs' => [
                'spdr' => ['symbol' => 'XLI', 'weight' => 1.0],
                'vanguard' => ['symbol' => 'VIS', 'weight' => 1.0],
                'ishares' => ['symbol' => 'IYJ', 'weight' => 1.0],
                'invesco' => ['symbol' => 'RSPN', 'weight' => 1.0],
                'fidelity' => ['symbol' => 'FIDU', 'weight' => 1.0],
            ],
        ],

        'energy' => [
            'label' => 'Energy',
            'existing_sector_slug' => 'energy',
            'etfs' => [
                'spdr' => ['symbol' => 'XLE', 'weight' => 1.0],
                'vanguard' => ['symbol' => 'VDE', 'weight' => 1.0],
                'ishares' => ['symbol' => 'IYE', 'weight' => 1.0],
                'invesco' => ['symbol' => 'RSPG', 'weight' => 1.0],
                'fidelity' => ['symbol' => 'FENY', 'weight' => 1.0],
            ],
        ],

        'utilities' => [
            'label' => 'Utilities',
            'existing_sector_slug' => 'utilities',
            'etfs' => [
                'spdr' => ['symbol' => 'XLU', 'weight' => 1.0],
                'vanguard' => ['symbol' => 'VPU', 'weight' => 1.0],
                'ishares' => ['symbol' => 'IDU', 'weight' => 1.0],
                'invesco' => ['symbol' => 'RSPU', 'weight' => 1.0],
                'fidelity' => ['symbol' => 'FUTY', 'weight' => 1.0],
            ],
        ],

        'real_estate' => [
            'label' => 'Real Estate',
            'existing_sector_slug' => 'real-estate',
            'etfs' => [
                'spdr' => ['symbol' => 'XLRE', 'weight' => 1.0],
                'vanguard' => ['symbol' => 'VNQ', 'weight' => 1.0],
                'ishares' => ['symbol' => 'IYR', 'weight' => 1.0],
                'invesco' => ['symbol' => 'RSPR', 'weight' => 1.0],
                'fidelity' => ['symbol' => 'FREL', 'weight' => 1.0],
            ],
        ],

        'materials' => [
            'label' => 'Materials',
            'existing_sector_slug' => 'materials',
            'etfs' => [
                'spdr' => ['symbol' => 'XLB', 'weight' => 1.0],
                'vanguard' => ['symbol' => 'VAW', 'weight' => 1.0],
                'ishares' => ['symbol' => 'IYM', 'weight' => 1.0],
                'invesco' => ['symbol' => 'RSPM', 'weight' => 1.0],
                'fidelity' => ['symbol' => 'FMAT', 'weight' => 1.0],
            ],
        ],

    ],

    /*
    |----------------------------------------------------------------------
    | Sector Money Flows — engine settings (Phase 1: retrieval scope)
    |----------------------------------------------------------------------
    | Operational settings for the moneyflow:update engine. Scoring,
    | strength, confidence and direction weights are intentionally NOT
    | defined here yet — they are introduced in Phase 2 alongside the
    | calculators that consume them, so real scores can be observed before
    | the weighting is fixed.
    |
    | Periods are expressed in TRADING SESSIONS, not calendar days. The
    | engine compares against the close N valid sessions earlier (e.g.
    | monthly = the close 2 sessions ago), never a calendar-month offset.
    */
    'moneyflow' => [
        'enabled' => env('MONEYFLOW_ENABLED', true),

        // Broad-market benchmark used for per-period relative strength.
        'benchmark_symbol' => env('MONEYFLOW_BENCHMARK_SYMBOL', 'SPY'),

        // Calendar-day window requested from FMP. Wider than the longest
        // trading-session period so weekends/holidays still yield 21+ bars.
        'history_lookback_days' => (int) env('MONEYFLOW_HISTORY_LOOKBACK_DAYS', 90),

        // Market calendar timezone. Sector ETFs are U.S.-listed; the
        // engine and scheduler should reference this explicitly rather
        // than mixing America/Toronto and America/New_York ad hoc.
        'market_timezone' => env('MONEYFLOW_MARKET_TIMEZONE', 'America/New_York'),

        // Comparison windows in trading sessions.
        'periods' => [
            'daily' => (int) env('MONEYFLOW_PERIOD_DAILY_SESSIONS', 1),
            'weekly' => (int) env('MONEYFLOW_PERIOD_WEEKLY_SESSIONS', 5),
            'monthly' => (int) env('MONEYFLOW_PERIOD_MONTHLY_SESSIONS', 2),
        ],
    ],

];
