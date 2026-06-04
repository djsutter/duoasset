<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Types\Money;
use App\Types\WalletSlug;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'currency',
        'platform_id',
        'opening_balance',
        'balance',
        'type',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'opening_balance' => MoneyCast::class.':currency',
        'balance' => MoneyCast::class.':currency',
    ];

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', 1);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(WalletAddress::class);
    }

    public function applyAmount(Money $amount): void
    {
        $this->balance = $this->balance->add($amount);
    }

    /**
     * Get the end-of-day balances for this wallet.
     */
    public function dayBalances(): HasMany
    {
        return $this->hasMany(DayBalance::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(WalletEntry::class);
    }

    public static function findByAddress(string $address): ?self
    {
        return static::whereHas('addresses', function ($query) use ($address) {
            $query->where('address', $address);
        })->first();
    }

    /**
     * Get the current balance for a wallet, or the day-balance for a specific day.
     */
    public function getBalance(?Carbon $date = null): Money
    {
        if (! $date) {
            return $this->balance;
        }

        if (
            $dayBalance = DayBalance::where('wallet_id', $this->id)
                ->where('date', '<=', $date)
                ->orderBy('date', 'desc')
                ->limit(1)
                ->first()
        ) {
            return $dayBalance->balance;
        }

        return $this->opening_balance;
    }

    /**
     * Get the balance for an account, before the given day.
     */
    public function getBalanceBefore(Carbon $date): Money
    {
        if (
            $dayBalance = DayBalance::where('wallet_id', $this->id)
                ->where('date', '<', $date)
                ->orderBy('date', 'desc')
                ->limit(1)
                ->first()
        ) {
            return $dayBalance->balance;
        }

        return $this->opening_balance;
    }

    public function isExternal(): bool
    {
        return $this->type === 'external';
    }

    public function isLiability(): bool
    {
        return str_starts_with($this->name, 'Liability-');
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function slug(): string
    {
        $slug = WalletSlug::fromParts($this->platform->name, $this->name, null);

        return $slug->toString();
    }
}
