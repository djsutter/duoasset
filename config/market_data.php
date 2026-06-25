<?php

return [
    'provider' => env('MARKET_DATA_PROVIDER', 'fmp'),

    'fmp' => [
        'base_url' => env('FMP_BASE_URL', 'https://financialmodelingprep.com/stable'),
        'api_key' => env('FMP_API_KEY'),
    ],

    'earnings_scanner' => [
        'enabled' => env('EARNINGS_SCANNER_ENABLED', true),
        'min_market_cap' => env('EARNINGS_SCANNER_MIN_MARKET_CAP', 100000000),
        'min_eps_surprise_percent' => env('EARNINGS_SCANNER_MIN_EPS_SURPRISE_PERCENT', 90),
        'exchanges' => ['NYSE', 'NASDAQ', 'TSX', 'TSXV'],
        'notification_email' => env('EARNINGS_SCANNER_NOTIFICATION_EMAIL'),
    ],
];
