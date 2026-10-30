<?php

use App\Jobs\PrewarmDashboardCacheJob;
use App\Models\Stock;
use App\Models\StockBuySetupAlert;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;

test('valid login dispatches the dashboard pre-warm job before the two-factor redirect', function () {
    Queue::fake();
    $user = User::factory()->create(['email_verified_at' => now()]);

    Volt::test('auth.login')
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect(route('two-factor.challenge'));

    Queue::assertPushed(PrewarmDashboardCacheJob::class, fn ($job) => $job->userId === $user->id);
});

test('invalid credentials do not dispatch the pre-warm job', function () {
    Queue::fake();
    $user = User::factory()->create(['email_verified_at' => now()]);

    Volt::test('auth.login')
        ->set('email', $user->email)
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('email');

    Queue::assertNotPushed(PrewarmDashboardCacheJob::class);
});

test('pre-warm job fills the dashboard cache keys for the user', function () {
    $user = User::factory()->create();
    $watchlist = Watchlist::create(['user_id' => $user->id, 'name' => 'Main', 'is_default' => true]);
    WatchlistItem::factory()->create([
        'watchlist_id' => $watchlist->id,
        'stock_id' => Stock::factory()->create(['symbol' => 'WARM'])->id,
    ]);
    StockBuySetupAlert::create([
        'symbol' => 'WARM', 'company_name' => 'Warm Corp', 'setup_score' => 80,
        'setup_type' => 'vcp_breakout', 'spike_date' => '2026-10-20', 'source' => 'test',
    ]);

    Cache::flush();

    PrewarmDashboardCacheJob::dispatchSync($user->id);

    expect(Cache::has('dash_top_scoring_setups'))->toBeTrue()
        ->and(Cache::has('dash_highest_eps_surprises'))->toBeTrue()
        ->and(Cache::has('dash_money_flows_gainers'))->toBeTrue()
        ->and(Cache::has('dash_money_flows_losers'))->toBeTrue()
        ->and(Cache::has("dash_user_{$user->id}_gainers"))->toBeTrue();
});
