<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Notifications\AccountActivationNotification;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    public const ANONYMOUS_EMAIL = 'anonymous@duoasset.local';

    public const ANONYMOUS_NAME = 'Anonymous User';

    public const VALID_VIEW_MODES = ['stocks', 'crypto', 'futures'];

    /**
     * @var array<string, bool>
     */
    protected static array $schemaColumnCache = [];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'guest',
        'view_mode' => 'stocks',
        'is_anonymous' => false,
        'credit_balance' => 0.00,
        'timezone' => 'America/New_York',
        'notify_eps_earnings' => true,
        'email_eps_surprise_digest' => true,
        'notify_eps_revisions' => true,
        'notify_stock_buy_setup' => false,
        'email_setup_scoring_digest' => false,
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
        'email_eps_surprise_digest',
        'notify_eps_revisions',
        'notify_stock_buy_setup',
        'email_setup_scoring_digest',
        'role',
        'assigned_roles',
        'view_mode',
        'is_anonymous',
        'recent_futures',
        'credit_balance',
        'timezone',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_code',
        'activation_token',
    ];

    /**
     * The user's regional IANA timezone (falls back to America/New_York when unset or invalid).
     */
    public function timezone(): string
    {
        $timezone = $this->attributes['timezone'] ?? null;

        if (is_string($timezone) && $timezone !== '' && in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            return $timezone;
        }

        return 'America/New_York';
    }

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
            'email_eps_surprise_digest' => 'boolean',
            'notify_eps_revisions' => 'boolean',
            'notify_stock_buy_setup' => 'boolean',
            'email_setup_scoring_digest' => 'boolean',
            'role' => UserRole::class,
            'assigned_roles' => 'array',
            'is_anonymous' => 'boolean',
            'recent_futures' => 'array',
            'credit_balance' => 'decimal:2',
            'two_factor_expires_at' => 'datetime',
        ];
    }

    public static function clearSchemaColumnCache(): void
    {
        self::$schemaColumnCache = [];
    }

    public static function hasSchemaColumn(string $column): bool
    {
        $conn = static::query()->getConnection()->getName();
        $key = $conn.':'.$column;

        if (array_key_exists($key, self::$schemaColumnCache)) {
            return self::$schemaColumnCache[$key];
        }

        try {
            return self::$schemaColumnCache[$key] = Schema::hasTable('users') && Schema::hasColumn('users', $column);
        } catch (Throwable) {
            return false;
        }
    }

    public static function ensureAnonymousSchema(): bool
    {
        try {
            if (! Schema::hasTable('users')) {
                return false;
            }

            $missingAnon = ! static::hasSchemaColumn('is_anonymous');
            $missingRoles = ! static::hasSchemaColumn('assigned_roles');
            $missingFutures = ! static::hasSchemaColumn('recent_futures');

            if (! $missingAnon && ! $missingRoles && ! $missingFutures) {
                return true;
            }

            Schema::table('users', function (Blueprint $table) use ($missingAnon, $missingRoles, $missingFutures) {
                if ($missingAnon && ! Schema::hasColumn('users', 'is_anonymous')) {
                    $table->boolean('is_anonymous')->default(false)->index()->after('view_mode');
                }
                if ($missingRoles && ! Schema::hasColumn('users', 'assigned_roles')) {
                    $table->json('assigned_roles')->nullable()->after('role');
                }
                if ($missingFutures && ! Schema::hasColumn('users', 'recent_futures')) {
                    $table->json('recent_futures')->nullable()->after('view_mode');
                }
            });

            static::clearSchemaColumnCache();

            return static::hasSchemaColumn('is_anonymous');
        } catch (Throwable) {
            static::clearSchemaColumnCache();

            return false;
        }
    }

    /**
     * Idempotently retrieve or create the system-managed Anonymous User account.
     *
     * Note: Does NOT grant Futures Subscriber by default. Role assignment is an
     * explicit administrator-controlled decision.
     */
    public static function ensureAnonymousUser(): self
    {
        static::ensureAnonymousSchema();

        $query = static::query();
        if (static::hasSchemaColumn('is_anonymous')) {
            $existing = (clone $query)->where('is_anonymous', true)->first();
            if ($existing) {
                return $existing;
            }
        }

        $existing = (clone $query)
            ->where('email', self::ANONYMOUS_EMAIL)
            ->orWhere('name', self::ANONYMOUS_NAME)
            ->first();

        if ($existing) {
            $needsSave = false;
            if (static::hasSchemaColumn('is_anonymous') && ! $existing->is_anonymous) {
                $existing->is_anonymous = true;
                $needsSave = true;
            }
            if ($existing->name !== self::ANONYMOUS_NAME) {
                $existing->name = self::ANONYMOUS_NAME;
                $needsSave = true;
            }
            if ($existing->email !== self::ANONYMOUS_EMAIL) {
                $existing->email = self::ANONYMOUS_EMAIL;
                $needsSave = true;
            }
            if ($needsSave) {
                $existing->save();
            }

            return $existing;
        }

        $user = new static;
        $fill = [
            'name' => self::ANONYMOUS_NAME,
            'email' => self::ANONYMOUS_EMAIL,
            'password' => Hash::make(Str::random(64)),
            'role' => UserRole::GUEST,
            'view_mode' => 'stocks',
            'credit_balance' => 0.00,
            'email_verified_at' => null,
            'activation_token' => null,
            'notify_eps_earnings' => false,
            'email_eps_surprise_digest' => false,
            'notify_eps_revisions' => false,
            'notify_stock_buy_setup' => false,
            'email_setup_scoring_digest' => false,
        ];

        if (static::hasSchemaColumn('is_anonymous')) {
            $fill['is_anonymous'] = true;
        }
        if (static::hasSchemaColumn('assigned_roles')) {
            $fill['assigned_roles'] = [];
        }
        if (static::hasSchemaColumn('recent_futures')) {
            $fill['recent_futures'] = [];
        }

        $user->forceFill($fill)->save();

        return $user;
    }

    public static function anonymousUser(): self
    {
        return static::ensureAnonymousUser();
    }

    public static function anonymous(): self
    {
        return static::ensureAnonymousUser();
    }

    public function isAnonymousUser(): bool
    {
        if (! empty($this->attributes['is_anonymous'])) {
            return true;
        }

        $email = strtolower(trim((string) ($this->attributes['email'] ?? '')));
        $name = trim((string) ($this->attributes['name'] ?? ''));

        return $email === self::ANONYMOUS_EMAIL || $name === self::ANONYMOUS_NAME;
    }

    public function isAnonymous(): bool
    {
        return $this->isAnonymousUser();
    }

    public function canLogin(): bool
    {
        return ! $this->isAnonymousUser();
    }

    public function canResetPassword(): bool
    {
        return ! $this->isAnonymousUser();
    }

    public function canBeDeleted(): bool
    {
        return ! $this->isAnonymousUser();
    }

    public function canBeImpersonated(): bool
    {
        return ! $this->isAnonymousUser();
    }

    protected static function booted(): void
    {
        static::saving(function (self $user) {
            if ($user->isAnonymousUser()) {
                $user->name = self::ANONYMOUS_NAME;
                $user->email = self::ANONYMOUS_EMAIL;
                $user->email_verified_at = null;
                $user->activation_token = null;
                $user->notify_eps_earnings = false;
                $user->email_eps_surprise_digest = false;
                $user->notify_eps_revisions = false;
                $user->notify_stock_buy_setup = false;
                $user->email_setup_scoring_digest = false;
                if (static::hasSchemaColumn('is_anonymous')) {
                    $user->is_anonymous = true;
                }
                if (static::hasSchemaColumn('recent_futures')) {
                    $user->recent_futures = [];
                }
            } else {
                if ($user->isDirty('email_eps_surprise_digest') && ! $user->isDirty('notify_eps_earnings')) {
                    $user->notify_eps_earnings = (bool) $user->email_eps_surprise_digest;
                } elseif ($user->isDirty('notify_eps_earnings') && ! $user->isDirty('email_eps_surprise_digest')) {
                    $user->email_eps_surprise_digest = (bool) $user->notify_eps_earnings;
                }

                if ($user->isDirty('email_setup_scoring_digest') && ! $user->isDirty('notify_stock_buy_setup')) {
                    $user->notify_stock_buy_setup = (bool) $user->email_setup_scoring_digest;
                } elseif ($user->isDirty('notify_stock_buy_setup') && ! $user->isDirty('email_setup_scoring_digest')) {
                    $user->email_setup_scoring_digest = (bool) $user->notify_stock_buy_setup;
                }
            }

            // Strip any schema columns that haven't been migrated yet to keep saves resilient
            foreach (['is_anonymous', 'assigned_roles', 'recent_futures'] as $optionalCol) {
                if (array_key_exists($optionalCol, $user->attributes) && ! static::hasSchemaColumn($optionalCol)) {
                    unset($user->attributes[$optionalCol]);
                }
            }
        });

        static::created(function (self $user) {
            if ($user->isAnonymousUser()) {
                return;
            }

            try {
                app(\App\Services\Watchlists\DefaultWatchlistService::class)->ensureDefaultWatchlist($user);
            } catch (Throwable $e) {
                Log::warning("Failed to provision default watchlist for user {$user->id}: {$e->getMessage()}", [
                    'user_id' => $user->id,
                    'exception' => $e,
                ]);
            }
        });

        static::deleting(function (self $user) {
            if ($user->isAnonymousUser()) {
                throw new LogicException('The system Anonymous User account cannot be deleted.');
            }
        });
    }

    /**
     * Ensure this user has an initialized default watchlist.
     */
    public function ensureDefaultWatchlist(): ?Watchlist
    {
        return app(\App\Services\Watchlists\DefaultWatchlistService::class)->ensureDefaultWatchlist($this);
    }

    /**
     * The user's active personal default watchlist.
     */
    public function defaultWatchlist(): ?Watchlist
    {
        return $this->watchlists()
            ->where('is_global', false)
            ->where('is_default', true)
            ->first();
    }

    /**
     * Determine whether this user is currently subscribed to the given digest category,
     * checking both user preference columns and explicit notification opt-outs.
     */
    public function isSubscribedToDigest(string $category): bool
    {
        if ($this->isAnonymousUser()) {
            return false;
        }

        if ($category === NotificationDigestDelivery::CATEGORY_EPS_SURPRISE) {
            if (! $this->email_eps_surprise_digest || ! $this->notify_eps_earnings) {
                return false;
            }

            return ! NotificationOptOut::isOptedOut((string) $this->email, NotificationOptOut::TYPE_EARNINGS);
        }

        if ($category === NotificationDigestDelivery::CATEGORY_SETUP_SCORING) {
            if (! $this->email_setup_scoring_digest || ! $this->notify_stock_buy_setup) {
                return false;
            }

            return ! NotificationOptOut::isOptedOut((string) $this->email, NotificationOptOut::TYPE_BUY_SETUP);
        }

        return false;
    }

    /**
     * Watchlists belonging to this user.
     */
    public function watchlists(): HasMany
    {
        return $this->hasMany(Watchlist::class);
    }

    /**
     * Digest deliveries for this user across scanner runs.
     */
    public function digestDeliveries(): HasMany
    {
        return $this->hasMany(NotificationDigestDelivery::class);
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
     * Subscription and checkout payments for this user.
     */
    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class)->orderBy('id', 'desc');
    }

    /**
     * Formatted string representation of the user's dollar credit balance.
     */
    public function formattedCreditBalance(): string
    {
        return '$'.number_format((float) ($this->credit_balance ?? 0), 2);
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
     * Return all roles assigned to this user as UserRole enum instances.
     *
     * For the Anonymous User, returns only explicitly assigned non-guest roles.
     *
     * @return list<UserRole>
     */
    public function assignedRoles(): array
    {
        $roles = [];

        $rawAssigned = $this->attributes['assigned_roles'] ?? null;
        if (is_string($rawAssigned) && $rawAssigned !== '') {
            $decoded = json_decode($rawAssigned, true);
            $rawAssigned = is_array($decoded) ? $decoded : [];
        }

        if (is_array($rawAssigned)) {
            foreach ($rawAssigned as $item) {
                try {
                    $resolved = UserRole::resolve($item);
                    if ($this->isAnonymousUser() && $resolved === UserRole::GUEST) {
                        continue;
                    }
                    $roles[$resolved->value] = $resolved;
                } catch (Throwable) {
                }
            }
        }

        $primaryRole = $this->role;
        if ($primaryRole instanceof UserRole) {
            if (! $this->isAnonymousUser() || $primaryRole !== UserRole::GUEST) {
                $roles[$primaryRole->value] = $primaryRole;
            }
        }

        if (empty($roles) && ! $this->isAnonymousUser()) {
            $roles[UserRole::GUEST->value] = UserRole::GUEST;
        }

        return array_values($roles);
    }

    /**
     * Check whether the user has a specific role assigned.
     */
    public function hasRole(UserRole|string $role): bool
    {
        try {
            $target = UserRole::resolve($role);
        } catch (Throwable) {
            return false;
        }

        if ($this->isAnonymousUser() && $target === UserRole::GUEST) {
            return empty($this->assignedRoles());
        }

        return in_array($target, $this->assignedRoles(), true);
    }

    /**
     * Assign a role to the user and align view_mode if single-domain.
     * For the Anonymous User, assigning a non-guest role adds it to assigned_roles.
     */
    public function assignRole(UserRole|string $role): static
    {
        static::ensureAnonymousSchema();

        $targetRole = UserRole::resolve($role);

        if ($this->isAnonymousUser()) {
            if ($targetRole === UserRole::GUEST) {
                $this->role = UserRole::GUEST;
                if (static::hasSchemaColumn('assigned_roles')) {
                    $this->assigned_roles = [];
                }
            } else {
                $current = array_map(fn (UserRole $r) => $r->value, $this->assignedRoles());
                if (! in_array($targetRole->value, $current, true)) {
                    $current[] = $targetRole->value;
                }
                $this->role = $targetRole;
                if (static::hasSchemaColumn('assigned_roles')) {
                    $this->assigned_roles = array_values(array_unique($current));
                }
            }

            if ($this->exists) {
                $this->save();
            }

            return $this;
        }

        $this->role = $targetRole;
        if (static::hasSchemaColumn('assigned_roles')) {
            $this->assigned_roles = $targetRole === UserRole::GUEST ? [] : [$targetRole->value];
        }

        if ($targetRole === UserRole::CRYPTO) {
            $this->view_mode = 'crypto';
        } elseif ($targetRole === UserRole::FUTURES) {
            $this->view_mode = 'futures';
        } elseif ($targetRole === UserRole::EQUITIES || $targetRole === UserRole::GUEST) {
            $this->view_mode = 'stocks';
        }

        if ($this->exists) {
            $this->save();
        }

        return $this;
    }

    /**
     * Add an additional role without removing existing assigned roles.
     */
    public function addRole(UserRole|string $role): static
    {
        static::ensureAnonymousSchema();

        $targetRole = UserRole::resolve($role);
        if ($targetRole === UserRole::GUEST) {
            return $this;
        }

        $current = array_values(array_filter(
            array_map(fn (UserRole $r) => $r->value, $this->assignedRoles()),
            fn (string $v) => $v !== UserRole::GUEST->value
        ));

        if (! in_array($targetRole->value, $current, true)) {
            $current[] = $targetRole->value;
        }

        $this->role = $targetRole;
        if (static::hasSchemaColumn('assigned_roles')) {
            $this->assigned_roles = array_values(array_unique($current));
        }

        if (! $this->isAnonymousUser()) {
            $permitted = $this->permittedViewModes();
            if (! in_array($this->view_mode, $permitted, true) && ! empty($permitted)) {
                $this->view_mode = $permitted[0];
            }
        }

        if ($this->exists) {
            $this->save();
        }

        return $this;
    }

    /**
     * Synchronize the user's roles to the exact provided list.
     *
     * @param  iterable<UserRole|string>  $roles
     */
    public function syncRoles(iterable $roles): static
    {
        static::ensureAnonymousSchema();

        $normalized = [];
        foreach ($roles as $r) {
            try {
                $resolved = UserRole::resolve($r);
                if ($resolved !== UserRole::GUEST) {
                    $normalized[$resolved->value] = $resolved;
                }
            } catch (Throwable) {
            }
        }

        if (empty($normalized)) {
            $this->role = UserRole::GUEST;
            if (static::hasSchemaColumn('assigned_roles')) {
                $this->assigned_roles = [];
            }
            if (! $this->isAnonymousUser()) {
                $this->view_mode = 'stocks';
            }
        } else {
            $list = array_values($normalized);
            $primary = end($list);
            $this->role = $primary;
            if (static::hasSchemaColumn('assigned_roles')) {
                $this->assigned_roles = array_map(fn (UserRole $r) => $r->value, $list);
            }
            if (! $this->isAnonymousUser()) {
                $permitted = $this->permittedViewModes();
                if (! in_array($this->view_mode, $permitted, true) && ! empty($permitted)) {
                    $this->view_mode = $permitted[0];
                }
            }
        }

        if ($this->exists) {
            $this->save();
        }

        return $this;
    }

    /**
     * Remove a specific role or all roles (resetting to guest when none remain).
     */
    public function removeRole(UserRole|string|null $role = null): static
    {
        static::ensureAnonymousSchema();

        if ($role === null) {
            $this->role = UserRole::GUEST;
            if (static::hasSchemaColumn('assigned_roles')) {
                $this->assigned_roles = [];
            }
            if (! $this->isAnonymousUser()) {
                $this->view_mode = 'stocks';
            }
            if ($this->exists) {
                $this->save();
            }

            return $this;
        }

        $targetRole = UserRole::resolve($role);
        $remaining = array_values(array_filter(
            $this->assignedRoles(),
            fn (UserRole $r) => $r !== $targetRole && $r !== UserRole::GUEST
        ));

        return $this->syncRoles($remaining);
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
        foreach ($this->assignedRoles() as $role) {
            if ($this->isAnonymousUser() && $role === UserRole::GUEST) {
                continue;
            }
            if ($role->hasStockAccess()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the user has access to crypto features.
     */
    public function hasCryptoAccess(): bool
    {
        foreach ($this->assignedRoles() as $role) {
            if ($role->hasCryptoAccess()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the user has access to futures market features.
     */
    public function hasFuturesAccess(): bool
    {
        foreach ($this->assignedRoles() as $role) {
            if ($role->hasFuturesAccess()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the ordered list of dashboard view modes permitted for this user.
     *
     * @return list<string>
     */
    public function permittedViewModes(): array
    {
        $modes = [];
        if ($this->hasStockAccess()) {
            $modes[] = 'stocks';
        }
        if ($this->hasCryptoAccess()) {
            $modes[] = 'crypto';
        }
        if ($this->hasFuturesAccess()) {
            $modes[] = 'futures';
        }

        return $modes;
    }

    /**
     * Check if the user can use the perspective/context switcher.
     */
    public function canToggleViewMode(): bool
    {
        $permitted = $this->permittedViewModes();
        if (count($permitted) > 1 || in_array('futures', $permitted, true)) {
            return true;
        }

        foreach ($this->assignedRoles() as $role) {
            if ($role->canToggleViewMode()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the user has a subscription higher than guest.
     */
    public function isHigherThanGuest(): bool
    {
        foreach ($this->assignedRoles() as $role) {
            if ($role->isHigherThanGuest()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the user is a guest.
     */
    public function isGuest(): bool
    {
        return ! $this->isHigherThanGuest();
    }

    /**
     * Check if the user is an administrator.
     * Note: The shared Anonymous User identity can never act as an interactive administrator.
     */
    public function isAdministrator(): bool
    {
        if ($this->isAnonymousUser()) {
            return false;
        }

        foreach ($this->assignedRoles() as $role) {
            if ($role === UserRole::ADMINISTRATOR) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the user is an administrator (alias).
     */
    public function isAdmin(): bool
    {
        return $this->isAdministrator();
    }

    /**
     * Get the effective/active view mode for the user, validated against current permissions.
     */
    public function activeViewMode(): string
    {
        $permitted = $this->permittedViewModes();

        if ($this->isAnonymousUser()) {
            $sessionMode = session('anonymous_view_mode');
            if (is_string($sessionMode) && in_array($sessionMode, $permitted, true)) {
                return $sessionMode;
            }

            return $permitted[0] ?? 'stocks';
        }

        $saved = strtolower(trim((string) ($this->view_mode ?: 'stocks')));
        if (in_array($saved, $permitted, true)) {
            return $saved;
        }

        return $permitted[0] ?? 'stocks';
    }

    /**
     * Set and persist the view mode ('stocks', 'crypto', or 'futures').
     */
    public function setViewMode(string $mode): static
    {
        $normalized = strtolower(trim($mode));

        if (! in_array($normalized, self::VALID_VIEW_MODES, true)) {
            throw new InvalidArgumentException("Invalid view mode [{$mode}]. Must be 'stocks', 'crypto', or 'futures'.");
        }

        if ($this->isAnonymousUser()) {
            session(['anonymous_view_mode' => $normalized]);

            return $this;
        }

        $this->view_mode = $normalized;

        if ($this->exists) {
            $this->save();
        }

        return $this;
    }

    /**
     * Generate a new 6-digit two-factor authentication code valid for 7 minutes.
     */
    public function generateTwoFactorCode(int $minutes = 7): string
    {
        $code = sprintf('%06d', random_int(100000, 999999));

        $this->forceFill([
            'two_factor_code' => Hash::make($code),
            'two_factor_expires_at' => now()->addMinutes($minutes),
        ])->save();

        return $code;
    }

    /**
     * Clear and reset any active two-factor authentication code.
     */
    public function resetTwoFactorCode(): void
    {
        $this->forceFill([
            'two_factor_code' => null,
            'two_factor_expires_at' => null,
        ])->save();
    }

    /**
     * Check if the two-factor authentication code has expired.
     */
    public function hasTwoFactorCodeExpired(): bool
    {
        if (! $this->two_factor_expires_at) {
            return true;
        }

        return now()->gt($this->two_factor_expires_at);
    }

    /**
     * Verify whether the given two-factor code matches and is unexpired.
     */
    public function verifyTwoFactorCode(string $code): bool
    {
        if ($this->hasTwoFactorCodeExpired() || ! $this->two_factor_code) {
            return false;
        }

        return Hash::check(trim($code), $this->two_factor_code);
    }

    /**
     * Generate an activation token, hash and persist it, and dispatch the activation notification.
     */
    public function sendAccountActivationNotification(): string
    {
        $plainToken = bin2hex(random_bytes(32));

        $this->forceFill([
            'activation_token' => hash('sha256', $plainToken),
        ])->save();

        $this->notify(new AccountActivationNotification($plainToken));

        return $plainToken;
    }

    /**
     * Find a user by plaintext activation token.
     */
    public static function findByActivationToken(string $token): ?self
    {
        $trimmed = trim($token);
        if ($trimmed === '') {
            return null;
        }

        return static::whereNotNull('activation_token')
            ->where('activation_token', hash('sha256', $trimmed))
            ->first();
    }

    /**
     * Activate the account: mark email as verified and clear the activation token.
     */
    public function activateAccount(): bool
    {
        $this->activation_token = null;

        return $this->markEmailAsVerified();
    }

    /**
     * Send the email verification notification.
     *
     * Overridden to suppress default Laravel verification email,
     * as we use a custom AccountActivationNotification.
     */
    public function sendEmailVerificationNotification()
    {
        // Suppress default Laravel verification email.
    }
}
