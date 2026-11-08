<?php

return [
    'provider' => env('MARKET_DATA_PROVIDER', 'fmp'),

    'api_key' => env('DUOASSET_API_KEY', env('MARKET_DATA_API_KEY')),

    'api_rate_limit_per_minute' => (int) env('MARKET_DATA_API_RATE_LIMIT_PER_MINUTE', 120),

    'fmp' => [
        'base_url' => env('FMP_BASE_URL', 'https://financialmodelingprep.com/stable'),
        'api_key' => env('FMP_API_KEY', env('FMP_KEY')),
        'rate_limit_per_minute' => (int) env('FMP_RATE_LIMIT_PER_MINUTE', 300),
    ],

    'screener' => [
        'always_fresh' => env('SCREENER_ALWAYS_FRESH', false),
    ],

    /*
    |----------------------------------------------------------------------
    | Financial Statement Storage & Caching
    |----------------------------------------------------------------------
    | Controls whether quarterly financial statements (income, balance sheet,
    | cash flow) are persisted to the database and cached. When false, jobs
    | and queries fetch directly from upstream without storing or caching.
    */
    'financial_storage_enabled' => env('FINANCIAL_STORAGE_ENABLED', true),

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
        'equity_validation_enabled' => env('BUY_SETUP_EQUITY_VALIDATION_ENABLED', true),
        'min_market_cap' => env('BUY_SETUP_MIN_MARKET_CAP', env('EARNINGS_SCANNER_MIN_MARKET_CAP', 50000000)),
        // Fallback/default only. The Buy Setup Scanner's effective
        // eligibility range is configured per setup type (see
        // BuySetupConfigService); these two values are used only when a
        // setup type has no market-cap range of its own.
        'max_market_cap' => env('BUY_SETUP_MAX_MARKET_CAP', 1000000000000),
        'exchanges' => array_filter(array_map('trim', explode(',', env('BUY_SETUP_EXCHANGES', 'NYSE,NASDAQ,TSX,TSXV,AMEX,OTC')))),
        'max_symbols_per_run' => env('BUY_SETUP_MAX_SYMBOLS', 8000),
        'history_lookback_days' => env('BUY_SETUP_HISTORY_LOOKBACK_DAYS', 504),
        'benchmark_symbols' => array_filter(array_map('trim', explode(',', env('BUY_SETUP_BENCHMARK_SYMBOLS', 'SPY,IWM')))),
        'recent_spike_window_days' => env('BUY_SETUP_RECENT_SPIKE_WINDOW_DAYS', 60),
        'spike_lookback_days' => env('BUY_SETUP_MAX_SPIKE_AGE_DAYS', env('BUY_SETUP_SPIKE_LOOKBACK_DAYS', 84)),
        'min_base_days' => env('BUY_SETUP_MIN_BASE_DAYS', 30),
        'max_base_days' => env('BUY_SETUP_MAX_BASE_DAYS', 150),
        'max_range_compression_pct' => env('BUY_SETUP_MAX_RANGE_COMPRESSION_PCT', 30),
        'max_atr_ratio' => env('BUY_SETUP_MAX_ATR_RATIO', 0.70),
        'min_setup_score' => env('BUY_SETUP_MIN_SETUP_SCORE', 0),
        'notify_min_setup_score' => env('BUY_SETUP_NOTIFY_MIN_SETUP_SCORE', env('BUY_SETUP_MIN_HEARTBEAT_SCORE', 50)),
        'min_heartbeat_score' => env('BUY_SETUP_MIN_HEARTBEAT_SCORE', 50),
        'digest_other_notices_limit' => env('BUY_SETUP_DIGEST_OTHER_NOTICES_LIMIT', 50),
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
            'large' => env('BUY_SETUP_SLEEPY_VOLUME_LARGE_CAP_PENALTY_PCT', 15),
            'medium' => env('BUY_SETUP_SLEEPY_VOLUME_MEDIUM_CAP_PENALTY_PCT', 10),
            'small' => env('BUY_SETUP_SLEEPY_VOLUME_SMALL_CAP_PENALTY_PCT', 7),
            'micro' => env('BUY_SETUP_SLEEPY_VOLUME_MICRO_CAP_PENALTY_PCT', 7),
        ],
        'acceleration_scales' => [
            'earnings_acceleration' => env('BUY_SETUP_EPS_ACCELERATION_SCALE', 75),
            'sales_acceleration' => env('BUY_SETUP_SALES_ACCELERATION_SCALE', 3000),
        ],
        'score_weights' => [
            'spike_rarity' => env('BUY_SETUP_SCORE_SPIKE_RARITY_WEIGHT', 23),
            'base_duration' => env('BUY_SETUP_SCORE_BASE_DURATION_WEIGHT', 8),
            'range_compression' => env('BUY_SETUP_SCORE_RANGE_COMPRESSION_WEIGHT', 11),
            'atr_contraction' => env('BUY_SETUP_SCORE_ATR_CONTRACTION_WEIGHT', 8),
            'volume_dry_up' => env('BUY_SETUP_SCORE_VOLUME_DRY_UP_WEIGHT', 8),
            'breakout_distance' => env('BUY_SETUP_SCORE_BREAKOUT_DISTANCE_WEIGHT', 8),
            'ma_alignment' => env('BUY_SETUP_SCORE_MA_ALIGNMENT_WEIGHT', 5),
            'relative_strength' => env('BUY_SETUP_SCORE_RELATIVE_STRENGTH_WEIGHT', 9),
            'earnings_acceleration' => env('BUY_SETUP_SCORE_EARNINGS_ACCELERATION_WEIGHT', 3),
            'sales_acceleration' => env('BUY_SETUP_SCORE_SALES_ACCELERATION_WEIGHT', 7),
            'operating_margin_expansion' => env('BUY_SETUP_SCORE_OPERATING_MARGIN_EXPANSION_WEIGHT', 10),
            'fcf_margin_expansion' => env('BUY_SETUP_SCORE_FCF_MARGIN_EXPANSION_WEIGHT', 10),
            'book_value' => env('BOOK_VALUE_WEIGHT', env('BUY_SETUP_SCORE_BOOK_VALUE_WEIGHT', 10)),
        ],
        'notification_email' => env('BUY_SETUP_NOTIFICATION_EMAIL', 'j@7pro.ca'),
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

        // Comparison windows expressed in bars. Daily/weekly/monthly are
        // trading SESSIONS on the daily series; hourly is intraday BARS on
        // the 1-hour series. e.g. monthly = the close N sessions earlier.
        'periods' => [
            'hourly' => (int) env('MONEYFLOW_PERIOD_HOURLY_BARS', 1),
            'daily' => (int) env('MONEYFLOW_PERIOD_DAILY_SESSIONS', 1),
            'weekly' => (int) env('MONEYFLOW_PERIOD_WEEKLY_SESSIONS', 5),
            'monthly' => (int) env('MONEYFLOW_PERIOD_MONTHLY_SESSIONS', 2),
        ],

        // Intraday (hourly) capture — lets intraday traders watch flows move
        // through the session. Uses the FMP intraday chart endpoint.
        'intraday' => [
            'interval' => env('MONEYFLOW_INTRADAY_INTERVAL', '1hour'),
            'lookback_days' => (int) env('MONEYFLOW_INTRADAY_LOOKBACK_DAYS', 15),
            // Baseline window (in hourly bars) for hourly relative volume.
            'relative_volume_lookback_bars' => (int) env('MONEYFLOW_INTRADAY_RVOL_BARS', 20),
        ],

        // Baseline window (in sessions) for daily/weekly/monthly relative
        // volume and for each ETF's own return-volatility normalization.
        'baseline_lookback_sessions' => (int) env('MONEYFLOW_BASELINE_SESSIONS', 60),

        /*
        | Absolute scoring. Each ETF's per-timeframe metrics are normalized
        | against its OWN history (return vs own volatility; relative volume
        | vs own average) into 0-100 component scores, blended by
        | `score_weights`, aggregated across issuers (weighted), then blended
        | across timeframes by `timeframe_weights` into `strength`. Cross-
        | sectional rank/percentile are computed separately and never feed
        | the absolute score.
        */
        'score_weights' => [
            'change' => (float) env('MONEYFLOW_SCORE_CHANGE_WEIGHT', 0.4),
            'relative_strength' => (float) env('MONEYFLOW_SCORE_RS_WEIGHT', 0.4),
            'relative_volume' => (float) env('MONEYFLOW_SCORE_RVOL_WEIGHT', 0.2),
        ],
        'timeframe_weights' => [
            'hourly' => (float) env('MONEYFLOW_TF_HOURLY_WEIGHT', 0.1),
            'daily' => (float) env('MONEYFLOW_TF_DAILY_WEIGHT', 0.2),
            'weekly' => (float) env('MONEYFLOW_TF_WEEKLY_WEIGHT', 0.3),
            'monthly' => (float) env('MONEYFLOW_TF_MONTHLY_WEIGHT', 0.4),
        ],
        // Squash scales for the tanh normalization (larger = less sensitive).
        'normalization' => [
            'change_z_scale' => (float) env('MONEYFLOW_NORM_CHANGE_Z_SCALE', 2.0),
            'relative_volume_scale' => (float) env('MONEYFLOW_NORM_RVOL_SCALE', 1.0),
            // Relative-strength scale (percentage points) per timeframe.
            'relative_strength_scale' => [
                'hourly' => (float) env('MONEYFLOW_NORM_RS_HOURLY', 1.0),
                'daily' => (float) env('MONEYFLOW_NORM_RS_DAILY', 2.0),
                'weekly' => (float) env('MONEYFLOW_NORM_RS_WEEKLY', 4.0),
                'monthly' => (float) env('MONEYFLOW_NORM_RS_MONTHLY', 8.0),
            ],
        ],

        // Confidence from issuer agreement. Fewer than min_etfs_to_publish
        // valid ETFs => the sector is skipped (no normal score published).
        'confidence' => [
            'min_etfs_to_publish' => (int) env('MONEYFLOW_MIN_ETFS_TO_PUBLISH', 3),
            'levels' => [
                5 => 100,
                4 => 85,
                3 => 70,
            ],
        ],

        // Pure direction classifier thresholds (composite velocity/accel).
        'direction' => [
            'strong_strength' => (float) env('MONEYFLOW_DIR_STRONG_STRENGTH', 60),
            'weak_strength' => (float) env('MONEYFLOW_DIR_WEAK_STRENGTH', 40),
            'velocity_band' => (float) env('MONEYFLOW_DIR_VELOCITY_BAND', 0.5),
            'acceleration_band' => (float) env('MONEYFLOW_DIR_ACCELERATION_BAND', 0.5),
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Futures & Commodity Instruments Metadata
    |----------------------------------------------------------------------
    | Metadata and FMP discovery defaults for supported futures contracts.
    | FMP's /commodities-list endpoint is used dynamically alongside this
    | catalog to classify symbols, display price units, and configure
    | related comparison instruments.
    */
    'futures' => [
        'commodities_list_endpoint' => env('FMP_COMMODITIES_LIST_ENDPOINT', 'commodities-list'),
        'yahoo_fallback_enabled' => (bool) env('FUTURES_YAHOO_FALLBACK_ENABLED', true),
        'yahoo_base_url' => env('FUTURES_YAHOO_BASE_URL', 'https://query1.finance.yahoo.com/v8/finance/chart'),
        'default_market_hours' => 'CME Globex: Sun 18:00 – Fri 17:00 ET (daily maintenance 17:00–18:00 ET)',
        'default_rollover_note' => 'Continuous front-month futures series (unadjusted). Contract rollovers splice consecutive active delivery months without back-adjusting historical prices.',
        'supported_intervals' => ['1m', '5m', '15m', '30m', '1h', '4h', '1D', '1W', '1M'],
        'recommended_intervals' => ['5m', '15m', '1h', '4h', '1D'],
        'aliases' => [
            'CLUSDT' => 'CLUSD',
            'GCUSDT' => 'GCUSD',
            'SIUSDT' => 'SIUSD',
            'NGUSDT' => 'NGUSD',
            'HGUSDT' => 'HGUSD',
            'BZUSDT' => 'BZUSD',
            'MGCUSDT' => 'MGCUSD',
            'SILUSDT' => 'SILUSD',
            'PLUSDT' => 'PLUSD',
            'PAUSDT' => 'PAUSD',
            'RBUSDT' => 'RBUSD',
            'HOUSDT' => 'HOUSD',
            'DXUSDT' => 'DXUSD',
            'ESUSDT' => 'ESUSD',
            'NQUSDT' => 'NQUSD',
            'YMUSDT' => 'YMUSD',
            'RTYUSDT' => 'RTYUSD',
        ],
        'instruments' => [
            'GCUSD' => [
                'name' => 'Gold Futures',
                'exchange' => 'COMEX / CME',
                'currency' => 'USD',
                'price_unit' => 'USD / troy oz',
                'contract_size' => '100 troy oz',
                'trade_month' => 'Dec',
                'commodity_profile' => 'gold',
                'fmp_symbol' => 'GCUSD',
                'yahoo_symbol' => 'GC=F',
                'related_symbols' => [
                    ['symbol' => 'CLUSD', 'name' => 'WTI Crude Oil', 'price_unit' => 'USD / bbl'],
                    ['symbol' => 'SIUSD', 'name' => 'Silver Futures', 'price_unit' => 'USD / troy oz'],
                ],
                'direct_proxies' => ['XAUUSD', 'GCUSD', 'MGCUSD', 'GLD', 'IAU'],
                'news_proxies' => [
                    'forex' => ['XAUUSD', 'EURUSD', 'USDJPY'],
                    'stock' => ['GLD', 'IAU', 'NEM', 'USO', 'TLT', 'UUP'],
                ],
            ],
            'MGCUSD' => [
                'name' => 'Micro Gold Futures',
                'exchange' => 'COMEX / CME',
                'currency' => 'USD',
                'price_unit' => 'USD / troy oz',
                'contract_size' => '10 troy oz',
                'trade_month' => 'Dec',
                'commodity_profile' => 'gold',
                'fmp_symbol' => 'MGCUSD',
                'yahoo_symbol' => 'MGC=F',
                'related_symbols' => [
                    ['symbol' => 'GCUSD', 'name' => 'Gold Futures', 'price_unit' => 'USD / troy oz'],
                    ['symbol' => 'SIUSD', 'name' => 'Silver Futures', 'price_unit' => 'USD / troy oz'],
                ],
                'direct_proxies' => ['XAUUSD', 'GCUSD', 'MGCUSD', 'GLD', 'IAU'],
                'news_proxies' => [
                    'forex' => ['XAUUSD'],
                    'stock' => ['GLD', 'IAU'],
                ],
            ],
            'SIUSD' => [
                'name' => 'Silver Futures',
                'exchange' => 'COMEX / CME',
                'currency' => 'USD',
                'price_unit' => 'USD / troy oz',
                'contract_size' => '5,000 troy oz',
                'trade_month' => 'Dec',
                'commodity_profile' => 'silver',
                'fmp_symbol' => 'SIUSD',
                'yahoo_symbol' => 'SI=F',
                'related_symbols' => [
                    ['symbol' => 'GCUSD', 'name' => 'Gold Futures', 'price_unit' => 'USD / troy oz'],
                    ['symbol' => 'HGUSD', 'name' => 'Copper Futures', 'price_unit' => 'USD / lb'],
                ],
                'direct_proxies' => ['XAGUSD', 'SIUSD', 'SILUSD', 'SLV'],
                'news_proxies' => [
                    'forex' => ['XAGUSD', 'XAUUSD'],
                    'stock' => ['SLV', 'GLD'],
                ],
            ],
            'SILUSD' => [
                'name' => 'Micro Silver Futures',
                'exchange' => 'COMEX / CME',
                'currency' => 'USD',
                'price_unit' => 'USD / troy oz',
                'contract_size' => '1,000 troy oz',
                'trade_month' => 'Dec',
                'commodity_profile' => 'silver',
                'fmp_symbol' => 'SILUSD',
                'yahoo_symbol' => 'SIL=F',
                'related_symbols' => [
                    ['symbol' => 'SIUSD', 'name' => 'Silver Futures', 'price_unit' => 'USD / troy oz'],
                    ['symbol' => 'GCUSD', 'name' => 'Gold Futures', 'price_unit' => 'USD / troy oz'],
                ],
                'direct_proxies' => ['XAGUSD', 'SIUSD', 'SILUSD', 'SLV'],
                'news_proxies' => [
                    'forex' => ['XAGUSD'],
                    'stock' => ['SLV'],
                ],
            ],
            'CLUSD' => [
                'name' => 'WTI Crude Oil Futures',
                'exchange' => 'NYMEX / CME',
                'currency' => 'USD',
                'price_unit' => 'USD / bbl',
                'contract_size' => '1,000 bbl',
                'trade_month' => 'Dec',
                'commodity_profile' => 'crude_oil',
                'fmp_symbol' => 'CLUSD',
                'yahoo_symbol' => 'CL=F',
                'related_symbols' => [
                    ['symbol' => 'BZUSD', 'name' => 'Brent Crude Oil', 'price_unit' => 'USD / bbl'],
                    ['symbol' => 'GCUSD', 'name' => 'Gold Futures', 'price_unit' => 'USD / troy oz'],
                ],
                'direct_proxies' => ['CLUSD', 'BZUSD', 'USO', 'BNO'],
                'news_proxies' => [
                    'forex' => ['USDCAD'],
                    'stock' => ['USO', 'XLE', 'BNO'],
                ],
            ],
            'BZUSD' => [
                'name' => 'Brent Crude Oil Futures',
                'exchange' => 'ICE / NYMEX',
                'currency' => 'USD',
                'price_unit' => 'USD / bbl',
                'contract_size' => '1,000 bbl',
                'trade_month' => 'Jan',
                'commodity_profile' => 'crude_oil',
                'fmp_symbol' => 'BZUSD',
                'yahoo_symbol' => 'BZ=F',
                'related_symbols' => [
                    ['symbol' => 'CLUSD', 'name' => 'WTI Crude Oil', 'price_unit' => 'USD / bbl'],
                    ['symbol' => 'GCUSD', 'name' => 'Gold Futures', 'price_unit' => 'USD / troy oz'],
                ],
                'direct_proxies' => ['BZUSD', 'CLUSD', 'BNO', 'USO'],
                'news_proxies' => [
                    'forex' => ['USDCAD'],
                    'stock' => ['BNO', 'USO', 'XLE'],
                ],
            ],
            'NGUSD' => [
                'name' => 'Natural Gas Futures',
                'exchange' => 'NYMEX / CME',
                'currency' => 'USD',
                'price_unit' => 'USD / MMBtu',
                'contract_size' => '10,000 MMBtu',
                'trade_month' => 'Dec',
                'commodity_profile' => 'natural_gas',
                'fmp_symbol' => 'NGUSD',
                'yahoo_symbol' => 'NG=F',
                'related_symbols' => [
                    ['symbol' => 'CLUSD', 'name' => 'WTI Crude Oil', 'price_unit' => 'USD / bbl'],
                    ['symbol' => 'GCUSD', 'name' => 'Gold Futures', 'price_unit' => 'USD / troy oz'],
                ],
                'direct_proxies' => ['NGUSD', 'UNG'],
                'news_proxies' => [
                    'forex' => [],
                    'stock' => ['UNG', 'XLE'],
                ],
            ],
            'HGUSD' => [
                'name' => 'Copper Futures',
                'exchange' => 'COMEX / CME',
                'currency' => 'USD',
                'price_unit' => 'USD / lb',
                'contract_size' => '25,000 lbs',
                'trade_month' => 'Dec',
                'commodity_profile' => 'copper',
                'fmp_symbol' => 'HGUSD',
                'yahoo_symbol' => 'HG=F',
                'related_symbols' => [
                    ['symbol' => 'SIUSD', 'name' => 'Silver Futures', 'price_unit' => 'USD / troy oz'],
                    ['symbol' => 'GCUSD', 'name' => 'Gold Futures', 'price_unit' => 'USD / troy oz'],
                ],
                'direct_proxies' => ['HGUSD', 'CPER', 'FCX', 'SCCO'],
                'news_proxies' => [
                    'forex' => [],
                    'stock' => ['CPER', 'FCX', 'SCCO'],
                ],
            ],
            'PLUSD' => [
                'name' => 'Platinum Futures',
                'exchange' => 'NYMEX / CME',
                'currency' => 'USD',
                'price_unit' => 'USD / troy oz',
                'contract_size' => '50 troy oz',
                'trade_month' => 'Jan',
                'fmp_symbol' => 'PLUSD',
                'yahoo_symbol' => 'PL=F',
                'related_symbols' => [
                    ['symbol' => 'GCUSD', 'name' => 'Gold Futures', 'price_unit' => 'USD / troy oz'],
                    ['symbol' => 'SIUSD', 'name' => 'Silver Futures', 'price_unit' => 'USD / troy oz'],
                ],
            ],
            'PAUSD' => [
                'name' => 'Palladium Futures',
                'exchange' => 'NYMEX / CME',
                'currency' => 'USD',
                'price_unit' => 'USD / troy oz',
                'contract_size' => '100 troy oz',
                'trade_month' => 'Dec',
                'fmp_symbol' => 'PAUSD',
                'yahoo_symbol' => 'PA=F',
            ],
            'RBUSD' => ['name' => 'Gasoline RBOB Futures', 'exchange' => 'NYMEX / CME', 'currency' => 'USD', 'price_unit' => 'USD / gal', 'fmp_symbol' => 'RBUSD', 'yahoo_symbol' => 'RB=F'],
            'HOUSD' => ['name' => 'Heating Oil Futures', 'exchange' => 'NYMEX / CME', 'currency' => 'USD', 'price_unit' => 'USD / gal', 'fmp_symbol' => 'HOUSD', 'yahoo_symbol' => 'HO=F'],
            'DXUSD' => ['name' => 'US Dollar Index Futures', 'exchange' => 'ICE', 'currency' => 'USD', 'price_unit' => 'Index Points', 'fmp_symbol' => 'DXUSD', 'yahoo_symbol' => 'DX-Y.NYB'],
            'ESUSD' => ['name' => 'E-Mini S&P 500 Futures', 'exchange' => 'CME', 'currency' => 'USD', 'price_unit' => 'Index Points', 'fmp_symbol' => 'ESUSD', 'yahoo_symbol' => 'ES=F'],
            'NQUSD' => ['name' => 'Nasdaq 100 Futures', 'exchange' => 'CME', 'currency' => 'USD', 'price_unit' => 'Index Points', 'fmp_symbol' => 'NQUSD', 'yahoo_symbol' => 'NQ=F'],
            'YMUSD' => ['name' => 'Mini Dow Jones Futures', 'exchange' => 'CBOT / CME', 'currency' => 'USD', 'price_unit' => 'Index Points', 'fmp_symbol' => 'YMUSD', 'yahoo_symbol' => 'YM=F'],
            'RTYUSD' => ['name' => 'Micro E-mini Russell 2000 Futures', 'exchange' => 'CME', 'currency' => 'USD', 'price_unit' => 'Index Points', 'fmp_symbol' => 'RTYUSD', 'yahoo_symbol' => 'RTY=F'],
            'ZBUSD' => ['name' => '30-Year U.S. Treasury Bond Futures', 'exchange' => 'CBOT / CME', 'currency' => 'USD', 'price_unit' => 'Points', 'fmp_symbol' => 'ZBUSD', 'yahoo_symbol' => 'ZB=F'],
            'ZNUSD' => ['name' => '10-Year T-Note Futures', 'exchange' => 'CBOT / CME', 'currency' => 'USD', 'price_unit' => 'Points', 'fmp_symbol' => 'ZNUSD', 'yahoo_symbol' => 'ZN=F'],
            'ZFUSD' => ['name' => '5-Year US Treasury Note Futures', 'exchange' => 'CBOT / CME', 'currency' => 'USD', 'price_unit' => 'Points', 'fmp_symbol' => 'ZFUSD', 'yahoo_symbol' => 'ZF=F'],
            'ZTUSD' => ['name' => '2-Year T-Note Futures', 'exchange' => 'CBOT / CME', 'currency' => 'USD', 'price_unit' => 'Points', 'fmp_symbol' => 'ZTUSD', 'yahoo_symbol' => 'ZT=F'],
            'ZQUSD' => ['name' => '30-Day Fed Funds Futures', 'exchange' => 'CBOT / CME', 'currency' => 'USD', 'price_unit' => 'Points', 'fmp_symbol' => 'ZQUSD', 'yahoo_symbol' => 'ZQ=F'],
            'ALIUSD' => ['name' => 'Aluminum Futures', 'exchange' => 'COMEX / CME', 'currency' => 'USD', 'price_unit' => 'USD / MT'],
            'LBUSD' => ['name' => 'Lumber Futures', 'exchange' => 'CME', 'currency' => 'USD', 'price_unit' => 'USD / MBF'],
            'CCUSD' => ['name' => 'Cocoa Futures', 'exchange' => 'ICE', 'currency' => 'USD', 'price_unit' => 'USD / MT'],
            'ZMUSD' => ['name' => 'Soybean Meal Futures', 'exchange' => 'CBOT / CME', 'currency' => 'USD', 'price_unit' => 'USD / ton'],
            'ZRUSD' => ['name' => 'Rough Rice Futures', 'exchange' => 'CBOT / CME', 'currency' => 'USD', 'price_unit' => 'USD / cwt'],
            'DCUSD' => ['name' => 'Class III Milk Futures', 'exchange' => 'CME', 'currency' => 'USD', 'price_unit' => 'USD / cwt'],
            'ZSUSX' => ['name' => 'Soybean Futures', 'exchange' => 'CBOT / CME', 'currency' => 'USX', 'price_unit' => 'USX / bu'],
            'ZCUSX' => ['name' => 'Corn Futures', 'exchange' => 'CBOT / CME', 'currency' => 'USX', 'price_unit' => 'USX / bu'],
            'KEUSX' => ['name' => 'Wheat Futures', 'exchange' => 'CBOT / CME', 'currency' => 'USX', 'price_unit' => 'USX / bu'],
            'ZLUSX' => ['name' => 'Soybean Oil Futures', 'exchange' => 'CBOT / CME', 'currency' => 'USX', 'price_unit' => 'USX / lb'],
            'ZOUSX' => ['name' => 'Oat Futures', 'exchange' => 'CBOT / CME', 'currency' => 'USX', 'price_unit' => 'USX / bu'],
            'KCUSX' => ['name' => 'Coffee Futures', 'exchange' => 'ICE', 'currency' => 'USX', 'price_unit' => 'USX / lb'],
            'CTUSX' => ['name' => 'Cotton Futures', 'exchange' => 'ICE', 'currency' => 'USX', 'price_unit' => 'USX / lb'],
            'SBUSX' => ['name' => 'Sugar Futures', 'exchange' => 'ICE', 'currency' => 'USX', 'price_unit' => 'USX / lb'],
            'OJUSX' => ['name' => 'Orange Juice Futures', 'exchange' => 'ICE', 'currency' => 'USX', 'price_unit' => 'USX / lb'],
            'LEUSX' => ['name' => 'Live Cattle Futures', 'exchange' => 'CME', 'currency' => 'USX', 'price_unit' => 'USX / lb'],
            'HEUSX' => ['name' => 'Lean Hogs Futures', 'exchange' => 'CME', 'currency' => 'USX', 'price_unit' => 'USX / lb'],
            'GFUSX' => ['name' => 'Feeder Cattle Futures', 'exchange' => 'CME', 'currency' => 'USX', 'price_unit' => 'USX / lb'],
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Interactive News Timeline & Relevance Filtering
    |----------------------------------------------------------------------
    | Configures news sources, relevance themes, and impact horizons per
    | asset type (futures vs stock) so criteria can be tuned independently.
    */
    'news' => [
        'enabled' => (bool) env('INSTRUMENT_NEWS_ENABLED', true),
        'fed_rss_enabled' => (bool) env('INSTRUMENT_NEWS_FED_RSS_ENABLED', true),
        'fed_rss_url' => env('INSTRUMENT_NEWS_FED_RSS_URL', 'https://www.federalreserve.gov/feeds/press_monetary.xml'),
        'max_markers_on_chart' => (int) env('INSTRUMENT_NEWS_MAX_MARKERS', 25),
        'default_limit' => (int) env('INSTRUMENT_NEWS_DEFAULT_LIMIT', 50),
        'max_limit' => (int) env('INSTRUMENT_NEWS_MAX_LIMIT', 150),
        'default_lookback_days' => (int) env('INSTRUMENT_NEWS_DEFAULT_LOOKBACK_DAYS', 365),
        'persistent_storage_enabled' => (bool) env('INSTRUMENT_NEWS_PERSISTENT_STORAGE_ENABLED', true),
        'cache_ttl_recent_seconds' => (int) env('INSTRUMENT_NEWS_CACHE_TTL_RECENT', 600),
        'cache_ttl_historical_seconds' => (int) env('INSTRUMENT_NEWS_CACHE_TTL_HISTORICAL', 43200),
        'provider_cache_ttl_seconds' => (int) env('INSTRUMENT_NEWS_PROVIDER_CACHE_TTL', 600),
        'retention_days' => (int) env('INSTRUMENT_NEWS_RETENTION_DAYS', 730),
        'async_on_page_load' => (bool) env('INSTRUMENT_NEWS_ASYNC_ON_LOAD', false),
        'queue_connection' => env('INSTRUMENT_NEWS_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
        'queue_name' => env('INSTRUMENT_NEWS_QUEUE_NAME', 'default'),
        'lock_timeout_seconds' => (int) env('INSTRUMENT_NEWS_LOCK_TIMEOUT', 15),
        'lock_wait_seconds' => (int) env('INSTRUMENT_NEWS_LOCK_WAIT', 3),
        'sources' => [
            'fmp_stock_enabled' => (bool) env('INSTRUMENT_NEWS_FMP_STOCK_ENABLED', true),
            'fmp_press_releases_enabled' => (bool) env('INSTRUMENT_NEWS_FMP_PRESS_ENABLED', true),
            'fmp_forex_enabled' => (bool) env('INSTRUMENT_NEWS_FMP_FOREX_ENABLED', true),
            'fmp_general_enabled' => (bool) env('INSTRUMENT_NEWS_FMP_GENERAL_ENABLED', true),
            'fmp_economic_calendar_enabled' => (bool) env('INSTRUMENT_NEWS_FMP_ECON_CALENDAR_ENABLED', true),
            'fed_rss_enabled' => (bool) env('INSTRUMENT_NEWS_FED_RSS_ENABLED', true),
            'searxng_enabled' => (bool) env('INSTRUMENT_NEWS_SEARXNG_ENABLED', true),
            'searxng_url' => env('SEARXNG_URL', 'http://searxng:8080'),
            'searxng_timeout_seconds' => (int) env('SEARXNG_TIMEOUT', 5),
            'searxng_fallback_min_articles' => (int) env('SEARXNG_FALLBACK_MIN_ARTICLES', 5),
            'rss_feeds' => [],
        ],
        'ai_enrichment' => [
            'enabled' => (bool) env('INSTRUMENT_NEWS_AI_ENABLED', false),
            'async' => (bool) env('INSTRUMENT_NEWS_AI_ASYNC', true),
            'ollama_url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),
            'model' => env('INSTRUMENT_NEWS_OLLAMA_MODEL', 'qwen3:8b'),
            'fallback_model' => env('INSTRUMENT_NEWS_OLLAMA_FALLBACK_MODEL', 'qwen3.8:27b-q4_K_M'),
            'timeout_seconds' => (int) env('INSTRUMENT_NEWS_OLLAMA_TIMEOUT', 15),
            'max_batch_size' => (int) env('INSTRUMENT_NEWS_OLLAMA_BATCH_SIZE', 8),
            'cache_ttl_days' => (int) env('INSTRUMENT_NEWS_AI_CACHE_TTL_DAYS', 30),
        ],
        'impact_horizons' => [
            ['key' => '5m', 'label' => '5 Minutes', 'minutes' => 5],
            ['key' => '15m', 'label' => '15 Minutes', 'minutes' => 15],
            ['key' => '30m', 'label' => '30 Minutes', 'minutes' => 30],
            ['key' => '60m', 'label' => '60 Minutes', 'minutes' => 60],
            ['key' => '4h', 'label' => '4 Hours', 'minutes' => 240],
        ],
        'symbol_disambiguation' => [
            'NBIS' => [
                'company_aliases' => ['nebius', 'nebius group', 'neocloud'],
                'exclude_phrases' => [],
            ],
            'MASS' => [
                'company_aliases' => ['908 devices'],
                'exclude_phrases' => ['masivo silver', 'tsxv: mass', 'tsxv:mass', 'cerro colorado'],
            ],
            'CRON' => [
                'company_aliases' => ['cronos group', 'spinach', 'canadelaar', 'cronos growco'],
                'exclude_phrases' => ['cronos gas', 'cyprus cronos', 'eni', 'subsea contract', 'subsea production'],
            ],
        ],
        'relevance_profiles' => [
            'futures' => [
                'min_score' => (int) env('INSTRUMENT_NEWS_FUTURES_MIN_SCORE', 25),
                'themes' => [
                    'gold_precious_metals' => [
                        'label' => 'Gold & Precious Metals',
                        'weight' => 45,
                        'keywords' => ['gold', 'bullion', 'xau', 'xauusd', 'gcusd', 'precious metal', 'precious metals', 'comex gold', 'safe-haven', 'safe haven'],
                    ],
                    'central_bank_gold' => [
                        'label' => 'Central Bank Gold',
                        'weight' => 45,
                        'keywords' => ['central bank gold', 'gold reserve', 'sovereign gold', 'pboc', 'central-bank buying', 'central bank purchase', 'imf gold'],
                    ],
                    'fed_monetary_policy' => [
                        'label' => 'Federal Reserve & Policy',
                        'weight' => 40,
                        'keywords' => ['federal reserve', 'fomc', 'powell', 'fed rate', 'rate cut', 'rate hike', 'monetary policy', 'fed chair', 'fed governor', 'interest rate decision'],
                    ],
                    'inflation_rates' => [
                        'label' => 'Inflation & Interest Rates',
                        'weight' => 35,
                        'keywords' => ['inflation', 'cpi', 'pce', 'ppi', 'consumer price', 'real yield', 'interest rate', 'stagflation', 'deflation', 'nonfarm payroll', 'nfp'],
                    ],
                    'usd_movements' => [
                        'label' => 'US Dollar',
                        'weight' => 25,
                        'keywords' => ['u.s. dollar', 'us dollar', 'dxy', 'dollar index', 'greenback', 'weak dollar', 'strong dollar'],
                    ],
                    'treasury_yields' => [
                        'label' => 'Treasury Yields',
                        'weight' => 35,
                        'keywords' => ['treasury yield', '10-year yield', '10-yr yield', 'bond yield', 'treasuries', '2-year yield', 'yield curve', 'bond auction'],
                    ],
                    'geopolitics' => [
                        'label' => 'Geopolitics & Conflict',
                        'weight' => 35,
                        'keywords' => ['geopolitic', 'strait of hormuz', 'middle east', 'military conflict', 'armed conflict', 'sanction', 'tariff', 'military strike', 'missile', 'ceasefire', 'escalation'],
                    ],
                    'oil_supply' => [
                        'label' => 'Oil & Energy Supply',
                        'weight' => 30,
                        'keywords' => ['oil supply', 'crude oil', 'wti', 'brent', 'opec', 'tanker', 'pipeline', 'refinery', 'energy disruption', 'clusd', 'clusdt'],
                    ],
                    'market_liquidity' => [
                        'label' => 'Market Liquidity & Risk',
                        'weight' => 25,
                        'keywords' => ['liquidity', 'margin call', 'market sell-off', 'selloff', 'vix', 'volatility spike', 'risk-off', 'banking stress', 'credit crunch'],
                    ],
                ],
                'commodity_profiles' => [
                    'silver' => [
                        'primary_keywords' => ['silver', 'xag', 'xagusd', 'siusd', 'slv', 'bullion', 'precious metal', 'comex'],
                        'primary_theme' => [
                            'key' => 'silver_precious_metals',
                            'label' => 'Silver & Precious Metals',
                            'weight' => 50,
                        ],
                    ],
                    'crude_oil' => [
                        'primary_keywords' => ['crude oil', 'wti', 'brent', 'clusd', 'clusdt', 'bzusd', 'opec', 'oil supply', 'petroleum', 'eia', 'tanker', 'refinery', 'pipeline', 'strait of hormuz'],
                        'primary_theme' => [
                            'key' => 'oil_supply',
                            'label' => 'Crude Oil & Energy Supply',
                            'weight' => 50,
                        ],
                        'excluded_themes' => ['gold_precious_metals', 'central_bank_gold'],
                    ],
                    'natural_gas' => [
                        'primary_keywords' => ['natural gas', 'lng', 'henry hub', 'ngusd', 'ung', 'gas storage', 'pipeline', 'heating demand', 'energy'],
                        'primary_theme' => [
                            'key' => 'natural_gas_energy',
                            'label' => 'Natural Gas & LNG',
                            'weight' => 50,
                        ],
                        'excluded_themes' => ['gold_precious_metals', 'central_bank_gold'],
                    ],
                    'copper' => [
                        'primary_keywords' => ['copper', 'hgusd', 'cper', 'comex copper', 'lme copper', 'base metal', 'industrial metal', 'smelter', 'mining'],
                        'primary_theme' => [
                            'key' => 'copper_industrial_metals',
                            'label' => 'Copper & Industrial Metals',
                            'weight' => 50,
                        ],
                        'excluded_themes' => ['gold_precious_metals', 'central_bank_gold'],
                    ],
                ],
            ],
            'stock' => [
                'min_score' => (int) env('INSTRUMENT_NEWS_STOCK_MIN_SCORE', 25),
                'themes' => [
                    'earnings_financials' => [
                        'label' => 'Earnings & Guidance',
                        'weight' => 45,
                        'keywords' => ['earnings', 'revenue', 'eps', 'guidance', 'quarter', 'q1', 'q2', 'q3', 'q4', 'beat', 'miss', 'profit', 'margin', 'dividend', 'buyback', 'arr', 'outlook', 'financial results'],
                    ],
                    'analyst_ratings' => [
                        'label' => 'Analyst & Price Target',
                        'weight' => 35,
                        'keywords' => ['upgrade', 'downgrade', 'price target', 'overweight', 'outperform', 'buy rating', 'sell rating', 'strong buy', 'analyst', 'initiation', 'zacks rank'],
                    ],
                    'corporate_events' => [
                        'label' => 'Corporate & Regulatory',
                        'weight' => 40,
                        'keywords' => ['acquisition', 'acquires', 'merger', 'fda', 'clinical trial', 'approval', 'contract', 'partnership', 'partner', 'launch', 'sec filing', '13f', 'ceo', 'cfo', 'insider', 'financing', 'convertible', 'offering', 'investor day', 'conference'],
                    ],
                    'macro_sector' => [
                        'label' => 'Sector & Macro',
                        'weight' => 25,
                        'keywords' => ['federal reserve', 'inflation', 'tariff', 'sector', 'ai', 'neocloud', 'data center', 'semiconductor', 'biotech', 'cannabis', 'chemical detection', 'regulation'],
                    ],
                ],
            ],
        ],
    ],

];
