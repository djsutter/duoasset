<?php

namespace Database\Factories;

use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubscriptionPayment>
 */
class SubscriptionPaymentFactory extends Factory
{
    protected $model = SubscriptionPayment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan' => 'equities',
            'status' => SubscriptionPayment::STATUS_PENDING,
            'amount' => 29.00,
            'credit_applied' => 0.00,
            'amount_due' => 29.00,
            'currency' => 'CAD',
            'provider' => SubscriptionPayment::PROVIDER_SWIPE,
            'provider_session_id' => 'swipe_sess_' . Str::random(20),
            'provider_payment_id' => null,
            'checkout_url' => 'https://checkout.swipe.example.com/pay/' . Str::random(16),
            'metadata' => [],
            'fulfilled_at' => null,
        ];
    }

    /**
     * Indicate that the payment is completed.
     */
    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionPayment::STATUS_COMPLETED,
            'provider_payment_id' => 'swipe_pi_' . Str::random(20),
            'fulfilled_at' => now(),
        ]);
    }

    /**
     * Indicate that the payment was settled fully via account credits.
     */
    public function internalCredit(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionPayment::STATUS_COMPLETED,
            'credit_applied' => 29.00,
            'amount_due' => 0.00,
            'provider' => SubscriptionPayment::PROVIDER_INTERNAL_CREDIT,
            'provider_payment_id' => 'credit_' . Str::random(16),
            'fulfilled_at' => now(),
        ]);
    }
}
