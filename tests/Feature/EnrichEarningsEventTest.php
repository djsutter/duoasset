<?php

use App\Jobs\EnrichEarningsEvent;
use App\Models\EarningsEvent;
use App\Services\Earnings\EarningsSurpriseScorer;
use App\Services\MarketData\MarketDataProvider;
use App\Services\Stocks\StockProvisioner;

/**
 * Regression test for the EPS Surprise Alerts watchlist showing empty
 * Company / Exchange / Market Cap columns: even when MarketDataProvider
 * ::profile() returns null (e.g. /profile gated on the current FMP
 * plan), enrichment must still populate those fields from the composite
 * quote() payload and compute market_cap from price × shares_outstanding.
 */
it('populates company_name, exchange and computed market_cap when only quote() returns data', function () {
    $event = EarningsEvent::create([
        'source' => 'fmp',
        'symbol' => 'TEST',
        'report_date' => now()->toDateString(),
        'eps_estimated' => 1.00,
        'eps_actual' => 2.50, // big enough surprise to pass storage floor
        'eps_surprise' => 1.50,
        'eps_surprise_percent' => 150.0,
        'detected_at' => now(),
    ]);

    $provider = Mockery::mock(MarketDataProvider::class);
    // Simulate /profile being unavailable (gated plan / cache miss).
    $provider->shouldReceive('profile')->andReturn(null);
    // Composite quote() still returns the same fields via fallbacks.
    $provider->shouldReceive('quote')->andReturn([
        'symbol' => 'TEST',
        'price' => 100.0,
        'volume' => 1_000_000,
        'avg_volume' => 500_000,
        'company_name' => 'Test Co.',
        'exchange' => 'NASDAQ',
        'shares_outstanding' => 2_000_000,
        'float_shares' => 1_800_000,
        'free_float' => 0.9,
        // Provider-reported market_cap left null so we exercise the
        // canonical compute path: 100 × 2_000_000 = 200_000_000.
        'market_cap' => null,
    ]);

    $stocks = Mockery::mock(StockProvisioner::class);
    $stocks->shouldReceive('findOrCreate')->andReturnNull();

    (new EnrichEarningsEvent($event->id))
        ->handle($provider, app(EarningsSurpriseScorer::class), $stocks);

    $event->refresh();

    expect($event->company_name)->toBe('Test Co.');
    expect($event->exchange)->toBe('NASDAQ');
    // 100 × 2_000_000 — computed via MarketCap::compute().
    expect((int) $event->market_cap)->toBe(200_000_000);
    expect($event->shares_outstanding)->toBe(2_000_000);
    expect($event->float_shares)->toBe(1_800_000);
    expect((float) $event->free_float)->toBe(0.9);
});

it('falls back to provider-reported market_cap when shares_outstanding is unavailable', function () {
    $event = EarningsEvent::create([
        'source' => 'fmp',
        'symbol' => 'LEGACY',
        'report_date' => now()->toDateString(),
        'eps_estimated' => 1.00,
        'eps_actual' => 2.00,
        'eps_surprise' => 1.00,
        'eps_surprise_percent' => 100.0,
        'detected_at' => now(),
    ]);

    $provider = Mockery::mock(MarketDataProvider::class);
    $provider->shouldReceive('profile')->andReturn(null);
    $provider->shouldReceive('quote')->andReturn([
        'symbol' => 'LEGACY',
        'price' => 50.0,
        'company_name' => 'Legacy Inc.',
        'exchange' => 'NYSE',
        'shares_outstanding' => null,
        'market_cap' => 1_234_567_890,
    ]);

    $stocks = Mockery::mock(StockProvisioner::class);
    $stocks->shouldReceive('findOrCreate')->andReturnNull();

    (new EnrichEarningsEvent($event->id))
        ->handle($provider, app(EarningsSurpriseScorer::class), $stocks);

    $event->refresh();

    expect($event->company_name)->toBe('Legacy Inc.');
    expect($event->exchange)->toBe('NYSE');
    expect((int) $event->market_cap)->toBe(1_234_567_890);
});
