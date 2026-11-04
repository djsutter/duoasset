<?php

use App\Jobs\CheckEpsRevisionForSymbol;
use App\Models\EpsEstimateHistory;
use App\Models\EpsRevisionAlert;
use Illuminate\Support\Facades\Http;

/**
 * HTTP fake helper for FMP analyst-estimates. The provider issues
 *   GET /analyst-estimates?symbol=XXX&period=quarter
 * and the response is an array of period rows.
 */
function fakeAnalystEstimates(string $symbol, array $rows): void
{
    Http::fake(function ($request) use ($symbol, $rows) {
        $url = (string) $request->url();
        if (str_contains($url, '/analyst-estimates')) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);
            if (($q['symbol'] ?? null) === $symbol) {
                return Http::response($rows, 200);
            }

            return Http::response([], 200);
        }

        return Http::response([], 200);
    });
}

beforeEach(function () {
    config([
        'market_data.provider' => 'fmp',
        'market_data.fmp.api_key' => 'test-key',
        'market_data.fmp.base_url' => 'https://test.local/stable',
        'market_data.revision_scanner.enabled' => true,
        'market_data.revision_scanner.min_market_cap' => 100_000_000,
        'market_data.revision_scanner.positive_threshold' => 20,
        'market_data.revision_scanner.negative_threshold' => -20,
        'market_data.revision_scanner.exchanges' => ['NYSE', 'NASDAQ', 'TSX', 'TSXV'],
    ]);
});

/**
 * Pre-seed an existing history snapshot so the second run becomes a
 * comparison instead of a first-sight no-op.
 */
function seedHistory(string $symbol, string $period, float $previous): EpsEstimateHistory
{
    return EpsEstimateHistory::create([
        'source' => 'fmp',
        'symbol' => $symbol,
        'next_quarter_end_date' => $period,
        'eps_estimate' => $previous,
        'collected_at' => now()->subDay(),
    ]);
}

it('does not alert on first sight — it only stores the snapshot', function () {
    $period = now()->addDays(60)->toDateString();
    fakeAnalystEstimates('AAPL', [
        ['date' => $period, 'estimatedEpsAvg' => 2.50],
    ]);

    CheckEpsRevisionForSymbol::dispatchSync('AAPL', 'Apple', 'NASDAQ', 3_200_000_000_000);

    expect(EpsEstimateHistory::where('symbol', 'AAPL')->count())->toBe(1);
    expect(EpsRevisionAlert::count())->toBe(0);
});

it('creates a positive-direction alert when revision >= threshold', function () {
    $period = now()->addDays(60)->toDateString();
    seedHistory('AAPL', $period, 2.00); // previous

    fakeAnalystEstimates('AAPL', [
        ['date' => $period, 'estimatedEpsAvg' => 2.50], // +25% → above +20
    ]);

    CheckEpsRevisionForSymbol::dispatchSync('AAPL', 'Apple', 'NASDAQ', 3_200_000_000_000);

    $alert = EpsRevisionAlert::where('symbol', 'AAPL')->first();
    expect($alert)->not->toBeNull();
    expect($alert->direction)->toBe(EpsRevisionAlert::DIRECTION_POSITIVE);
    expect(round((float) $alert->revision_percent, 2))->toBe(25.0);
    expect((float) $alert->previous_estimate)->toBe(2.0);
    expect((float) $alert->latest_estimate)->toBe(2.5);
});

it('creates a negative-direction alert when revision <= negative threshold', function () {
    $period = now()->addDays(60)->toDateString();
    seedHistory('XYZ', $period, 4.00);

    fakeAnalystEstimates('XYZ', [
        ['date' => $period, 'estimatedEpsAvg' => 3.00], // -25% → below -20
    ]);

    CheckEpsRevisionForSymbol::dispatchSync('XYZ', 'XYZ Corp', 'NYSE', 5_000_000_000);

    $alert = EpsRevisionAlert::where('symbol', 'XYZ')->first();
    expect($alert)->not->toBeNull();
    expect($alert->direction)->toBe(EpsRevisionAlert::DIRECTION_NEGATIVE);
    expect(round((float) $alert->revision_percent, 2))->toBe(-25.0);
});

it('does not alert when revision is between thresholds', function () {
    $period = now()->addDays(60)->toDateString();
    seedHistory('CALM', $period, 2.00);

    fakeAnalystEstimates('CALM', [
        ['date' => $period, 'estimatedEpsAvg' => 2.10], // +5% → between
    ]);

    CheckEpsRevisionForSymbol::dispatchSync('CALM', 'CalmCo', 'NASDAQ', 5_000_000_000);

    expect(EpsRevisionAlert::count())->toBe(0);
    // Snapshot still refreshed.
    expect((float) EpsEstimateHistory::where('symbol', 'CALM')->first()->eps_estimate)->toBe(2.1);
});

it('prevents duplicate alerts on repeated runs', function () {
    $period = now()->addDays(60)->toDateString();
    seedHistory('DUP', $period, 2.00);

    fakeAnalystEstimates('DUP', [
        ['date' => $period, 'estimatedEpsAvg' => 2.50],
    ]);

    CheckEpsRevisionForSymbol::dispatchSync('DUP', 'DupCo', 'NASDAQ', 5_000_000_000);
    CheckEpsRevisionForSymbol::dispatchSync('DUP', 'DupCo', 'NASDAQ', 5_000_000_000);
    CheckEpsRevisionForSymbol::dispatchSync('DUP', 'DupCo', 'NASDAQ', 5_000_000_000);

    expect(EpsRevisionAlert::where('symbol', 'DUP')->count())->toBe(1);
});

it('skips the comparison when previous estimate is zero', function () {
    $period = now()->addDays(60)->toDateString();
    seedHistory('ZERO', $period, 0.0);

    fakeAnalystEstimates('ZERO', [
        ['date' => $period, 'estimatedEpsAvg' => 2.50],
    ]);

    CheckEpsRevisionForSymbol::dispatchSync('ZERO', 'ZeroCo', 'NYSE', 5_000_000_000);

    expect(EpsRevisionAlert::count())->toBe(0);
    // History was refreshed so the *next* run has a valid denominator.
    expect((float) EpsEstimateHistory::where('symbol', 'ZERO')->first()->eps_estimate)->toBe(2.5);
});

it('skips non-equity instruments such as redeemed bonds from EPS revision checks and cleans up alerts', function () {
    $period = now()->addDays(60)->toDateString();
    fakeAnalystEstimates('BWNB', [
        ['date' => $period, 'estimatedEpsAvg' => 2.50],
    ]);

    // Pre-create an existing false-positive alert
    EpsRevisionAlert::create([
        'source' => 'fmp',
        'symbol' => 'BWNB',
        'next_quarter_end_date' => $period,
        'alert_type' => 'eps_revision',
        'direction' => EpsRevisionAlert::DIRECTION_POSITIVE,
        'previous_estimate' => 1.0,
        'latest_estimate' => 2.5,
        'revision_percent' => 150.0,
        'status' => 'new',
        'detected_at' => now(),
    ]);
    expect(EpsRevisionAlert::where('symbol', 'BWNB')->count())->toBe(1);

    CheckEpsRevisionForSymbol::dispatchSync('BWNB', 'Babcock & Wilcox Enterprises Inc. 6.50% Senior Notes due 2026', 'NYSE', 100_000_000);

    expect(EpsEstimateHistory::where('symbol', 'BWNB')->count())->toBe(0);
    expect(EpsRevisionAlert::where('symbol', 'BWNB')->count())->toBe(0);
});

it('rejects explicitly requested inactive securities using structured profile metadata and cleans up alerts', function () {
    $period = now()->addDays(60)->toDateString();

    // Mock FMP endpoints: profile has isActivelyTrading = false
    Http::fake(function ($request) use ($period) {
        $url = (string) $request->url();
        if (str_contains($url, '/profile')) {
            return Http::response([
                [
                    'symbol' => 'INACT',
                    'companyName' => 'Inactive Security Corp.',
                    'exchange' => 'NYSE',
                    'isActivelyTrading' => false,
                    'isEtf' => false,
                    'isFund' => false,
                    'type' => 'stock',
                ],
            ], 200);
        }
        if (str_contains($url, '/analyst-estimates')) {
            return Http::response([
                ['date' => $period, 'estimatedEpsAvg' => 3.00],
            ], 200);
        }

        return Http::response([], 200);
    });

    // Seed existing alert
    EpsRevisionAlert::create([
        'source' => 'fmp',
        'symbol' => 'INACT',
        'next_quarter_end_date' => $period,
        'alert_type' => 'eps_revision',
        'direction' => EpsRevisionAlert::DIRECTION_POSITIVE,
        'previous_estimate' => 1.0,
        'latest_estimate' => 3.0,
        'revision_percent' => 200.0,
        'status' => 'new',
        'detected_at' => now(),
    ]);
    expect(EpsRevisionAlert::where('symbol', 'INACT')->count())->toBe(1);

    // Explicit job dispatch
    CheckEpsRevisionForSymbol::dispatchSync('INACT', 'Inactive Security Corp.', 'NYSE', 100_000_000);

    // Existing alert must be deleted, no history created
    expect(EpsRevisionAlert::where('symbol', 'INACT')->count())->toBe(0);
    expect(EpsEstimateHistory::where('symbol', 'INACT')->count())->toBe(0);

    // Verify via command with --symbol=INACT --sync
    EpsRevisionAlert::create([
        'source' => 'fmp',
        'symbol' => 'INACT',
        'next_quarter_end_date' => $period,
        'alert_type' => 'eps_revision',
        'direction' => EpsRevisionAlert::DIRECTION_POSITIVE,
        'previous_estimate' => 1.0,
        'latest_estimate' => 3.0,
        'revision_percent' => 200.0,
        'status' => 'new',
        'detected_at' => now(),
    ]);

    $this->artisan('earnings:scan-revisions', [
        '--symbol' => ['INACT'],
        '--sync' => true,
    ])->assertSuccessful();

    expect(EpsRevisionAlert::where('symbol', 'INACT')->count())->toBe(0);
    expect(EpsEstimateHistory::where('symbol', 'INACT')->count())->toBe(0);
});

it('bypasses equity validation across EPS revision command and jobs when disabled', function () {
    config(['market_data.buy_setup_scanner.equity_validation_enabled' => false]);
    $period = now()->addDays(60)->toDateString();

    Http::fake(function ($request) use ($period) {
        $url = (string) $request->url();
        if (str_contains($url, '/profile')) {
            return Http::response([
                [
                    'symbol' => 'INACT',
                    'companyName' => 'Inactive Security Corp.',
                    'exchange' => 'NYSE',
                    'isActivelyTrading' => false,
                    'isEtf' => false,
                    'isFund' => false,
                    'type' => 'stock',
                ],
            ], 200);
        }
        if (str_contains($url, '/analyst-estimates')) {
            return Http::response([
                ['date' => $period, 'estimatedEpsAvg' => 3.00],
            ], 200);
        }

        return Http::response([], 200);
    });

    // When equity validation is disabled, CheckEpsRevisionForSymbol does not skip INACT
    CheckEpsRevisionForSymbol::dispatchSync('INACT', 'Inactive Security Corp.', 'NYSE', 100_000_000);

    // Initial snapshot should be created because validation was bypassed
    expect(EpsEstimateHistory::where('symbol', 'INACT')->count())->toBe(1);
});
