<?php

use App\Enums\UserRole;
use App\Livewire\Subscription\Index as SubscriptionIndex;
use App\Models\Currency;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Models\UserCredit;
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
});

/*
|--------------------------------------------------------------------------
| Checkout Initiation & Prevention of Instant Self-Upgrades
|--------------------------------------------------------------------------
*/

test('guest clicking paid upgrade does not grant instant role upgrade and creates pending payment', function () {
    $guest = User::factory()->guest()->create([
        'credit_balance' => 0.00,
    ]);

    Livewire::actingAs($guest)
        ->test(SubscriptionIndex::class)
        ->call('upgradeToPlan', 'equities')
        ->assertRedirect();

    // Verification: user MUST remain Guest (no instant upgrade)
    expect($guest->fresh()->role)->toBe(UserRole::GUEST);

    // Auditable payment record created
    $payment = SubscriptionPayment::where('user_id', $guest->id)->first();
    expect($payment)->not->toBeNull();
    expect($payment->plan)->toBe('equities');
    expect($payment->status)->toBe(SubscriptionPayment::STATUS_PENDING);
    expect((float) $payment->amount)->toBe(29.00);
    expect((float) $payment->credit_applied)->toBe(0.00);
    expect((float) $payment->amount_due)->toBe(29.00);
    expect($payment->provider)->toBe('swipe');
    expect($payment->provider_session_id)->toStartWith('swipe_sess_');
    expect($payment->checkout_url)->toContain($payment->provider_session_id);
    expect($payment->fulfilled_at)->toBeNull();
});

test('paid checkout with partial credits applies credit offset and charges remaining balance', function () {
    $guest = User::factory()->guest()->create([
        'credit_balance' => 10.00,
    ]);

    Livewire::actingAs($guest)
        ->test(SubscriptionIndex::class)
        ->call('upgradeToPlan', 'equities')
        ->assertRedirect();

    // User is NOT upgraded yet
    expect($guest->fresh()->role)->toBe(UserRole::GUEST);
    // Credit balance is held until fulfillment
    expect((float) $guest->fresh()->credit_balance)->toBe(10.00);

    $payment = SubscriptionPayment::where('user_id', $guest->id)->first();
    expect($payment)->not->toBeNull();
    expect((float) $payment->amount)->toBe(29.00);
    expect((float) $payment->credit_applied)->toBe(10.00);
    expect((float) $payment->amount_due)->toBe(19.00);
    expect($payment->status)->toBe(SubscriptionPayment::STATUS_PENDING);
});

/*
|--------------------------------------------------------------------------
| Credit-Only Upgrade Path
|--------------------------------------------------------------------------
*/

test('user whose credits fully cover the plan is upgraded internally without external checkout', function () {
    $guest = User::factory()->guest()->create([
        'credit_balance' => 50.00,
    ]);

    Livewire::actingAs($guest)
        ->test(SubscriptionIndex::class)
        ->call('upgradeToPlan', 'equities')
        ->assertDispatched('view-mode-updated')
        ->assertSee('Congratulations! Your account has been upgraded');

    // Role is activated immediately
    expect($guest->fresh()->role)->toBe(UserRole::EQUITIES);
    // Credit deducted: $50 - $29 = $21
    expect((float) $guest->fresh()->credit_balance)->toBe(21.00);

    // Ledger entry recorded
    $creditEntry = UserCredit::where('user_id', $guest->id)->first();
    expect($creditEntry)->not->toBeNull();
    expect((float) $creditEntry->amount)->toBe(-29.00);
    expect($creditEntry->type)->toBe('subscription_payment');

    // Auditable payment record persisted as completed
    $payment = SubscriptionPayment::where('user_id', $guest->id)->first();
    expect($payment)->not->toBeNull();
    expect($payment->status)->toBe(SubscriptionPayment::STATUS_COMPLETED);
    expect((float) $payment->amount)->toBe(29.00);
    expect((float) $payment->credit_applied)->toBe(29.00);
    expect((float) $payment->amount_due)->toBe(0.00);
    expect($payment->provider)->toBe(SubscriptionPayment::PROVIDER_INTERNAL_CREDIT);
    expect($payment->fulfilled_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Webhook Fulfillment & Role Activation
|--------------------------------------------------------------------------
*/

test('successful webhook fulfillment activates the user role and settles credits', function () {
    $guest = User::factory()->guest()->create([
        'credit_balance' => 10.00,
    ]);

    $payment = SubscriptionPayment::factory()->create([
        'user_id' => $guest->id,
        'plan' => 'equities',
        'status' => SubscriptionPayment::STATUS_PENDING,
        'amount' => 29.00,
        'credit_applied' => 10.00,
        'amount_due' => 19.00,
        'provider' => 'swipe',
        'provider_session_id' => 'swipe_sess_fulfill_test_123',
    ]);

    $payload = [
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'id' => 'swipe_sess_fulfill_test_123',
                'payment_intent' => 'swipe_pi_fulfill_test_999',
                'metadata' => [
                    'user_id' => $guest->id,
                    'plan' => 'equities',
                    'payment_id' => $payment->id,
                ],
            ],
        ],
    ];

    $payloadJson = json_encode($payload);
    $secret = config('services.payment.swipe.webhook_secret', 'whsec_swipe_test_secret');
    $signature = hash_hmac('sha256', $payloadJson, $secret);

    $response = $this->postJson(route('webhooks.payment'), $payload, [
        'Swipe-Signature' => $signature,
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => 'success',
            'payment_id' => $payment->id,
            'plan' => 'equities',
            'role' => 'equities',
        ]);

    // Role is now activated!
    expect($guest->fresh()->role)->toBe(UserRole::EQUITIES);
    expect($guest->fresh()->view_mode)->toBe('stocks');

    // Partial credit deduction applied to ledger
    expect((float) $guest->fresh()->credit_balance)->toBe(0.00);
    expect(UserCredit::where('user_id', $guest->id)->count())->toBe(1);
    expect((float) UserCredit::where('user_id', $guest->id)->first()->amount)->toBe(-10.00);

    // Payment marked completed with fulfillment timestamp
    $payment->refresh();
    expect($payment->status)->toBe(SubscriptionPayment::STATUS_COMPLETED);
    expect($payment->provider_payment_id)->toBe('swipe_pi_fulfill_test_999');
    expect($payment->fulfilled_at)->not->toBeNull();
});

test('duplicate webhook fulfillment is idempotent and does not double-debit credits or error', function () {
    $guest = User::factory()->guest()->create([
        'credit_balance' => 15.00,
    ]);

    $payment = SubscriptionPayment::factory()->create([
        'user_id' => $guest->id,
        'plan' => 'full_subscription',
        'status' => SubscriptionPayment::STATUS_PENDING,
        'amount' => 49.00,
        'credit_applied' => 15.00,
        'amount_due' => 34.00,
        'provider_session_id' => 'swipe_sess_idempotent_test_456',
    ]);

    $payload = [
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'id' => 'swipe_sess_idempotent_test_456',
                'payment_intent' => 'swipe_pi_idempotent_test_888',
                'metadata' => [
                    'user_id' => $guest->id,
                    'plan' => 'full_subscription',
                ],
            ],
        ],
    ];

    $payloadJson = json_encode($payload);
    $secret = config('services.payment.swipe.webhook_secret', 'whsec_swipe_test_secret');
    $timestamp = time();
    $hash = hash_hmac('sha256', "{$timestamp}.{$payloadJson}", $secret);
    $signatureHeader = "t={$timestamp},v1={$hash}";

    // First webhook delivery
    $firstResponse = $this->postJson(route('webhooks.payment'), $payload, [
        'Swipe-Signature' => $signatureHeader,
    ]);
    $firstResponse->assertOk()->assertJson(['status' => 'success']);

    expect($guest->fresh()->role)->toBe(UserRole::FULL_SUBSCRIPTION);
    expect((float) $guest->fresh()->credit_balance)->toBe(0.00);
    expect(UserCredit::where('user_id', $guest->id)->count())->toBe(1);

    // Second webhook delivery with the exact same payload
    $secondResponse = $this->postJson(route('webhooks.payment'), $payload, [
        'Swipe-Signature' => $signatureHeader,
    ]);
    $secondResponse->assertOk()->assertJson(['status' => 'success']);

    // Assert that credits were NOT debited again and role remains unchanged
    expect($guest->fresh()->role)->toBe(UserRole::FULL_SUBSCRIPTION);
    expect((float) $guest->fresh()->credit_balance)->toBe(0.00);
    expect(UserCredit::where('user_id', $guest->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Validation & Security Edge Cases
|--------------------------------------------------------------------------
*/

test('webhook with invalid signature is rejected with 400', function () {
    $payload = [
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => 'swipe_sess_invalid_sig']],
    ];

    $response = $this->postJson(route('webhooks.payment'), $payload, [
        'Swipe-Signature' => 'invalid_forged_signature',
    ]);

    $response->assertStatus(400)
        ->assertJson(['error' => 'Invalid webhook signature.']);
});

test('webhook with non-existent session id returns 404', function () {
    $payload = [
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => 'swipe_sess_does_not_exist']],
    ];

    $payloadJson = json_encode($payload);
    $secret = config('services.payment.swipe.webhook_secret', 'whsec_swipe_test_secret');
    $signature = hash_hmac('sha256', $payloadJson, $secret);

    $response = $this->postJson(route('webhooks.payment'), $payload, [
        'Swipe-Signature' => $signature,
    ]);

    $response->assertStatus(404)
        ->assertJson(['error' => 'Payment record not found.']);
});

test('webhook ignores unhandled event types gracefully with 200 status', function () {
    $payload = [
        'type' => 'customer.subscription.deleted',
        'data' => ['object' => ['id' => 'sub_123']],
    ];

    $payloadJson = json_encode($payload);
    $secret = config('services.payment.swipe.webhook_secret', 'whsec_swipe_test_secret');
    $signature = hash_hmac('sha256', $payloadJson, $secret);

    $response = $this->postJson(route('webhooks.payment'), $payload, [
        'Swipe-Signature' => $signature,
    ]);

    $response->assertOk()
        ->assertJson(['status' => 'ignored']);
});

test('user already active on selected plan is not charged or redirected', function () {
    $user = User::factory()->equities()->create([
        'credit_balance' => 50.00,
    ]);

    Livewire::actingAs($user)
        ->test(SubscriptionIndex::class)
        ->call('upgradeToPlan', 'equities')
        ->assertSee('You are already active on the Equities Subscriber plan.');

    expect(SubscriptionPayment::where('user_id', $user->id)->count())->toBe(0);
    expect((float) $user->fresh()->credit_balance)->toBe(50.00);
});

test('invalid plan tier is rejected with 400', function () {
    $guest = User::factory()->guest()->create();

    Livewire::actingAs($guest)
        ->test(SubscriptionIndex::class)
        ->call('upgradeToPlan', 'invalid_plan_tier')
        ->assertStatus(400);
});

/*
|--------------------------------------------------------------------------
| UI Feedback & Pending Payment States
|--------------------------------------------------------------------------
*/

test('subscription page displays pending payment banner and resume link when user has pending payment', function () {
    $guest = User::factory()->guest()->create();

    $payment = SubscriptionPayment::factory()->create([
        'user_id' => $guest->id,
        'plan' => 'crypto',
        'status' => SubscriptionPayment::STATUS_PENDING,
        'amount' => 29.00,
        'amount_due' => 29.00,
        'checkout_url' => 'https://checkout.swipe.example.com/pay/pending123',
    ]);

    $this->actingAs($guest)
        ->get(route('subscription'))
        ->assertOk()
        ->assertSeeHtml('data-testid="subscription-pending-payment-alert"')
        ->assertSee('Card checkout of $29.00 for Crypto is awaiting confirmation')
        ->assertSee('Resume Checkout');
});
