<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCredit extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'admin_id',
        'amount',
        'balance_after',
        'type',
        'description',
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
            'balance_after' => 'decimal:2',
        ];
    }

    /**
     * The user account that received the credit transaction.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The administrator that applied the credit (if applicable).
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * Format the amount with a sign (e.g. +$25.00 or -$10.00).
     */
    public function formattedAmount(): string
    {
        $num = (float) $this->amount;
        $formatted = '$'.number_format(abs($num), 2);

        return $num >= 0 ? '+'.$formatted : '-'.$formatted;
    }

    /**
     * Format the resulting balance after this transaction.
     */
    public function formattedBalanceAfter(): string
    {
        return '$'.number_format((float) $this->balance_after, 2);
    }
}
