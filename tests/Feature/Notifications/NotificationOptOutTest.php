<?php

use App\Models\EarningsAlert;
use App\Models\EarningsEvent;
use App\Models\EpsRevisionAlert;
use App\Models\NotificationOptOut;
use App\Models\Stock;
use App\Models\StockBuySetupAlert;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistAlertEvent;
use App\Models\WatchlistAlertRule;
use App\Models\WatchlistItem;
use App\Notifications\EarningsSurpriseDetected;
use App\Notifications\EpsTargetRevised;
use App\Notifications\StockBuySetupDetected;
use App\Notifications\WatchlistAlertMail;
use Livewire\Volt\Volt as LivewireVolt;

test('outbound notifications include valid opt-out url with email, type, and key', function () {
    $user = User::factory()->create(['email' => 'trader@example.com']);

    // 1. Earnings Surprise
    $event = EarningsEvent::create([
        'source' => 'fmp',
        'symbol' => 'AAPL',
        'report_date' => now()->toDateString(),
        'eps_estimated' => 1.00,
        'eps_actual' => 2.50,
        'eps_surprise' => 1.50,
        'eps_surprise_percent' => 150.0,
        'detected_at' => now(),
    ]);
    $alert = EarningsAlert::create([
        'earnings_event_id' => $event->id,
        'symbol' => 'AAPL',
        'alert_type' => 'eps_surprise',
        'direction' => EarningsAlert::DIRECTION_POSITIVE,
        'score' => 50,
        'status' => 'new',
        'message' => '',
    ]);
    $earningsNotification = new EarningsSurpriseDetected($event, $alert);
    $mailMessage1 = $earningsNotification->toMail($user);

    $optOut1 = NotificationOptOut::where('email', 'trader@example.com')
        ->where('item_type', NotificationOptOut::TYPE_EARNINGS)
        ->first();
    expect($optOut1)->not->toBeNull();

    $expectedUrl1 = route('notifications.opt-out', [
        'email' => 'trader@example.com',
        'type' => NotificationOptOut::TYPE_EARNINGS,
        'key' => $optOut1->token,
    ]);
    expect(implode("\n", array_merge($mailMessage1->introLines, $mailMessage1->outroLines)))->toContain($expectedUrl1);

    // 2. EPS Revision
    $revisionAlert = EpsRevisionAlert::create([
        'symbol' => 'AAPL',
        'source' => 'fmp',
        'period' => '2026-Q4',
        'alert_type' => 'eps_revision',
        'direction' => 'positive',
        'previous_estimate' => 1.0,
        'latest_estimate' => 2.0,
        'revision_percent' => 100,
        'market_cap' => 5_000_000_000,
        'status' => 'new',
        'message' => '',
    ]);
    $revisionNotification = new EpsTargetRevised($revisionAlert);
    $mailMessage2 = $revisionNotification->toMail($user);

    $optOut2 = NotificationOptOut::where('email', 'trader@example.com')
        ->where('item_type', NotificationOptOut::TYPE_REVISION)
        ->first();
    expect($optOut2)->not->toBeNull();
    $expectedUrl2 = route('notifications.opt-out', [
        'email' => 'trader@example.com',
        'type' => NotificationOptOut::TYPE_REVISION,
        'key' => $optOut2->token,
    ]);
    expect(implode("\n", array_merge($mailMessage2->introLines, $mailMessage2->outroLines)))->toContain($expectedUrl2);

    // 3. Stock Buy Setup
    $buyAlert = StockBuySetupAlert::create([
        'symbol' => 'AAPL',
        'source' => 'fmp',
        'spike_date' => now()->toDateString(),
        'setup_score' => 85,
        'heartbeat_score' => 80,
        'setup_type' => 'cup_with_handle',
        'status' => 'new',
    ]);
    $buyNotification = new StockBuySetupDetected($buyAlert);
    $mailMessage3 = $buyNotification->toMail($user);

    $optOut3 = NotificationOptOut::where('email', 'trader@example.com')
        ->where('item_type', NotificationOptOut::TYPE_BUY_SETUP)
        ->first();
    expect($optOut3)->not->toBeNull();
    $expectedUrl3 = route('notifications.opt-out', [
        'email' => 'trader@example.com',
        'type' => NotificationOptOut::TYPE_BUY_SETUP,
        'key' => $optOut3->token,
    ]);
    expect(implode("\n", array_merge($mailMessage3->introLines, $mailMessage3->outroLines)))->toContain($expectedUrl3);

    // 4. Watchlist Alert
    $stock = Stock::factory()->create(['symbol' => 'AAPL']);
    $watchlist = Watchlist::factory()->create(['user_id' => $user->id]);
    $item = WatchlistItem::factory()->create(['watchlist_id' => $watchlist->id, 'stock_id' => $stock->id]);
    $rule = WatchlistAlertRule::factory()->create(['watchlist_item_id' => $item->id]);
    $watchEvent = WatchlistAlertEvent::factory()->create(['watchlist_alert_rule_id' => $rule->id]);

    $watchlistNotification = new WatchlistAlertMail($watchEvent, $rule, $item, $stock);
    $mailMessage4 = $watchlistNotification->toMail($user);

    $optOut4 = NotificationOptOut::where('email', 'trader@example.com')
        ->where('item_type', NotificationOptOut::TYPE_WATCHLIST)
        ->first();
    expect($optOut4)->not->toBeNull();
    $expectedUrl4 = route('notifications.opt-out', [
        'email' => 'trader@example.com',
        'type' => NotificationOptOut::TYPE_WATCHLIST,
        'key' => $optOut4->token,
    ]);
    expect(implode("\n", array_merge($mailMessage4->introLines, $mailMessage4->outroLines)))->toContain($expectedUrl4);
});

test('opt-out page shows details with valid email, type, and key', function () {
    $optOut = NotificationOptOut::getOrCreate('user@example.com', NotificationOptOut::TYPE_EARNINGS);

    $response = $this->get(route('notifications.opt-out', [
        'email' => 'user@example.com',
        'type' => NotificationOptOut::TYPE_EARNINGS,
        'key' => $optOut->token,
    ]));

    $response->assertStatus(200);
    $response->assertSee('user@example.com');
    $response->assertSee('EPS Earnings Surprise Alerts');
    $response->assertSee('Unsubscribe from this notification');
});

test('opt-out page rejects invalid or missing parameters gracefully', function () {
    $response = $this->get(route('notifications.opt-out', [
        'email' => 'user@example.com',
        'type' => NotificationOptOut::TYPE_EARNINGS,
        'key' => 'invalid-token',
    ]));

    $response->assertStatus(200);
    $response->assertSee('Invalid or expired opt-out link');
});

test('user can opt out via public post endpoint and it updates user preferences idempotently', function () {
    $user = User::factory()->create([
        'email' => 'trader@example.com',
        'notify_eps_earnings' => true,
    ]);

    $optOut = NotificationOptOut::getOrCreate('trader@example.com', NotificationOptOut::TYPE_EARNINGS);

    $response = $this->post(route('notifications.opt-out.update'), [
        'email' => 'trader@example.com',
        'type' => NotificationOptOut::TYPE_EARNINGS,
        'key' => $optOut->token,
        'action' => 'opt_out',
    ]);

    $response->assertRedirect(route('notifications.opt-out', [
        'email' => 'trader@example.com',
        'type' => NotificationOptOut::TYPE_EARNINGS,
        'key' => $optOut->token,
    ]));

    $optOut->refresh();
    $user->refresh();

    expect($optOut->opted_out_at)->not->toBeNull();
    expect($user->notify_eps_earnings)->toBeFalse();
    expect(NotificationOptOut::isOptedOut('trader@example.com', NotificationOptOut::TYPE_EARNINGS))->toBeTrue();

    // Idempotent retry
    $this->post(route('notifications.opt-out.update'), [
        'email' => 'trader@example.com',
        'type' => NotificationOptOut::TYPE_EARNINGS,
        'key' => $optOut->token,
        'action' => 'opt_out',
    ]);

    $user->refresh();
    expect($user->notify_eps_earnings)->toBeFalse();
});

test('opting out of one item type does not disable other types', function () {
    $user = User::factory()->create([
        'email' => 'trader@example.com',
        'notify_eps_earnings' => true,
        'notify_eps_revisions' => true,
        'notify_stock_buy_setup' => true,
    ]);

    $optOut = NotificationOptOut::getOrCreate('trader@example.com', NotificationOptOut::TYPE_EARNINGS);
    $optOut->optOut();

    $user->refresh();
    expect($user->notify_eps_earnings)->toBeFalse();
    expect($user->notify_eps_revisions)->toBeTrue();
    expect($user->notify_stock_buy_setup)->toBeTrue();

    expect(NotificationOptOut::isOptedOut('trader@example.com', NotificationOptOut::TYPE_EARNINGS))->toBeTrue();
    expect(NotificationOptOut::isOptedOut('trader@example.com', NotificationOptOut::TYPE_REVISION))->toBeFalse();
    expect(NotificationOptOut::isOptedOut('trader@example.com', NotificationOptOut::TYPE_BUY_SETUP))->toBeFalse();
});

test('user can opt back in via public post endpoint', function () {
    $user = User::factory()->create([
        'email' => 'trader@example.com',
        'notify_eps_earnings' => false,
    ]);

    $optOut = NotificationOptOut::getOrCreate('trader@example.com', NotificationOptOut::TYPE_EARNINGS);
    $optOut->optOut();

    $response = $this->post(route('notifications.opt-out.update'), [
        'email' => 'trader@example.com',
        'type' => NotificationOptOut::TYPE_EARNINGS,
        'key' => $optOut->token,
        'action' => 'opt_in',
    ]);

    $response->assertRedirect();
    $optOut->refresh();
    $user->refresh();

    expect($optOut->opted_out_at)->toBeNull();
    expect($user->notify_eps_earnings)->toBeTrue();
    expect(NotificationOptOut::isOptedOut('trader@example.com', NotificationOptOut::TYPE_EARNINGS))->toBeFalse();
});

test('rotating token invalidates old token', function () {
    $optOut = NotificationOptOut::getOrCreate('trader@example.com', NotificationOptOut::TYPE_EARNINGS);
    $oldToken = $optOut->token;

    $newToken = $optOut->rotateToken();
    expect($newToken)->not->toEqual($oldToken);

    $response = $this->get(route('notifications.opt-out', [
        'email' => 'trader@example.com',
        'type' => NotificationOptOut::TYPE_EARNINGS,
        'key' => $oldToken,
    ]));

    $response->assertSee('Invalid or expired opt-out link');
});

test('user profile settings support updating stock buy setup notifications', function () {
    $user = User::factory()->create([
        'notify_eps_earnings' => true,
        'notify_eps_revisions' => true,
        'notify_stock_buy_setup' => false,
    ]);

    $this->actingAs($user);

    $component = LivewireVolt::test('settings.profile')
        ->set('notify_stock_buy_setup', true)
        ->call('updateProfileInformation');

    $component->assertHasNoErrors();
    $user->refresh();
    expect($user->notify_stock_buy_setup)->toBeTrue();
});

test('jobs do not deliver notifications to users opted out via notification opt-out', function () {
    \Illuminate\Support\Facades\Notification::fake();

    $user = User::factory()->create([
        'email' => 'optout@example.com',
        'notify_eps_earnings' => true,
    ]);

    $optOut = NotificationOptOut::getOrCreate('optout@example.com', NotificationOptOut::TYPE_EARNINGS);
    $optOut->optOut();

    $event = EarningsEvent::create([
        'source' => 'fmp',
        'symbol' => 'OPTOUT',
        'report_date' => now()->toDateString(),
        'eps_estimated' => 1.00,
        'eps_actual' => 2.50,
        'eps_surprise' => 1.50,
        'eps_surprise_percent' => 150.0,
        'detected_at' => now(),
    ]);
    $alert = EarningsAlert::create([
        'earnings_event_id' => $event->id,
        'symbol' => 'OPTOUT',
        'alert_type' => 'eps_surprise',
        'direction' => EarningsAlert::DIRECTION_POSITIVE,
        'score' => 50,
        'status' => 'new',
        'message' => '',
    ]);

    \App\Jobs\SendEarningsAlert::dispatchSync($alert->id);

    \Illuminate\Support\Facades\Notification::assertNotSentTo($user, EarningsSurpriseDetected::class);
});
