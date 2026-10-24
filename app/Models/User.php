<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'guest',
        'view_mode' => 'stocks',
        'credit_balance' => 0.00,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'notify_eps_earnings',
        'notify_eps_revisions',
        'notify_stock_buy_setup',
        'role',
        'view_mode',
        'credit_balance',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];


    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'notify_eps_earnings' => 'boolean',
            'notify_eps_revisions' => 'boolean',
            'notify_stock_buy_setup' => 'boolean',
            'role' => UserRole::class,
            'credit_balance' => 'decimal:2',
        ];
    }

    /**
     * Deliveries of earnings/revision notifications to this user. Used
     * by SendEarningsAlert / SendEpsRevisionAlert to dedupe per-user.
     */
    public function earningsDeliveries(): HasMany
    {
        return $this->hasMany(EarningsNotificationDelivery::class);
    }

    /**
     * Dollar credits and balance adjustments for this user.
     */
    public function credits(): HasMany
    {
        return $this->hasMany(UserCredit::class)->orderBy('id', 'desc');
    }

    /**
     * Formatted string representation of the user's dollar credit balance.
     */
    public function formattedCreditBalance(): string
    {
        return '$' . number_format((float) ($this->credit_balance ?? 0), 2);
    }

    /**
     * Apply a dollar credit (or debit) adjustment to the user's account.
     */
    public function applyCredit(
        float|int|string $amount,
        ?string $description = null,
        ?User $admin = null,
        string $type = 'credit'
    ): UserCredit {
        $numericAmount = round((float) $amount, 2);

        return DB::transaction(function () use ($numericAmount, $description, $admin, $type) {
            $currentBalance = (float) ($this->credit_balance ?? 0);
            $newBalance = round($currentBalance + $numericAmount, 2);

            $this->credit_balance = $newBalance;
            $this->save();

            return $this->credits()->create([
                'admin_id' => $admin?->id,
                'amount' => $numericAmount,
                'balance_after' => $newBalance,
                'type' => $type,
                'description' => $description,
            ]);
        });
    }

    /**
     * Assign a new role to the user and align view_mode if single-domain.
     */
    public function assignRole(UserRole|string $role): static
    {
        $targetRole = is_string($role) ? UserRole::from($role) : $role;
        $this->role = $targetRole;

        if ($targetRole === UserRole::CRYPTO) {
            $this->view_mode = 'crypto';
        } elseif ($targetRole === UserRole::EQUITIES || $targetRole === UserRole::GUEST) {
            $this->view_mode = 'stocks';
        }

        $this->save();

        return $this;
    }

    /**
     * Remove role from the user by resetting to guest.
     */
    public function removeRole(): static
    {
        return $this->assignRole(UserRole::GUEST);
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }

    /**
     * Check if the user has access to stock market features.
     */
    public function hasStockAccess(): bool
    {
        return $this->role?->hasStockAccess() ?? false;
    }

    /**
     * Check if the user has access to crypto features.
     */
    public function hasCryptoAccess(): bool
    {
        return $this->role?->hasCryptoAccess() ?? false;
    }

    /**
     * Check if the user can toggle between stocks and crypto view perspectives.
     */
    public function canToggleViewMode(): bool
    {
        return $this->role?->canToggleViewMode() ?? false;
    }

    /**
     * Check if the user has a subscription higher than guest.
     */
    public function isHigherThanGuest(): bool
    {
        return $this->role?->isHigherThanGuest() ?? false;
    }

    /**
     * Check if the user is a guest.
     */
    public function isGuest(): bool
    {
        return $this->role === UserRole::GUEST;
    }

    /**
     * Check if the user is an administrator.
     */
    public function isAdministrator(): bool
    {
        return $this->role === UserRole::ADMINISTRATOR;
    }

    /**
     * Check if the user is an administrator (alias).
     */
    public function isAdmin(): bool
    {
        return $this->isAdministrator();
    }

    /**
     * Get the effective/active view mode for the user.
     */
    public function activeViewMode(): string
    {
        if ($this->role === UserRole::CRYPTO) {
            return 'crypto';
        }

        if ($this->role === UserRole::GUEST || $this->role === UserRole::EQUITIES) {
            return 'stocks';
        }

        return $this->view_mode ?: 'stocks';
    }

    /**
     * Set and persist the view mode ('stocks' or 'crypto').
     */
    public function setViewMode(string $mode): static
    {
        $normalized = strtolower(trim($mode));

        if (! in_array($normalized, ['stocks', 'crypto'], true)) {
            throw new InvalidArgumentException("Invalid view mode [{$mode}]. Must be 'stocks' or 'crypto'.");
        }

        $this->view_mode = $normalized;

        if ($this->exists) {
            $this->save();
        }

        return $this;
    }
}
