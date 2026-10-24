<?php

use App\Models\StockBuySetupRun;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('stock buy setup run categorizes rejection reasons accurately', function () {
    expect(StockBuySetupRun::categorizeReason('insufficient history (180 < 252 bars)'))
        ->toBe(StockBuySetupRun::CATEGORY_INSUFFICIENT_HISTORY);

    expect(StockBuySetupRun::categorizeReason('company is too new (< 252 daily bars)'))
        ->toBe(StockBuySetupRun::CATEGORY_INSUFFICIENT_HISTORY);

    expect(StockBuySetupRun::categorizeReason('market cap below setup minimum (50000000)'))
        ->toBe(StockBuySetupRun::CATEGORY_BELOW_MIN_MARKET_CAP);

    expect(StockBuySetupRun::categorizeReason('market cap above setup maximum (2000000000)'))
        ->toBe(StockBuySetupRun::CATEGORY_ABOVE_MAX_MARKET_CAP);

    expect(StockBuySetupRun::categorizeReason('exchange mismatch (expected NASDAQ)'))
        ->toBe(StockBuySetupRun::CATEGORY_EXCHANGE_MISMATCH);

    expect(StockBuySetupRun::categorizeReason('no enabled setup detector matched'))
        ->toBe(StockBuySetupRun::CATEGORY_TECHNICAL_CRITERIA_UNMET);

    expect(StockBuySetupRun::categorizeReason('no high-volume spike within lookback'))
        ->toBe(StockBuySetupRun::CATEGORY_TECHNICAL_CRITERIA_UNMET);

    expect(StockBuySetupRun::categorizeReason('base range compression too wide'))
        ->toBe(StockBuySetupRun::CATEGORY_TECHNICAL_CRITERIA_UNMET);

    expect(StockBuySetupRun::categorizeReason('FMP API connection failed'))
        ->toBe(StockBuySetupRun::CATEGORY_EXECUTION_ERROR);

    expect(StockBuySetupRun::categorizeReason('unspecified anomaly'))
        ->toBe(StockBuySetupRun::CATEGORY_OTHER);
});

test('stock buy setup run records results and updates cumulative counts atomically', function () {
    $run = StockBuySetupRun::create([
        'started_at' => now(),
        'status' => 'running',
        'trigger' => 'cli',
        'symbols_considered' => 3,
        'scored_count' => 0,
        'matched_count' => 0,
        'rejected_count' => 0,
        'error_count' => 0,
    ]);

    // Record rejected symbol (too new)
    StockBuySetupRun::recordResult($run->id, [
        'symbol' => 'NEWCO',
        'status' => 'rejected',
        'reason' => 'insufficient history (100 < 252 bars)',
    ]);

    // Record rejected symbol (low market cap)
    StockBuySetupRun::recordResult($run->id, [
        'symbol' => 'TINY',
        'status' => 'rejected',
        'reason' => 'market cap below setup minimum (50000000)',
    ]);

    // Record matched symbol
    StockBuySetupRun::recordResult($run->id, [
        'symbol' => 'WINR',
        'status' => 'matched',
        'matches' => [
            [
                'setup_type' => 'heartbeat_consolidation_spike',
                'setup_score' => 85,
                'status' => 'created',
            ],
        ],
    ]);

    $run->refresh();

    expect($run->scored_count)->toBe(3);
    expect($run->matched_count)->toBe(1);
    expect($run->rejected_count)->toBe(2);
    expect($run->error_count)->toBe(0);
    expect($run->status)->toBe('completed');
    expect($run->completed_at)->not->toBeNull();

    expect($run->categorized_reasons)->toHaveKey(StockBuySetupRun::CATEGORY_INSUFFICIENT_HISTORY, 1);
    expect($run->categorized_reasons)->toHaveKey(StockBuySetupRun::CATEGORY_BELOW_MIN_MARKET_CAP, 1);

    expect($run->top_matches)->toHaveCount(1);
    expect($run->top_matches[0]['symbol'])->toBe('WINR');
    expect($run->top_matches[0]['score'])->toBe(85);

    $breakdown = $run->rejectionBreakdown();
    expect($breakdown)->toBeArray();

    // Check percentage calculations
    $insufficientHistory = collect($breakdown)->firstWhere('key', StockBuySetupRun::CATEGORY_INSUFFICIENT_HISTORY);
    expect($insufficientHistory['count'])->toBe(1);
    expect($insufficientHistory['pct_of_rejected'])->toBe(50.0);
    expect($insufficientHistory['pct_of_scored'])->toBe(33.3);
});

test('stock buy setup run finishSync updates run with aggregated summary and duration', function () {
    $run = StockBuySetupRun::create([
        'started_at' => now()->subSeconds(45),
        'status' => 'running',
        'trigger' => 'cli-sync',
        'symbols_considered' => 50,
    ]);

    $summary = [
        'processed' => 50,
        'matched' => 3,
        'created' => 2,
        'existing' => 1,
        'rejected' => 45,
        'errors' => 2,
        'reasons' => [
            'insufficient history (200 < 252 bars)' => 20,
            'market cap below setup minimum (50000000)' => 15,
            'no enabled setup detector matched' => 10,
        ],
        'top' => [
            ['symbol' => 'HOT', 'setup_type' => 'heartbeat', 'score' => 95, 'status' => 'created'],
        ],
    ];

    $run->finishSync($summary, 45.2);
    $run->refresh();

    expect($run->status)->toBe('completed');
    expect($run->scored_count)->toBe(50);
    expect($run->matched_count)->toBe(3);
    expect($run->rejected_count)->toBe(45);
    expect($run->error_count)->toBe(2);
    expect($run->categorized_reasons[StockBuySetupRun::CATEGORY_INSUFFICIENT_HISTORY])->toBe(20);
    expect($run->categorized_reasons[StockBuySetupRun::CATEGORY_BELOW_MIN_MARKET_CAP])->toBe(15);
    expect($run->categorized_reasons[StockBuySetupRun::CATEGORY_TECHNICAL_CRITERIA_UNMET])->toBe(10);
    expect($run->formattedDuration())->toBe('45.2s');
});
