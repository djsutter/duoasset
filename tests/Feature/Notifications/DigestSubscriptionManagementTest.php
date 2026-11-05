<?php

use App\Jobs\PrepareScannerRunDigests;
use App\Jobs\SendUserNotificationDigest;
use App\Models\EarningsAlert;
use App\Models\EarningsEvent;
use App\Models\EarningsScanRun;
use App\Models\NotificationDigestDelivery;
use App\Models\NotificationOptOut;
use App\Models\StockBuySetupAlert;
use App\Models\StockBuySetupRun;
use App\Models\User;
use App\Notifications\EpsSurpriseDigestNotification;
use App\Notifications\SetupScoringDigestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function seedCompletedEpsRunWithAlert(): EarningsScanRun
{
    $run = EarningsScanRun::create([
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
        'status' => EarningsScanRun::STATUS_COMPLETED,
    ]);

    $event = EarningsEvent::create([
        'source' => 'fmp',
        'scan_run_id' => $run->id,
        'symbol' => 'NVDA',
        'company_name' => 'NVIDIA Corp',
        'exchange' => 'NASDAQ',
        'report_date' => '2026-11-04',
        'eps_estimated' => 1.00,
        'eps_actual' => 2.50,
        'eps_surprise' => 1.50,
        'eps_surprise_percent' => 150.0,
        'market_cap' => 2_000_000_000,
        'detected_at' => now(),
    ]);

    EarningsAlert::create([
        'earnings_event_id' => $event->id,
        'scan_run_id' => $run->id,
        'symbol' => 'NVDA',
        'alert_type' => 'eps_surprise',
        'direction' => EarningsAlert::DIRECTION_POSITIVE,
        'score' => 95,
        'status' => 'new',
    ]);

    return $run;
}

function seedCompletedSetupRunWithAlert(): StockBuySetupRun
{
    $run = StockBuySetupRun::create([
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
        'status' => 'completed',
        'symbols_considered' => 1,
        'scored_count' => 1,
        'matched_count' => 1,
    ]);

    StockBuySetupAlert::create([
        'source' => 'fmp',
        'scan_run_id' => $run->id,
        'first_scan_run_id' => $run->id,
        'symbol' => 'NBIS',
        'company_name' => 'Nebius Group',
        'exchange' => 'NASDAQ',
        'setup_type' => 'heartbeat_consolidation_spike',
        'setup_score' => 75,
        'previous_setup_score' => 50,
        'score_delta' => 25,
        'raw_setup_score' => 75,
        'heartbeat_score' => 75,
        'spike_date' => '2026-11-04',
        'status' => 'new',
        'detected_at' => now(),
    ]);

    return $run;
}

it('delivers each notification category independently based on user subscription preferences', function () {
    Notification::fake();

    $epsOnly = User::factory()->create([
        'email_eps_surprise_digest' => true,
        'email_setup_scoring_digest' => false,
    ]);
    $setupOnly = User::factory()->create([
        'email_eps_surprise_digest' => false,
        'email_setup_scoring_digest' => true,
    ]);
    $both = User::factory()->create([
        'email_eps_surprise_digest' => true,
        'email_setup_scoring_digest' => true,
    ]);
    $neither = User::factory()->create([
        'email_eps_surprise_digest' => false,
        'email_setup_scoring_digest' => false,
    ]);

    $epsRun = seedCompletedEpsRunWithAlert();
    $setupRun = seedCompletedSetupRunWithAlert();

    PrepareScannerRunDigests::dispatchSync(NotificationDigestDelivery::CATEGORY_EPS_SURPRISE, $epsRun->id);
    PrepareScannerRunDigests::dispatchSync(NotificationDigestDelivery::CATEGORY_SETUP_SCORING, $setupRun->id);

    Notification::assertSentToTimes($epsOnly, EpsSurpriseDigestNotification::class, 1);
    Notification::assertNotSentTo($epsOnly, SetupScoringDigestNotification::class);

    Notification::assertNotSentTo($setupOnly, EpsSurpriseDigestNotification::class);
    Notification::assertSentToTimes($setupOnly, SetupScoringDigestNotification::class, 1);

    Notification::assertSentToTimes($both, EpsSurpriseDigestNotification::class, 1);
    Notification::assertSentToTimes($both, SetupScoringDigestNotification::class, 1);

    Notification::assertNothingSentTo($neither);
});

it('allows users to independently enable and disable EPS and Setup digest preferences in Profile Settings', function () {
    $user = User::factory()->create([
        'email_eps_surprise_digest' => true,
        'email_setup_scoring_digest' => false,
    ]);

    $this->actingAs($user);

    Volt::test('settings.profile')
        ->set('email_eps_surprise_digest', false)
        ->set('email_setup_scoring_digest', true)
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->email_eps_surprise_digest)->toBeFalse()
        ->and($user->notify_eps_earnings)->toBeFalse()
        ->and($user->email_setup_scoring_digest)->toBeTrue()
        ->and($user->notify_stock_buy_setup)->toBeTrue();
});

it('suppresses a queued digest if the user unsubscribes before the delivery job executes', function () {
    Notification::fake();
    Queue::fake([SendUserNotificationDigest::class]);

    $user = User::factory()->create([
        'email_eps_surprise_digest' => true,
        'email_setup_scoring_digest' => true,
    ]);

    $epsRun = seedCompletedEpsRunWithAlert();

    // Step 1 & 2: Scanner finishes and EPS digest is queued in the outbox
    PrepareScannerRunDigests::dispatchSync(NotificationDigestDelivery::CATEGORY_EPS_SURPRISE, $epsRun->id);

    $delivery = NotificationDigestDelivery::where('user_id', $user->id)
        ->where('category', NotificationDigestDelivery::CATEGORY_EPS_SURPRISE)
        ->where('scan_run_id', $epsRun->id)
        ->first();

    expect($delivery)->not->toBeNull()
        ->and($delivery->status)->toBe(NotificationDigestDelivery::STATUS_QUEUED);

    // Step 3: User disables EPS notifications while the job is still in the queue
    $user->update(['email_eps_surprise_digest' => false]);

    // Step 4: Queued digest reaches its delivery stage
    app()->call([new SendUserNotificationDigest($delivery->id), 'handle']);

    // Expected result: Email is NOT sent, and outbox row records skipped_unsubscribed
    Notification::assertNothingSentTo($user);
    $delivery->refresh();
    expect($delivery->status)->toBe(NotificationDigestDelivery::STATUS_SKIPPED_UNSUBSCRIBED)
        ->and($delivery->sent_at)->toBeNull();
    // Setup subscription remains untouched
    expect($user->fresh()->email_setup_scoring_digest)->toBeTrue();
});

it('redirects unauthenticated users to login when visiting an unsubscribe link and preserves intended URL', function () {
    $url = route('notifications.unsubscribe.confirm', ['category' => 'eps_surprise']);

    $response = $this->get($url);

    $response->assertRedirect(route('login'));
    expect(session('url.intended'))->toBe($url);
});

it('never changes subscription settings on GET requests and requires an authenticated POST request to unsubscribe', function () {
    $user = User::factory()->create([
        'email_eps_surprise_digest' => true,
        'email_setup_scoring_digest' => true,
    ]);

    $this->actingAs($user);

    // Merely visiting the unsubscribe confirmation URL (GET) must NEVER mutate preferences
    $getResponse = $this->get(route('notifications.unsubscribe.confirm', ['category' => 'eps_surprise']));
    $getResponse->assertOk()
        ->assertSee('Disable EPS Surprise Notifications?');

    $user->refresh();
    expect($user->email_eps_surprise_digest)->toBeTrue()
        ->and($user->email_setup_scoring_digest)->toBeTrue();

    // Confirming via POST disables ONLY EPS Surprise Notifications
    $postResponse = $this->post(route('notifications.unsubscribe.perform', ['category' => 'eps_surprise']));
    $postResponse->assertRedirect(route('notifications.unsubscribe.confirm', ['category' => 'eps_surprise']));

    $user->refresh();
    expect($user->email_eps_surprise_digest)->toBeFalse()
        ->and($user->email_setup_scoring_digest)->toBeTrue()
        ->and(NotificationOptOut::isOptedOut($user->email, NotificationOptOut::TYPE_EARNINGS))->toBeTrue()
        ->and(NotificationOptOut::isOptedOut($user->email, NotificationOptOut::TYPE_BUY_SETUP))->toBeFalse();
});

it('allows unsubscribing from both digest categories via confirmation POST', function () {
    $user = User::factory()->create([
        'email_eps_surprise_digest' => true,
        'email_setup_scoring_digest' => true,
    ]);

    $this->actingAs($user);

    $this->post(route('notifications.unsubscribe.perform', ['category' => 'both']))
        ->assertRedirect(route('notifications.unsubscribe.confirm', ['category' => 'both']));

    $user->refresh();
    expect($user->email_eps_surprise_digest)->toBeFalse()
        ->and($user->email_setup_scoring_digest)->toBeFalse();
});

it('prevents a user from modifying another users notification preferences via URL or body parameters', function () {
    $attacker = User::factory()->create([
        'email' => 'attacker@example.com',
        'email_eps_surprise_digest' => true,
    ]);
    $victim = User::factory()->create([
        'email' => 'victim@example.com',
        'email_eps_surprise_digest' => true,
    ]);

    $this->actingAs($attacker);

    // Supplying another user's ID or email in query or POST body is rejected with 403 Forbidden
    $this->get(route('notifications.unsubscribe.confirm', [
        'category' => 'eps_surprise',
        'user_id' => $victim->id,
    ]))->assertForbidden();

    $this->post(route('notifications.unsubscribe.perform', ['category' => 'eps_surprise']), [
        'email' => $victim->email,
    ])->assertForbidden();

    expect($victim->fresh()->email_eps_surprise_digest)->toBeTrue();
});
