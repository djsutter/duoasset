<?php

use App\Jobs\SendEarningsAlert;
use App\Jobs\SendEpsRevisionAlert;
use App\Models\EarningsAlert;
use App\Models\EarningsEvent;
use App\Models\EarningsNotificationDelivery;
use App\Models\EpsRevisionAlert;
use App\Models\User;
use App\Notifications\EarningsSurpriseDetected;
use App\Notifications\EpsTargetRevised;
use Illuminate\Support\Facades\Notification;

function makeEarningsAlert(): EarningsAlert
{
    $event = EarningsEvent::create([
        'symbol' => 'BOOM',
        'exchange' => 'NASDAQ',
        'company_name' => 'BoomCo',
        'report_date' => now()->toDateString(),
        'eps_estimated' => 1.0,
        'eps_actual' => 2.0,
        'eps_surprise' => 1.0,
        'eps_surprise_percent' => 100,
        'market_cap' => 5_000_000_000,
        'source' => 'fmp',
        'detected_at' => now(),
    ]);

    return EarningsAlert::create([
        'earnings_event_id' => $event->id,
        'symbol' => 'BOOM',
        'alert_type' => 'eps_surprise',
        'direction' => EarningsAlert::DIRECTION_POSITIVE,
        'score' => 50,
        'status' => 'new',
        'message' => '',
    ]);
}

function makeRevisionAlert(): EpsRevisionAlert
{
    return EpsRevisionAlert::create([
        'symbol' => 'BOOM',
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
}

it('only notifies users who opted in to earnings alerts', function () {
    Notification::fake();

    $in = User::factory()->create(['notify_eps_earnings' => true]);
    $out = User::factory()->create(['notify_eps_earnings' => false]);
    $alert = makeEarningsAlert();

    SendEarningsAlert::dispatchSync($alert->id);

    Notification::assertSentTo($in, EarningsSurpriseDetected::class);
    Notification::assertNotSentTo($out, EarningsSurpriseDetected::class);
});

it('does not send the same earnings alert twice to the same user', function () {
    Notification::fake();

    $user = User::factory()->create(['notify_eps_earnings' => true]);
    $alert = makeEarningsAlert();

    SendEarningsAlert::dispatchSync($alert->id);
    // Reset sent_at so the job's own short-circuit doesn't mask the
    // per-user dedupe we care about here.
    $alert->refresh();
    $alert->sent_at = null;
    $alert->save();
    SendEarningsAlert::dispatchSync($alert->id);

    Notification::assertSentToTimes($user, EarningsSurpriseDetected::class, 1);
    expect(EarningsNotificationDelivery::where([
        'user_id' => $user->id,
        'alert_type' => EarningsNotificationDelivery::TYPE_EARNINGS,
        'alert_id' => $alert->id,
    ])->count())->toBe(1);
});

it('only notifies users who opted in to revision alerts', function () {
    Notification::fake();

    $in = User::factory()->create(['notify_eps_revisions' => true]);
    $out = User::factory()->create(['notify_eps_revisions' => false]);
    $alert = makeRevisionAlert();

    SendEpsRevisionAlert::dispatchSync($alert->id);

    Notification::assertSentTo($in, EpsTargetRevised::class);
    Notification::assertNotSentTo($out, EpsTargetRevised::class);
});

it('does not resend the same revision alert to the same user', function () {
    Notification::fake();

    $user = User::factory()->create(['notify_eps_revisions' => true]);
    $alert = makeRevisionAlert();

    SendEpsRevisionAlert::dispatchSync($alert->id);
    $alert->refresh();
    $alert->sent_at = null;
    $alert->save();
    SendEpsRevisionAlert::dispatchSync($alert->id);

    Notification::assertSentToTimes($user, EpsTargetRevised::class, 1);
    expect(EarningsNotificationDelivery::where([
        'user_id' => $user->id,
        'alert_type' => EarningsNotificationDelivery::TYPE_REVISION,
        'alert_id' => $alert->id,
    ])->count())->toBe(1);
});
