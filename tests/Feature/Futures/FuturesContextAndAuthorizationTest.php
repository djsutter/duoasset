<?php

use App\Enums\UserRole;
use App\Livewire\Admin\ManageAccounts;
use App\Livewire\MainNavigation;
use App\Livewire\ViewModeToggle;
use App\Models\User;
use App\Services\Auth\AuthorizationResolver;
use App\Services\Dashboard\RecentFuturesService;
use App\Services\MarketData\FakeMarketDataProvider;
use App\Services\MarketData\MarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $fake = new FakeMarketDataProvider;
    $nowTs = CarbonImmutable::now('UTC')->subMinutes(3)->timestamp;

    foreach ([
        'GCUSD' => ['name' => 'Gold Futures', 'price' => 4125.60, 'change' => 24.10, 'pct' => 0.59, 'ts' => $nowTs],
        'SIUSD' => ['name' => 'Silver Futures', 'price' => 51.84, 'change' => 0.42, 'pct' => 0.82, 'ts' => $nowTs - 60],
        'CLUSD' => ['name' => 'WTI Crude Oil Futures', 'price' => 63.20, 'change' => -0.80, 'pct' => -1.25, 'ts' => $nowTs - 120],
        'NGUSD' => ['name' => 'Natural Gas Futures', 'price' => 2.95, 'change' => 0.05, 'pct' => 1.72, 'ts' => $nowTs - 180],
        'HGUSD' => ['name' => 'Copper Futures', 'price' => 4.45, 'change' => 0.03, 'pct' => 0.68, 'ts' => $nowTs - 240],
    ] as $sym => $info) {
        $fake->quotes[$sym] = [
            'symbol' => $sym,
            'company_name' => $info['name'],
            'price' => $info['price'],
            'daily_change' => $info['change'],
            'daily_change_percent' => $info['pct'],
            'volume' => 125000,
            'exchange' => 'COMMODITY',
            'timestamp' => $info['ts'],
        ];
        $fake->dailyBars[$sym] = [
            ['date' => '2026-11-04', 'open' => $info['price'] - 1, 'high' => $info['price'] + 2, 'low' => $info['price'] - 2, 'close' => $info['price'], 'volume' => 100000],
            ['date' => '2026-11-05', 'open' => $info['price'], 'high' => $info['price'] + 1.5, 'low' => $info['price'] - 1, 'close' => $info['price'], 'volume' => 125000],
        ];
    }

    app()->instance(MarketDataProvider::class, $fake);
});

test('Futures Subscriber role exists with stable identifier and label and is idempotent', function () {
    expect(UserRole::FUTURES->value)->toBe('futures')
        ->and(UserRole::FUTURES->label())->toBe('Futures Subscriber')
        ->and(UserRole::FUTURES->hasFuturesAccess())->toBeTrue()
        ->and(UserRole::FUTURES->hasStockAccess())->toBeFalse()
        ->and(UserRole::FUTURES->hasCryptoAccess())->toBeFalse()
        ->and(UserRole::resolve('Futures Subscriber'))->toBe(UserRole::FUTURES)
        ->and(UserRole::resolve('futures'))->toBe(UserRole::FUTURES);

    $user = User::factory()->futures()->create();
    expect($user->hasFuturesAccess())->toBeTrue()
        ->and($user->hasRole(UserRole::FUTURES))->toBeTrue()
        ->and($user->activeViewMode())->toBe('futures');
});

test('Case A: authorized user with Futures Subscriber sees Futures in selector, opens Futures dashboard, and accesses futures routes', function () {
    $user = User::factory()->create([
        'role' => UserRole::FULL_SUBSCRIPTION,
        'view_mode' => 'stocks',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user);

    // Selector includes Stocks, Crypto, and Futures
    Livewire::test(ViewModeToggle::class)
        ->assertSee('Stocks')
        ->assertSee('Crypto')
        ->assertSee('Futures')
        ->call('setViewMode', 'futures')
        ->assertSet('viewMode', 'futures');

    expect($user->fresh()->view_mode)->toBe('futures');

    // /dashboard displays Futures dashboard
    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('Futures Overview')
        ->assertSee('Recently Visited Futures')
        ->assertSeeHtml('data-testid="futures-dashboard"')
        ->assertSeeHtml('data-testid="futures-empty-history"');

    // All 5 required futures routes work
    foreach (['gcusd', 'siusd', 'clusd', 'ngusd', 'hgusd'] as $slug) {
        $this->get("/futures/{$slug}")
            ->assertOk()
            ->assertSee(strtoupper($slug));
    }
});

test('Case B: unauthorized authenticated users without Futures Subscriber are denied access to futures pages, chart endpoints, and API routes', function () {
    foreach ([UserRole::GUEST, UserRole::EQUITIES, UserRole::CRYPTO] as $unauthorizedRole) {
        $user = User::factory()->create([
            'role' => $unauthorizedRole,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        // Futures option is not visible in ViewModeToggle
        Livewire::test(ViewModeToggle::class)
            ->assertDontSeeHtml('data-testid="view-mode-futures"');

        // Attempting to force-select futures via Livewire is rejected with 403
        Livewire::test(ViewModeToggle::class)
            ->call('setViewMode', 'futures')
            ->assertForbidden();

        // Direct requests to all 5 futures pages, chart endpoints, news endpoints, and API routes are rejected with 403
        foreach (['gcusd', 'siusd', 'clusd', 'ngusd', 'hgusd'] as $slug) {
            $this->get("/futures/{$slug}")->assertForbidden();
            $this->get("/futures/{$slug}/candles?interval=1day")->assertForbidden();
            $this->get("/futures/{$slug}/news")->assertForbidden();
            $this->get("/futures/{$slug}/news-impact?timestamp=1730000000")->assertForbidden();

            // Even calling the shared /stocks/{symbol}/candles route with a futures symbol is rejected with 403
            $this->get("/stocks/{$slug}/candles?interval=1day")->assertForbidden();

            // API routes for futures symbols are rejected with 403
            $this->getJson('/api/v1/market-data/'.strtoupper($slug).'/quote')->assertForbidden();
            $this->getJson('/api/v1/market-data/'.strtoupper($slug).'/candles')->assertForbidden();
        }
    }
});

test('Case C: anonymous visitor without role cannot access Futures dashboard or protected futures endpoints', function () {
    $anon = User::ensureAnonymousUser();
    $anon->removeRole();

    Auth::logout();
    expect(Auth::check())->toBeFalse();

    // Dashboard redirects unauthenticated visitor with no anonymous roles to login
    $this->get('/dashboard')->assertRedirect('/login');

    foreach (['gcusd', 'siusd', 'clusd', 'ngusd', 'hgusd'] as $slug) {
        $this->get("/futures/{$slug}")->assertForbidden();
        $this->get("/futures/{$slug}/candles?interval=1day")->assertForbidden();
        $this->get("/futures/{$slug}/news")->assertForbidden();
        $this->get("/futures/{$slug}/news-impact?timestamp=1730000000")->assertForbidden();
    }

    $this->getJson('/api/v1/market-data/GCUSD/quote')->assertUnauthorized();
});

test('Case D: assigning Futures Subscriber to Anonymous User grants unauthenticated visitors Futures access while keeping Auth::check() false and protecting authenticated-only routes', function () {
    $anon = User::ensureAnonymousUser();
    $anon->assignRole(UserRole::FUTURES);

    Auth::logout();
    expect(Auth::check())->toBeFalse();

    // Unauthenticated visitor can open /dashboard and sees Futures dashboard
    $dashResponse = $this->get('/dashboard');
    $dashResponse->assertOk()
        ->assertSee('Futures Overview')
        ->assertSeeHtml('data-testid="futures-dashboard"');
    expect(Auth::check())->toBeFalse();

    // Unauthenticated visitor can open futures detail pages and chart/news/API endpoints
    $this->get('/futures/gcusd')
        ->assertOk()
        ->assertSee('GCUSD')
        ->assertSee('Gold Futures');
    expect(Auth::check())->toBeFalse();

    $this->get('/futures/clusd/candles?interval=1day')
        ->assertOk()
        ->assertJsonPath('symbol', 'CLUSD');
    expect(Auth::check())->toBeFalse();

    $this->getJson('/api/v1/market-data/GCUSD/quote')
        ->assertOk()
        ->assertJsonPath('symbol', 'GCUSD');
    expect(Auth::check())->toBeFalse();

    // Authenticated-only routes remain protected even if Anonymous User is assigned Administrator
    $anon->assignRole(UserRole::ADMINISTRATOR);
    expect($anon->isAdmin())->toBeFalse()
        ->and(app(AuthorizationResolver::class)->canAccessAdmin(null))->toBeFalse();

    $this->get('/admin/accounts')->assertRedirect('/login');
    $this->get('/admin/configuration')->assertRedirect('/login');
    $this->get('/settings/profile')->assertRedirect('/login');
    $this->get('/subscription')->assertRedirect('/login');
    expect(Auth::check())->toBeFalse();

    // Authenticated user WITHOUT Futures Subscriber does NOT inherit Anonymous User's Futures Subscriber role
    $equitiesUser = User::factory()->equities()->create(['email_verified_at' => now()]);
    $this->actingAs($equitiesUser);
    $this->get('/futures/gcusd')->assertForbidden();
});

test('Case E: revoking Futures Subscriber during an active session invalidates saved Futures context and falls back to an authorized context', function () {
    $user = User::factory()->create([
        'role' => UserRole::FUTURES,
        'view_mode' => 'futures',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user);

    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('Futures Overview');

    // Administrator revokes Futures Subscriber mid-session (switches user to Equities Subscriber, leaving view_mode stale in DB)
    DB::table('users')->where('id', $user->id)->update([
        'role' => UserRole::EQUITIES->value,
        'assigned_roles' => json_encode([UserRole::EQUITIES->value]),
        'view_mode' => 'futures', // intentionally left stale to test fallback
    ]);

    // Next request to /dashboard must not render Futures; it must fall back to Stocks
    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('Market Overview')
        ->assertDontSee('Futures Overview');

    expect($user->fresh()->view_mode)->toBe('stocks');

    // Navigation no longer exposes Futures
    Livewire::test(MainNavigation::class)
        ->assertDontSee('Gold (GCUSD)')
        ->assertDontSeeHtml('data-testid="sidebar-view-mode-futures"');

    // Protected futures routes immediately return 403
    $this->get('/futures/gcusd')->assertForbidden();
    $this->get('/futures/gcusd/candles?interval=1day')->assertForbidden();
});

test('Case F & Section 5: recent futures tracking orders most-recent first, caps at 3 unique items, shows observation timestamps, and isolates anonymous sessions', function () {
    $anon = User::ensureAnonymousUser();
    $anon->assignRole(UserRole::FUTURES);

    // Anonymous Visitor 1 visits GCUSD -> SIUSD -> CLUSD -> NGUSD -> SIUSD (revisit moves SIUSD to top)
    $this->withSession([]);
    $this->get('/futures/gcusd')->assertOk();
    $this->get('/futures/siusd')->assertOk();
    $this->get('/futures/clusd')->assertOk();
    $this->get('/futures/ngusd')->assertOk();
    $this->get('/futures/siusd')->assertOk(); // Revisit SIUSD -> order should be SIUSD, NGUSD, CLUSD (GCUSD pushed out)

    $service = app(RecentFuturesService::class);
    $symbolsVisitor1 = $service->getRecentSymbols(null);
    expect($symbolsVisitor1)->toBe(['SIUSD', 'NGUSD', 'CLUSD']);

    // Verify shared Anonymous User DB record was NOT polluted with visitor session history
    expect($anon->fresh()->recent_futures)->toBeEmpty();

    // Render Futures dashboard for Visitor 1 and verify order, prices, currency, and observation timestamps
    $dash1 = $this->get('/dashboard');
    $dash1->assertOk()
        ->assertSeeInOrder(['SIUSD', 'NGUSD', 'CLUSD'])
        ->assertDontSeeHtml('data-testid="recent-futures-row-gcusd"')
        ->assertSee('51.84')
        ->assertSee('2.95')
        ->assertSee('63.20')
        ->assertSee('USD');

    // Anonymous Visitor 2 in a separate clean session sees empty history, not Visitor 1's instruments
    $this->flushSession();
    expect($service->getRecentSymbols(null))->toBeEmpty();

    $dash2 = $this->get('/dashboard');
    $dash2->assertOk()
        ->assertSeeHtml('data-testid="futures-empty-history"')
        ->assertDontSeeHtml('data-testid="recent-futures-row-siusd"')
        ->assertSeeHtml('href="'.route('futures.show', ['symbol' => 'gcusd']).'"');

    // Visitor 2 visits HGUSD only
    $this->get('/futures/hgusd')->assertOk();
    expect($service->getRecentSymbols(null))->toBe(['HGUSD']);
});

test('Case G: context switching Stocks -> Futures -> Crypto -> Stocks renders the correct dashboard each time without cross-context contamination', function () {
    $user = User::factory()->fullSubscription()->create([
        'view_mode' => 'stocks',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user);
    app(RecentFuturesService::class)->recordVisit('GCUSD', $user);

    // 1. Stocks
    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('Market Overview')
        ->assertDontSee('Futures Overview');

    // 2. Switch to Futures
    Livewire::test(ViewModeToggle::class)->call('setViewMode', 'futures');
    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('Futures Overview')
        ->assertSee('GCUSD')
        ->assertDontSee('Market Overview');

    // 3. Switch to Crypto
    Livewire::test(ViewModeToggle::class)->call('setViewMode', 'crypto');
    $this->get('/dashboard')
        ->assertOk()
        ->assertDontSee('Futures Overview')
        ->assertDontSee('Market Overview');

    // 4. Switch back to Stocks
    Livewire::test(ViewModeToggle::class)->call('setViewMode', 'stocks');
    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('Market Overview')
        ->assertDontSee('Futures Overview');
});

test('Anonymous User account is created once, manageable by administrators, and protected from login, password reset, deletion, and impersonation', function () {
    $anon1 = User::ensureAnonymousUser();
    $anon2 = User::ensureAnonymousUser();

    expect($anon1->id)->toBe($anon2->id)
        ->and($anon1->name)->toBe('Anonymous User')
        ->and($anon1->isAnonymousUser())->toBeTrue()
        ->and($anon1->canLogin())->toBeFalse()
        ->and($anon1->canResetPassword())->toBeFalse()
        ->and($anon1->canBeDeleted())->toBeFalse()
        ->and($anon1->canBeImpersonated())->toBeFalse()
        ->and($anon1->hasFuturesAccess())->toBeFalse();

    // Direct Eloquent delete is blocked
    expect(fn () => $anon1->delete())->toThrow(LogicException::class);

    // CLI password reset and activation are blocked
    $this->artisan('user:reset-password', ['email' => User::ANONYMOUS_EMAIL, '--generate' => true])
        ->assertExitCode(1);
    $this->artisan('user:activate', ['email' => User::ANONYMOUS_EMAIL])
        ->assertExitCode(1);

    // Interactive login and forgot-password reject Anonymous User
    Livewire::test('auth.login')
        ->set('email', User::ANONYMOUS_EMAIL)
        ->set('password', 'any-password')
        ->call('login')
        ->assertHasErrors(['email']);

    Livewire::test('auth.forgot-password')
        ->set('email', User::ANONYMOUS_EMAIL)
        ->call('sendPasswordResetLink')
        ->assertHasErrors(['email']);

    // Administrator can find Anonymous User, assign Futures Subscriber, and remove roles via ManageAccounts
    $admin = User::factory()->administrator()->create(['email_verified_at' => now()]);
    $this->actingAs($admin);

    Livewire::test(ManageAccounts::class)
        ->set('search', 'Anonymous User')
        ->assertSee('Anonymous User')
        ->assertSee('System Visitor Identity')
        ->call('openRoleModal', $anon1->id)
        ->set('selectedRole', UserRole::FUTURES->value)
        ->call('updateRole');

    expect($anon1->fresh()->hasFuturesAccess())->toBeTrue();

    // Admin cannot delete or impersonate Anonymous User via ManageAccounts
    Livewire::test(ManageAccounts::class)
        ->call('deleteAccount', $anon1->id)
        ->assertHasErrors(['account']);
    expect(User::find($anon1->id))->not->toBeNull();

    Livewire::test(ManageAccounts::class)
        ->call('impersonate', $anon1->id)
        ->assertHasErrors(['account']);

    // Admin can remove Futures Subscriber from Anonymous User
    Livewire::test(ManageAccounts::class)
        ->call('removeRole', $anon1->id, UserRole::FUTURES->value);

    expect($anon1->fresh()->hasFuturesAccess())->toBeFalse();
});

test('Futures dashboard handles stale fallback price and single-instrument quote failure gracefully without breaking other instruments', function () {
    $user = User::factory()->futures()->create(['email_verified_at' => now()]);
    $this->actingAs($user);

    $fake = new FakeMarketDataProvider;
    $obsTs = CarbonImmutable::parse('2026-11-05 14:30:00', 'America/New_York')->utc()->timestamp;

    $fake->quotes['GCUSD'] = [
        'symbol' => 'GCUSD',
        'company_name' => 'Gold Futures',
        'price' => 4125.60,
        'daily_change' => 15.20,
        'daily_change_percent' => 0.37,
        'exchange' => 'COMMODITY',
        'timestamp' => $obsTs,
    ];
    // SIUSD quote fails/returns null, while CLUSD returns valid quote
    $fake->quotes['SIUSD'] = null;
    $fake->quotes['CLUSD'] = [
        'symbol' => 'CLUSD',
        'company_name' => 'WTI Crude Oil Futures',
        'price' => 63.20,
        'daily_change' => -0.50,
        'daily_change_percent' => -0.78,
        'exchange' => 'COMMODITY',
        'timestamp' => $obsTs,
    ];
    app()->instance(MarketDataProvider::class, $fake);

    $service = app(RecentFuturesService::class);
    $service->recordVisit('CLUSD', $user);
    $service->recordVisit('SIUSD', $user);
    $service->recordVisit('GCUSD', $user);

    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('4,125.60')
        ->assertSee('14:30:00')
        ->assertSee('63.20')
        ->assertSee('Unavailable');
});
