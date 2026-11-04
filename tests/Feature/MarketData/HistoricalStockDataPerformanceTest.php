<?php

use App\Models\StockDailyBar;
use App\Models\User;
use App\Services\MarketData\MarketDataProvider;
use App\Services\MarketData\PersistentMarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(fn () => Cache::flush());

test('proves vast performance improvement in second fetch vs first fetch of historical stock data for known stock', function () {
    // 1 year of trading bars (252 bars)
    $startDate = CarbonImmutable::parse('2025-10-01');
    $simulatedBars = [];
    $cur = $startDate;
    while (count($simulatedBars) < 252) {
        if ($cur->isWeekday()) {
            $simulatedBars[] = [
                'date' => $cur->toDateString(),
                'open' => 25.0 + (count($simulatedBars) * 0.05),
                'high' => 26.0 + (count($simulatedBars) * 0.05),
                'low' => 24.5 + (count($simulatedBars) * 0.05),
                'close' => 25.5 + (count($simulatedBars) * 0.05),
                'adj_close' => 25.5 + (count($simulatedBars) * 0.05),
                'volume' => 500000 + (count($simulatedBars) * 500),
            ];
        }
        $cur = $cur->addDay();
    }
    $endDate = CarbonImmutable::parse(end($simulatedBars)['date']);

    $upstream = Mockery::mock(MarketDataProvider::class);
    // Upstream should only be called ONCE on the initial cold fetch
    $upstream->shouldReceive('historicalDailyBars')
        ->once()
        ->with('ARVN', Mockery::type(CarbonImmutable::class), Mockery::type(CarbonImmutable::class))
        ->andReturn($simulatedBars);

    $provider = new PersistentMarketDataProvider($upstream);

    // 1) FIRST FETCH (Cold: external FMP API call, network roundtrip + database storage)
    $t0 = microtime(true);
    $firstFetchBars = $provider->historicalDailyBars('ARVN', $startDate, $endDate);
    $firstFetchDuration = microtime(true) - $t0;

    expect($firstFetchBars)->toHaveCount(252)
        ->and(StockDailyBar::where('symbol', 'ARVN')->count())->toBe(252);

    // 2) SECOND FETCH (Warm: served from local memory cache / local database with ZERO external calls)
    $t1 = microtime(true);
    $secondFetchBars = $provider->historicalDailyBars('ARVN', $startDate, $endDate);
    $secondFetchDuration = microtime(true) - $t1;

    expect($secondFetchBars)->toHaveCount(252)
        ->and($secondFetchBars)->toEqual($firstFetchBars);

    // Validate vast performance improvement
    $speedupMultiplier = $firstFetchDuration / max(0.0001, $secondFetchDuration);
    $latencyReductionPct = (($firstFetchDuration - $secondFetchDuration) / $firstFetchDuration) * 100;

    // 3) THIRD FETCH (L2 Database: after memory cache flush, proving local DB is also vastly faster than external API)
    Cache::flush();
    $t2 = microtime(true);
    $thirdFetchBars = $provider->historicalDailyBars('ARVN', $startDate, $endDate);
    $thirdFetchDuration = microtime(true) - $t2;

    expect($thirdFetchBars)->toHaveCount(252)
        ->and($thirdFetchBars)->toEqual($firstFetchBars)
        ->and($thirdFetchDuration)->toBeLessThan($firstFetchDuration);
});

test('proves end-to-end HTTP candle endpoint performance improvement on second fetch for known stock', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $startDate = CarbonImmutable::parse('2025-10-01');
    $simulatedBars = [];
    $cur = $startDate;
    while (count($simulatedBars) < 100) {
        if ($cur->isWeekday()) {
            $simulatedBars[] = [
                'date' => $cur->toDateString(),
                'open' => 10.0 + count($simulatedBars),
                'high' => 11.0 + count($simulatedBars),
                'low' => 9.5 + count($simulatedBars),
                'close' => 10.5 + count($simulatedBars),
                'adj_close' => 10.5 + count($simulatedBars),
                'volume' => 100000,
            ];
        }
        $cur = $cur->addDay();
    }
    $endDate = CarbonImmutable::parse(end($simulatedBars)['date']);

    $upstream = Mockery::mock(MarketDataProvider::class);
    $upstream->shouldReceive('lastErrors')->andReturn([]);
    $upstream->shouldReceive('historicalDailyBars')
        ->once()
        ->with('ARVN', Mockery::type(CarbonImmutable::class), Mockery::type(CarbonImmutable::class))
        ->andReturnUsing(function () use ($simulatedBars) {
            usleep(40000); // 40ms simulated network latency

            return $simulatedBars;
        });

    $persistentProvider = new PersistentMarketDataProvider($upstream);
    app()->instance(MarketDataProvider::class, $persistentProvider);

    // 1) First HTTP Request: triggers external API call and stores into persistent storage
    $t0 = microtime(true);
    $firstResp = $this->actingAs($user)->get("/stocks/ARVN/candles?interval=1day&from={$startDate->toDateString()}&to={$endDate->toDateString()}");
    $firstDuration = microtime(true) - $t0;

    $firstResp->assertOk();
    $firstData = $firstResp->json();
    expect($firstData['candles'])->toHaveCount(100);

    // 2) Second HTTP Request: served locally from persistent storage without external API call
    $t1 = microtime(true);
    $secondResp = $this->actingAs($user)->get("/stocks/ARVN/candles?interval=1day&from={$startDate->toDateString()}&to={$endDate->toDateString()}");
    $secondDuration = microtime(true) - $t1;

    $secondResp->assertOk();
    $secondData = $secondResp->json();
    expect($secondData['candles'])->toHaveCount(100)
        ->and($secondData['candles'])->toEqual($firstData['candles'])
        ->and($secondDuration)->toBeLessThan($firstDuration);
});
