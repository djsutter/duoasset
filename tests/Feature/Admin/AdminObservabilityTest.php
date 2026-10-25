<?php

use App\Enums\UserRole;
use App\Livewire\Admin\Configuration;
use App\Livewire\Admin\ErrorLogs;
use App\Livewire\Admin\SetupRunReport;
use App\Models\Currency;
use App\Models\StockBuySetupRun;
use App\Models\User;
use App\Services\Admin\LogReaderService;
use App\Services\MarketData\MarketDataProvider;
use Livewire\Livewire;

beforeEach(function () {
    // Seed CAD currency which layout/widgets depend on
    Currency::factory()->create([
        'currency_code' => 'CAD',
        'name' => 'Canadian Dollar',
        'symbol' => '$',
        'scale' => 2,
        'display_scale' => 2,
        'type' => 'fiat',
        'is_active' => true,
    ]);
});

/*
|--------------------------------------------------------------------------
| Admin Observability Route Protection & Gating
|--------------------------------------------------------------------------
*/

test('unauthenticated users are redirected to login when accessing admin observability routes', function () {
    $this->get(route('admin.logs'))->assertRedirect(route('login'));
    $this->get(route('admin.setup-run-report'))->assertRedirect(route('login'));
});

test('non-administrator roles receive 403 forbidden when accessing admin observability routes', function () {
    $roles = [
        UserRole::GUEST,
        UserRole::EQUITIES,
        UserRole::CRYPTO,
        UserRole::FULL_SUBSCRIPTION,
    ];

    foreach ($roles as $role) {
        $user = User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('admin.logs'))
            ->assertStatus(403);

        $this->actingAs($user)
            ->get(route('admin.setup-run-report'))
            ->assertStatus(403);
    }
});

test('administrator can access admin error logs and setup run report routes', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->get(route('admin.logs'))
        ->assertOk()
        ->assertSee('Admin Error & Activity Log Viewer');

    $this->actingAs($admin)
        ->get(route('admin.setup-run-report'))
        ->assertOk()
        ->assertSee('Stock Buy Setup Run Dashboard');
});

/*
|--------------------------------------------------------------------------
| ErrorLogs Livewire Component Tests
|--------------------------------------------------------------------------
*/

test('error logs component lists log entries and filters by category, level, and search', function () {
    $admin = User::factory()->administrator()->create();

    $logContent = <<<'LOG'
[2026-10-24 07:00:00] local.INFO: buy_setup.notifying_user {"alert_id":5,"email":"investor@domain.com"}
[2026-10-24 07:01:00] local.ERROR: buy_setup.notify_failed {"alert_id":5,"error":"Connection refused"}
[2026-10-24 07:05:00] local.INFO: buy_setup.rejected {"symbol":"TINY","reason":"market cap below setup minimum (50000000)"}
[2026-10-24 07:06:00] local.INFO: buy_setup.evaluated {"symbol":"AAPL","matches":[{"setup_type":"heartbeat","setup_score":90}]}
[2026-10-24 07:10:00] local.WARNING: earnings.invalid_eps {"symbol":"XYZ","reason":"eps value invalid"}
LOG;

    $tempLog = tempnam(sys_get_temp_dir(), 'test_log_');
    file_put_contents($tempLog, $logContent);

    // Swap LogReaderService with one targeting the temporary fixture
    $service = new class($tempLog) extends LogReaderService
    {
        public function __construct(private string $customPath) {}

        public function getLogs(?string $logFilePath = null, string $type = self::TYPE_ALL, string $level = self::LEVEL_ALL, string $search = '', int $limit = 100, int $maxBytes = 2097152): array
        {
            return parent::getLogs($this->customPath, $type, $level, $search, $limit, $maxBytes);
        }
    };
    app()->instance(LogReaderService::class, $service);

    try {
        Livewire::actingAs($admin)
            ->test(ErrorLogs::class)
            // Initial render shows all entries
            ->assertSee('buy_setup.notifying_user')
            ->assertSee('buy_setup.notify_failed')
            ->assertSee('buy_setup.rejected')
            ->assertSee('buy_setup.evaluated')
            ->assertSee('earnings.invalid_eps')
            // Filter by Notifications
            ->call('setFilterType', 'notifications')
            ->assertSee('buy_setup.notifying_user')
            ->assertSee('buy_setup.notify_failed')
            ->assertDontSee('buy_setup.rejected')
            ->assertDontSee('earnings.invalid_eps')
            // Filter by Setup
            ->call('setFilterType', 'setup')
            ->assertSee('buy_setup.rejected')
            ->assertSee('buy_setup.evaluated')
            ->assertDontSee('buy_setup.notifying_user')
            ->assertDontSee('earnings.invalid_eps')
            // Filter by EPS
            ->call('setFilterType', 'eps')
            ->assertSee('earnings.invalid_eps')
            ->assertDontSee('buy_setup.rejected')
            // Filter by Level (errors only)
            ->call('setFilterType', 'all')
            ->call('setFilterLevel', 'error')
            ->assertSee('buy_setup.notify_failed')
            ->assertDontSee('buy_setup.notifying_user')
            ->assertDontSee('buy_setup.evaluated')
            // Search filter
            ->call('clearFilters')
            ->set('search', 'TINY')
            ->assertSee('TINY')
            ->assertDontSee('AAPL')
            // Modal details inspection
            ->call('showDetails', 0)
            ->assertSet('selectedEntryIndex', 0)
            ->call('closeDetails')
            ->assertSet('selectedEntryIndex', null);
    } finally {
        @unlink($tempLog);
    }
});

/*
|--------------------------------------------------------------------------
| SetupRunReport Livewire Component Tests
|--------------------------------------------------------------------------
*/

test('setup run report component renders empty state when no runs exist', function () {
    $admin = User::factory()->administrator()->create();

    Livewire::actingAs($admin)
        ->test(SetupRunReport::class)
        ->assertSee('No Setup Scan Runs Recorded Yet')
        ->assertSee('php artisan stocks:scan-buy-setups');
});

test('setup run report renders statistics, categorized rejection breakdown, and past runs', function () {
    $admin = User::factory()->administrator()->create();

    $run1 = StockBuySetupRun::create([
        'started_at' => now()->subHours(2),
        'completed_at' => now()->subHours(2)->addMinutes(1),
        'status' => 'completed',
        'trigger' => 'cli-sync',
        'symbols_considered' => 100,
        'scored_count' => 100,
        'matched_count' => 5,
        'rejected_count' => 95,
        'error_count' => 0,
        'categorized_reasons' => [
            StockBuySetupRun::CATEGORY_INSUFFICIENT_HISTORY => 40,
            StockBuySetupRun::CATEGORY_BELOW_MIN_MARKET_CAP => 30,
            StockBuySetupRun::CATEGORY_TECHNICAL_CRITERIA_UNMET => 25,
        ],
        'raw_reasons' => [
            'insufficient history (150 < 252 bars)' => 40,
            'market cap below setup minimum (50000000)' => 30,
            'no enabled setup detector matched' => 25,
        ],
        'top_matches' => [
            ['symbol' => 'ALPHA', 'setup_type' => 'heartbeat', 'score' => 92, 'status' => 'created'],
        ],
        'summary_data' => [
            'elapsed_seconds' => 60.5,
        ],
    ]);

    $run2 = StockBuySetupRun::create([
        'started_at' => now()->subMinutes(10),
        'completed_at' => now()->subMinutes(9),
        'status' => 'completed',
        'trigger' => 'cli',
        'symbols_considered' => 200,
        'scored_count' => 200,
        'matched_count' => 12,
        'rejected_count' => 188,
        'error_count' => 2,
        'categorized_reasons' => [
            StockBuySetupRun::CATEGORY_INSUFFICIENT_HISTORY => 100,
            StockBuySetupRun::CATEGORY_BELOW_MIN_MARKET_CAP => 50,
            StockBuySetupRun::CATEGORY_TECHNICAL_CRITERIA_UNMET => 38,
        ],
        'top_matches' => [
            ['symbol' => 'BETA', 'setup_type' => 'heartbeat', 'score' => 96, 'status' => 'created'],
        ],
        'summary_data' => [
            'elapsed_seconds' => 75.0,
        ],
    ]);

    Livewire::actingAs($admin)
        ->test(SetupRunReport::class)
        // Defaults to most recent run ($run2)
        ->assertSee('Run #'.$run2->id)
        ->assertSee('Latest / Most Recent')
        ->assertSee('200') // considered / scored
        ->assertSee('12')  // matched
        ->assertSee('188') // rejected
        ->assertSee('Too New / Insufficient History (< 252 bars)')
        ->assertSee('Below Market Cap Minimum')
        ->assertSee('Technical Setup Criteria Unmet')
        ->assertSee('BETA')
        // Can switch to previous run ($run1)
        ->call('selectRun', $run1->id)
        ->assertSee('Run #'.$run1->id)
        ->assertSee('ALPHA')
        ->assertSee('95'); // run1 rejected count
});

/*
|--------------------------------------------------------------------------
| Admin Configuration Console Tabs & Navigation Visibility Tests
|--------------------------------------------------------------------------
*/

test('admin configuration overview shows observability cards and switches to logs and setup-run tabs', function () {
    $admin = User::factory()->administrator()->create();

    StockBuySetupRun::create([
        'started_at' => now()->subMinutes(5),
        'completed_at' => now()->subMinutes(4),
        'status' => 'completed',
        'trigger' => 'cli-sync',
        'symbols_considered' => 50,
        'scored_count' => 50,
        'matched_count' => 2,
        'rejected_count' => 48,
        'error_count' => 0,
        'summary_data' => ['elapsed_seconds' => 15.0],
    ]);

    Livewire::actingAs($admin)
        ->test(Configuration::class)
        // Overview tab displays observability cards
        ->assertSee('Application Diagnostics & Error Logs')
        ->assertSee('Last Setup Run Report')
        ->assertSee('50') // scored count in overview
        // Switch to Error Logs tab
        ->call('setTab', 'logs')
        ->assertSet('tab', 'logs')
        ->assertSeeHtml('data-testid="embedded-error-logs"')
        // Switch to Setup Run Report tab
        ->call('setTab', 'setup-run')
        ->assertSet('tab', 'setup-run')
        ->assertSeeHtml('data-testid="embedded-setup-run-report"');
});

test('administrator sees error logs and setup run report links in navigation', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('admin.logs'))
        ->assertSee(route('admin.setup-run-report'))
        ->assertSee('Error Logs')
        ->assertSee('Setup Run Report');
});

test('non-administrator does not see admin observability links in navigation', function () {
    $user = User::factory()->equities()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('admin.logs'))
        ->assertDontSee(route('admin.setup-run-report'));
});

test('stocks:scan-buy-setups command creates and finalizes a setup scan run record', function () {
    $mockProvider = Mockery::mock(MarketDataProvider::class);
    $mockProvider->shouldReceive('clearErrors')->zeroOrMoreTimes();
    $mockProvider->shouldReceive('profile')->with('FEO')->andReturn([
        'company_name' => 'Oceanic Iron Ore Corp',
        'exchange' => 'TSXV',
        'market_cap' => 10000000,
        'price' => 0.10,
    ]);
    $mockProvider->shouldReceive('historicalDailyBars')->with('FEO', Mockery::any(), Mockery::any())->andReturn([]);

    app()->instance(MarketDataProvider::class, $mockProvider);

    expect(StockBuySetupRun::count())->toBe(0);

    $this->artisan('stocks:scan-buy-setups', [
        '--symbol' => ['FEO'],
        '--sync' => true,
    ])->assertSuccessful();

    expect(StockBuySetupRun::count())->toBe(1);

    $run = StockBuySetupRun::first();
    expect($run->status)->toBe('completed');
    expect($run->symbols_considered)->toBe(1);
    expect($run->scored_count)->toBe(1);
    expect($run->rejected_count)->toBe(1);
    expect($run->categorized_reasons)->toHaveKey(StockBuySetupRun::CATEGORY_INSUFFICIENT_HISTORY);
});
