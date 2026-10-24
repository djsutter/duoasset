<?php

use App\Enums\UserRole;
use App\Livewire\Admin\Configuration;
use App\Models\Currency;
use App\Models\User;
use App\Notifications\AdminTestNotification;
use App\Services\Admin\LogReaderService;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
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
| Admin Test Email Authorization Gating
|--------------------------------------------------------------------------
*/

test('unauthenticated users cannot call sendTestEmail', function () {
    $component = new Configuration;
    expect(fn () => $component->sendTestEmail())
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class, 'Unauthorized. Administrator access required.');

    $this->get(route('admin.config'))->assertRedirect(route('login'));
});

test('non-administrator roles receive 403 forbidden when calling sendTestEmail', function () {
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

        $this->actingAs($user);
        $component = new Configuration;

        expect(fn () => $component->sendTestEmail())
            ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class, 'Unauthorized. Administrator access required.');

        $this->actingAs($user)
            ->get(route('admin.config'))
            ->assertStatus(403);
    }
});

/*
|--------------------------------------------------------------------------
| Admin Test Email Successful Dispatch & UI Feedback
|--------------------------------------------------------------------------
*/

test('administrator can trigger sendTestEmail and receive success confirmation with audit logging', function () {
    Notification::fake();
    Log::spy();

    $admin = User::factory()->administrator()->create([
        'name' => 'Super Admin',
        'email' => 'admin@testdelivery.ca',
    ]);

    $expectedSender = config('mail.from.address') ?: 'duoasset@12pro.ca';

    Livewire::actingAs($admin)
        ->test(Configuration::class)
        ->assertSet('testEmailStatus', null)
        ->assertSet('testEmailError', null)
        ->call('sendTestEmail')
        ->assertSet('testEmailError', null)
        ->assertSee('Test verification email successfully dispatched to admin@testdelivery.ca from '.$expectedSender)
        ->assertSeeHtml('data-testid="admin-test-email-status"');

    // Assert notification was dispatched to the admin
    Notification::assertSentTo($admin, AdminTestNotification::class, function ($notification) use ($admin, $expectedSender) {
        return $notification->via($admin) === ['mail']
            && $notification->sender === $expectedSender;
    });

    // Assert audit log was recorded
    Log::shouldHaveReceived('info')->with('admin.test_email_sent', Mockery::on(function ($context) use ($admin, $expectedSender) {
        return ($context['admin_id'] ?? null) === $admin->id
            && ($context['email'] ?? null) === $admin->email
            && ($context['sender'] ?? null) === $expectedSender
            && ! empty($context['timestamp']);
    }))->once();
});

/*
|--------------------------------------------------------------------------
| Admin Test Email Failure Handling
|--------------------------------------------------------------------------
*/

test('sendTestEmail catches exceptions gracefully, avoids 500 crash, and renders error alert with audit log', function () {
    Log::spy();

    $admin = User::factory()->administrator()->create([
        'name' => 'Super Admin',
        'email' => 'admin@testdelivery.ca',
    ]);

    $expectedSender = config('mail.from.address') ?: 'duoasset@12pro.ca';

    // Mock ChannelManager to simulate mail transport connection failure
    $channelManager = Mockery::mock(ChannelManager::class);
    $channelManager->shouldReceive('send')
        ->once()
        ->andThrow(new \RuntimeException('Connection refused to SMTP server: 12pro.ca:587'));
    app()->instance(ChannelManager::class, $channelManager);

    Livewire::actingAs($admin)
        ->test(Configuration::class)
        ->assertSet('testEmailStatus', null)
        ->assertSet('testEmailError', null)
        ->call('sendTestEmail')
        ->assertSet('testEmailStatus', null)
        ->assertSet('testEmailError', 'Failed to dispatch test email to admin@testdelivery.ca: Connection refused to SMTP server: 12pro.ca:587')
        ->assertSee('Failed to dispatch test email to admin@testdelivery.ca: Connection refused to SMTP server: 12pro.ca:587')
        ->assertSeeHtml('data-testid="admin-test-email-error"');

    // Assert error audit log was recorded
    Log::shouldHaveReceived('error')->with('admin.test_email_failed', Mockery::on(function ($context) use ($admin, $expectedSender) {
        return ($context['admin_id'] ?? null) === $admin->id
            && ($context['email'] ?? null) === $admin->email
            && ($context['sender'] ?? null) === $expectedSender
            && str_contains($context['error'] ?? '', 'Connection refused to SMTP server: 12pro.ca:587')
            && ($context['exception'] ?? null) === \RuntimeException::class;
    }))->once();
});

/*
|--------------------------------------------------------------------------
| Observability Categorization in LogReaderService
|--------------------------------------------------------------------------
*/

test('admin test email log messages are categorized under notifications in LogReaderService', function () {
    $logService = app(LogReaderService::class);

    $sentCategory = $logService->categorize(
        'admin.test_email_sent',
        'local',
        ['admin_id' => 1, 'email' => 'admin@12pro.ca', 'sender' => 'duoasset@12pro.ca']
    );

    $failedCategory = $logService->categorize(
        'admin.test_email_failed',
        'local',
        ['admin_id' => 1, 'email' => 'admin@12pro.ca', 'sender' => 'duoasset@12pro.ca', 'error' => 'SMTP timeout']
    );

    expect($sentCategory)->toBe(LogReaderService::TYPE_NOTIFICATIONS)
        ->and($failedCategory)->toBe(LogReaderService::TYPE_NOTIFICATIONS);
});

/*
|--------------------------------------------------------------------------
| AdminTestNotification Unit Structure
|--------------------------------------------------------------------------
*/

test('AdminTestNotification generates proper mail message structure and array representation', function () {
    $admin = User::factory()->make([
        'email' => 'diagnostic@12pro.ca',
    ]);

    $fixedTime = Carbon::parse('2026-10-24 15:30:00', 'UTC');
    $notification = new AdminTestNotification($fixedTime, 'duoasset@12pro.ca');

    expect($notification->via($admin))->toBe(['mail']);

    $mailMessage = $notification->toMail($admin);
    expect($mailMessage)->toBeInstanceOf(MailMessage::class)
        ->and($mailMessage->subject)->toBe('DuoAsset System - Test Email Verification')
        ->and($mailMessage->from)->toBe(['duoasset@12pro.ca', 'DuoAsset']);

    // Check lines in introLines
    $bodyText = implode("\n", array_merge($mailMessage->introLines, $mailMessage->outroLines));
    expect($bodyText)->toContain('diagnostic@12pro.ca')
        ->and($bodyText)->toContain('duoasset@12pro.ca')
        ->and($bodyText)->toContain('2026-10-24 15:30:00 (UTC)')
        ->and($mailMessage->actionText)->toBe('Open Admin Configuration')
        ->and($mailMessage->actionUrl)->toBe(route('admin.config'));

    $arrayData = $notification->toArray($admin);
    expect($arrayData)->toBe([
        'type' => 'admin_test_email',
        'recipient' => 'diagnostic@12pro.ca',
        'sender' => 'duoasset@12pro.ca',
        'sent_at' => $fixedTime->toIso8601String(),
    ]);
});

/*
|--------------------------------------------------------------------------
| UI Elements Rendering & Dismiss Actions
|--------------------------------------------------------------------------
*/

test('admin configuration view renders test email buttons in header and diagnostics overview', function () {
    $admin = User::factory()->administrator()->create();

    Livewire::actingAs($admin)
        ->test(Configuration::class)
        ->assertSeeHtml('data-testid="send-test-email-btn"')
        ->assertSeeHtml('data-testid="diagnostic-send-test-email-btn"')
        ->assertSee('Send Test Email');
});

test('administrator can dismiss status and error alerts', function () {
    $admin = User::factory()->administrator()->create();

    Livewire::actingAs($admin)
        ->test(Configuration::class)
        ->set('testEmailStatus', 'Test email sent successfully.')
        ->assertSee('Test email sent successfully.')
        ->assertSeeHtml('data-testid="admin-test-email-status"')
        ->set('testEmailStatus', null)
        ->assertDontSeeHtml('data-testid="admin-test-email-status"')
        ->set('testEmailError', 'Delivery failure test.')
        ->assertSee('Delivery failure test.')
        ->assertSeeHtml('data-testid="admin-test-email-error"')
        ->set('testEmailError', null)
        ->assertDontSeeHtml('data-testid="admin-test-email-error"');
});
