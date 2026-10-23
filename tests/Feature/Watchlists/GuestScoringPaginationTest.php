<?php

use App\Livewire\Watchlists\EarningsSurprises;
use App\Livewire\Watchlists\EpsRevisions;
use App\Livewire\Watchlists\StockBuySetups;
use App\Models\Currency;
use App\Models\EarningsEvent;
use App\Models\EpsRevisionAlert;
use App\Models\StockBuySetupAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Currency::factory()->create([
        'currency_code' => 'CAD',
        'name' => 'Canadian Dollar',
        'symbol' => '$',
        'scale' => 2,
        'display_scale' => 2,
        'type' => 'fiat',
        'is_active' => true,
    ]);

    config([
        'market_data.earnings_scanner.min_eps_surprise_percent' => null,
        'market_data.earnings_scanner.min_market_cap' => null,
        'market_data.revision_scanner.positive_threshold' => null,
        'market_data.revision_scanner.min_market_cap' => null,
    ]);
});

function seedStockBuySetupAlerts(int $count = 100): void
{
    for ($i = 1; $i <= $count; $i++) {
        StockBuySetupAlert::create([
            'symbol' => 'SETUP' . $i,
            'source' => 'test',
            'setup_type' => 'vol_spike',
            'setup_score' => 85,
            'spike_date' => now(),
            'detected_at' => now()->subMinutes($i),
            'status' => 'detected',
        ]);
    }
}

function seedEarningsEvents(int $count = 100): void
{
    for ($i = 1; $i <= $count; $i++) {
        EarningsEvent::create([
            'symbol' => 'EARN' . $i,
            'source' => 'test',
            'report_date' => now()->subDays($i),
            'detected_at' => now()->subMinutes($i),
            'eps_surprise_percent' => 100.0,
            'market_cap' => 200_000_000,
        ]);
    }
}

function seedEpsRevisionAlerts(int $count = 100): void
{
    for ($i = 1; $i <= $count; $i++) {
        EpsRevisionAlert::create([
            'symbol' => 'REV' . $i,
            'source' => 'fmp',
            'previous_estimate' => 1.00,
            'latest_estimate' => 1.50,
            'revision_percent' => 50.0,
            'direction' => 'positive',
            'alert_type' => 'eps_revision',
            'status' => 'new',
            'market_cap' => 200_000_000,
            'next_quarter_end_date' => now()->addDays($i)->toDateString(),
            'detected_at' => now()->subMinutes($i),
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| Stock Buy Setups Clamping & Preview
|--------------------------------------------------------------------------
*/

test('guest user is strictly clamped to page 4 on stock buy setups across incoming query pages', function (int $requestedPage) {
    seedStockBuySetupAlerts(105);
    $guest = User::factory()->guest()->create();

    $test = Livewire::actingAs($guest)
        ->withQueryParams(['page' => $requestedPage])
        ->test(StockBuySetups::class);

    expect($test->instance()->getPage())->toBe(4);
    expect($test->viewData('alerts')->currentPage())->toBe(4);
})->with([1, 2, 3, 5, 99]);

test('guest user calling pagination action methods on stock buy setups is locked to page 4', function () {
    seedStockBuySetupAlerts(105);
    $guest = User::factory()->guest()->create();

    $test = Livewire::actingAs($guest)->test(StockBuySetups::class);
    expect($test->instance()->getPage())->toBe(4);

    $test->call('setPage', 1);
    expect($test->instance()->getPage())->toBe(4);

    $test->call('gotoPage', 2);
    expect($test->instance()->getPage())->toBe(4);

    $test->call('resetPage');
    expect($test->instance()->getPage())->toBe(4);

    $test->call('nextPage');
    expect($test->instance()->getPage())->toBe(4);

    $test->call('previousPage');
    expect($test->instance()->getPage())->toBe(4);
});

test('guest user on stock buy setups sees guest preview banner and upgrade cta instead of standard links', function () {
    seedStockBuySetupAlerts(105);
    $guest = User::factory()->guest()->create();

    $test = Livewire::actingAs($guest)->test(StockBuySetups::class);

    $test->assertSeeHtml('data-testid="guest-preview-banner"');
    $test->assertSeeHtml('data-testid="guest-preview-subscribe-button"');
    $test->assertSee('Guest Preview: Page 4 Sample Results');
    $test->assertSee('Subscribe for Full Access');
    $test->assertDontSeeHtml('aria-label="Pagination Navigation"');
});

test('stock buy setups handles underflow gracefully when dataset has fewer than 4 pages', function () {
    seedStockBuySetupAlerts(10);
    $guest = User::factory()->guest()->create();

    $test = Livewire::actingAs($guest)->test(StockBuySetups::class);

    expect($test->viewData('alerts')->lastPage())->toBeLessThan(4);
    $test->assertSeeHtml('data-testid="guest-preview-underflow-banner"');
    $test->assertSee('Guest Preview: Sample Status');
    $test->assertSee('Subscribe for Full Access');
    $test->assertSee('Guest preview is strictly clamped to page 4.');
});

test('guest user accessing stock buy setups route directly is clamped to page 4', function () {
    seedStockBuySetupAlerts(105);
    $guest = User::factory()->guest()->create();

    $response = $this->actingAs($guest)->get('/watchlist/stock-buy-setups?page=1');

    $response->assertOk();
    $response->assertSee('Guest Preview: Page 4 Sample Results');
    $response->assertSee('Subscribe for Full Access');
});

/*
|--------------------------------------------------------------------------
| Earnings Surprises Clamping & Preview
|--------------------------------------------------------------------------
*/

test('guest user is strictly clamped to page 4 on earnings surprises across incoming query pages', function (int $requestedPage) {
    seedEarningsEvents(105);
    $guest = User::factory()->guest()->create();

    $test = Livewire::actingAs($guest)
        ->withQueryParams(['page' => $requestedPage])
        ->test(EarningsSurprises::class);

    expect($test->instance()->getPage())->toBe(4);
    expect($test->viewData('events')->currentPage())->toBe(4);
})->with([1, 2, 3, 5, 99]);

test('guest user calling pagination action methods on earnings surprises is locked to page 4', function () {
    seedEarningsEvents(105);
    $guest = User::factory()->guest()->create();

    $test = Livewire::actingAs($guest)->test(EarningsSurprises::class);
    expect($test->instance()->getPage())->toBe(4);

    $test->call('setPage', 1);
    expect($test->instance()->getPage())->toBe(4);

    $test->call('gotoPage', 2);
    expect($test->instance()->getPage())->toBe(4);

    $test->call('resetPage');
    expect($test->instance()->getPage())->toBe(4);

    $test->call('nextPage');
    expect($test->instance()->getPage())->toBe(4);

    $test->call('previousPage');
    expect($test->instance()->getPage())->toBe(4);
});

test('guest user on earnings surprises sees guest preview banner and upgrade cta', function () {
    seedEarningsEvents(105);
    $guest = User::factory()->guest()->create();

    $test = Livewire::actingAs($guest)->test(EarningsSurprises::class);

    expect($test->viewData('events')->lastPage())->toBeGreaterThanOrEqual(4);
    $test->assertSeeHtml('data-testid="guest-preview-banner"');
    $test->assertSeeHtml('data-testid="guest-preview-subscribe-button"');
    $test->assertSee('Guest Preview: Page 4 Sample Results');
    $test->assertSee('Subscribe for Full Access');
    $test->assertDontSeeHtml('aria-label="Pagination Navigation"');
});

test('earnings surprises handles underflow gracefully when dataset has fewer than 4 pages', function () {
    seedEarningsEvents(10);
    $guest = User::factory()->guest()->create();

    $test = Livewire::actingAs($guest)->test(EarningsSurprises::class);

    expect($test->viewData('events')->lastPage())->toBeLessThan(4);
    $test->assertSeeHtml('data-testid="guest-preview-underflow-banner"');
    $test->assertSee('Guest Preview: Sample Status');
    $test->assertSee('Subscribe for Full Access');
    $test->assertSee('Guest preview is strictly clamped to page 4.');
});

test('guest user accessing earnings surprises route directly is clamped to page 4', function () {
    seedEarningsEvents(105);
    $guest = User::factory()->guest()->create();

    $response = $this->actingAs($guest)->get('/watchlist/earnings-surprises?page=2');

    $response->assertOk();
    $response->assertSee('Guest Preview: Page 4 Sample Results');
    $response->assertSee('Subscribe for Full Access');
});

/*
|--------------------------------------------------------------------------
| EPS Revisions Clamping & Preview
|--------------------------------------------------------------------------
*/

test('guest user is strictly clamped to page 4 on eps revisions across incoming query pages', function (int $requestedPage) {
    seedEpsRevisionAlerts(105);
    $guest = User::factory()->guest()->create();

    $test = Livewire::actingAs($guest)
        ->withQueryParams(['page' => $requestedPage])
        ->test(EpsRevisions::class);

    expect($test->instance()->getPage())->toBe(4);
    expect($test->viewData('alerts')->currentPage())->toBe(4);
})->with([1, 2, 3, 5, 99]);

test('guest user calling pagination action methods on eps revisions is locked to page 4', function () {
    seedEpsRevisionAlerts(105);
    $guest = User::factory()->guest()->create();

    $test = Livewire::actingAs($guest)->test(EpsRevisions::class);
    expect($test->instance()->getPage())->toBe(4);

    $test->call('setPage', 1);
    expect($test->instance()->getPage())->toBe(4);

    $test->call('gotoPage', 2);
    expect($test->instance()->getPage())->toBe(4);

    $test->call('resetPage');
    expect($test->instance()->getPage())->toBe(4);

    $test->call('nextPage');
    expect($test->instance()->getPage())->toBe(4);

    $test->call('previousPage');
    expect($test->instance()->getPage())->toBe(4);
});

test('guest user on eps revisions sees guest preview banner and upgrade cta', function () {
    seedEpsRevisionAlerts(105);
    $guest = User::factory()->guest()->create();

    $test = Livewire::actingAs($guest)->test(EpsRevisions::class);

    $test->assertSeeHtml('data-testid="guest-preview-banner"');
    $test->assertSeeHtml('data-testid="guest-preview-subscribe-button"');
    $test->assertSee('Guest Preview: Page 4 Sample Results');
    $test->assertSee('Subscribe for Full Access');
    $test->assertDontSeeHtml('aria-label="Pagination Navigation"');
});

test('eps revisions handles underflow gracefully when dataset has fewer than 4 pages', function () {
    seedEpsRevisionAlerts(10);
    $guest = User::factory()->guest()->create();

    $test = Livewire::actingAs($guest)->test(EpsRevisions::class);

    expect($test->viewData('alerts')->lastPage())->toBeLessThan(4);
    $test->assertSeeHtml('data-testid="guest-preview-underflow-banner"');
    $test->assertSee('Guest Preview: Sample Status');
    $test->assertSee('Subscribe for Full Access');
    $test->assertSee('Guest preview is strictly clamped to page 4.');
});

test('guest user accessing eps revisions route directly is clamped to page 4', function () {
    seedEpsRevisionAlerts(105);
    $guest = User::factory()->guest()->create();

    $response = $this->actingAs($guest)->get('/watchlist/eps-revisions?page=3');

    $response->assertOk();
    $response->assertSee('Guest Preview: Page 4 Sample Results');
    $response->assertSee('Subscribe for Full Access');
});

/*
|--------------------------------------------------------------------------
| Subscribed & Stock-Authorized Users Pagination
|--------------------------------------------------------------------------
*/

test('equities subscriber has unrestricted pagination access across all scoring components', function () {
    seedStockBuySetupAlerts(105);
    seedEarningsEvents(105);
    seedEpsRevisionAlerts(105);

    $subscriber = User::factory()->equities()->create();

    // 1. Stock Buy Setups
    $setupTest = Livewire::actingAs($subscriber)
        ->withQueryParams(['page' => 2])
        ->test(StockBuySetups::class);

    expect($setupTest->instance()->getPage())->toBe(2);
    expect($setupTest->viewData('alerts')->currentPage())->toBe(2);
    $setupTest->assertDontSeeHtml('data-testid="guest-preview-banner"');

    $setupTest->call('setPage', 3);
    expect($setupTest->instance()->getPage())->toBe(3);

    $setupTest->call('resetPage');
    expect($setupTest->instance()->getPage())->toBe(1);

    // 2. Earnings Surprises
    $earnTest = Livewire::actingAs($subscriber)
        ->withQueryParams(['page' => 1])
        ->test(EarningsSurprises::class);

    expect($earnTest->instance()->getPage())->toBe(1);
    expect($earnTest->viewData('events')->currentPage())->toBe(1);
    $earnTest->assertDontSeeHtml('data-testid="guest-preview-banner"');

    $earnTest->call('setPage', 2);
    expect($earnTest->instance()->getPage())->toBe(2);

    // 3. EPS Revisions
    $revTest = Livewire::actingAs($subscriber)
        ->withQueryParams(['page' => 3])
        ->test(EpsRevisions::class);

    expect($revTest->instance()->getPage())->toBe(3);
    expect($revTest->viewData('alerts')->currentPage())->toBe(3);
    $revTest->assertDontSeeHtml('data-testid="guest-preview-banner"');

    $revTest->call('setPage', 1);
    expect($revTest->instance()->getPage())->toBe(1);
});

test('full subscription user has unrestricted pagination on stock scoring components', function () {
    seedStockBuySetupAlerts(105);
    $fullSub = User::factory()->fullSubscription()->create();

    $test = Livewire::actingAs($fullSub)
        ->withQueryParams(['page' => 1])
        ->test(StockBuySetups::class);

    expect($test->instance()->getPage())->toBe(1);
    expect($test->viewData('alerts')->currentPage())->toBe(1);
    $test->assertDontSeeHtml('data-testid="guest-preview-banner"');

    $test->call('setPage', 2);
    expect($test->instance()->getPage())->toBe(2);
});

test('administrator user has unrestricted pagination on stock scoring components', function () {
    seedStockBuySetupAlerts(105);
    $admin = User::factory()->administrator()->create();

    $test = Livewire::actingAs($admin)
        ->withQueryParams(['page' => 1])
        ->test(StockBuySetups::class);

    expect($test->instance()->getPage())->toBe(1);
    expect($test->viewData('alerts')->currentPage())->toBe(1);
    $test->assertDontSeeHtml('data-testid="guest-preview-banner"');

    $test->call('setPage', 3);
    expect($test->instance()->getPage())->toBe(3);
});
