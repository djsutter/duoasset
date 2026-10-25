<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $plan
 * @property string $status
 * @property float|string $amount
 * @property float|string $credit_applied
 * @property float|string $amount_due
 * @property string $currency
 * @property string $provider
 * @property string|null $provider_session_id
 * @property string|null $provider_payment_id
 * @property string|null $checkout_url
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $fulfilled_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 */
class SubscriptionPayment extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const PROVIDER_SWIPE = 'swipe';
    public const PROVIDER_INTERNAL_CREDIT = 'internal_credit';

    public const PLAN_PRICES = [
        'equities' => 29.00,
        'crypto' => 29.00,
        'full_subscription' => 49.00,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'plan',
        'status',
        'amount',
        'credit_applied',
        'amount_due',
        'currency',
        'provider',
        'provider_session_id',
        'provider_payment_id',
        'checkout_url',
        'metadata',
        'fulfilled_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'credit_applied' => 'decimal:2',
            'amount_due' => 'decimal:2',
            'metadata' => 'array',
            'fulfilled_at' => 'datetime',
        ];
    }

    /**
     * The user account associated with this payment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if payment is currently pending confirmation.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if payment is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if fulfillment has been executed.
     */
    public function isFulfilled(): bool
    {
        return $this->fulfilled_at !== null;
    }
}
