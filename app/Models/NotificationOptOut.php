<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class NotificationOptOut extends Model
{
    public const TYPE_EARNINGS = 'earnings';

    public const TYPE_REVISION = 'revision';

    public const TYPE_BUY_SETUP = 'buy_setup';

    public const TYPE_WATCHLIST = 'watchlist';

    public const VALID_TYPES = [
        self::TYPE_EARNINGS,
        self::TYPE_REVISION,
        self::TYPE_BUY_SETUP,
        self::TYPE_WATCHLIST,
    ];

    protected $fillable = [
        'user_id',
        'email',
        'item_type',
        'token',
        'opted_out_at',
    ];

    protected $casts = [
        'opted_out_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function typeLabel(): string
    {
        return match ($this->item_type) {
            self::TYPE_EARNINGS => 'EPS Earnings Surprise Alerts',
            self::TYPE_REVISION => 'EPS Target Revision Alerts',
            self::TYPE_BUY_SETUP => 'Stock Buy Setup Alerts',
            self::TYPE_WATCHLIST => 'Watchlist Alerts',
            default => ucfirst(str_replace('_', ' ', $this->item_type)),
        };
    }

    public static function getOrCreate(string $email, string $itemType, ?int $userId = null): self
    {
        $normalizedEmail = strtolower(trim($email));

        if (! $userId) {
            $userId = User::where('email', $normalizedEmail)->value('id');
        }

        return static::firstOrCreate(
            [
                'email' => $normalizedEmail,
                'item_type' => $itemType,
            ],
            [
                'user_id' => $userId,
                'token' => Str::random(40),
            ]
        );
    }

    public static function urlFor(string $email, string $itemType, ?int $userId = null): string
    {
        $optOut = static::getOrCreate($email, $itemType, $userId);

        return route('notifications.opt-out', [
            'email' => $optOut->email,
            'type' => $optOut->item_type,
            'key' => $optOut->token,
        ]);
    }

    public static function isOptedOut(string $email, string $itemType): bool
    {
        $normalizedEmail = strtolower(trim($email));

        $record = static::where('email', $normalizedEmail)
            ->where('item_type', $itemType)
            ->first();

        if ($record && $record->opted_out_at !== null) {
            return true;
        }

        $user = User::where('email', $normalizedEmail)->first();
        if ($user) {
            if ($itemType === self::TYPE_EARNINGS && ! $user->notify_eps_earnings) {
                return true;
            }
            if ($itemType === self::TYPE_REVISION && ! $user->notify_eps_revisions) {
                return true;
            }
            if ($itemType === self::TYPE_BUY_SETUP && ! $user->notify_stock_buy_setup) {
                return true;
            }
        }

        return false;
    }

    public function optOut(): void
    {
        $this->update(['opted_out_at' => now()]);

        $user = $this->user ?? User::where('email', $this->email)->first();
        if ($user) {
            if ($this->item_type === self::TYPE_EARNINGS) {
                $user->notify_eps_earnings = false;
            } elseif ($this->item_type === self::TYPE_REVISION) {
                $user->notify_eps_revisions = false;
            } elseif ($this->item_type === self::TYPE_BUY_SETUP) {
                $user->notify_stock_buy_setup = false;
            }
            $user->save();
        }
    }

    public function optIn(): void
    {
        $this->update(['opted_out_at' => null]);

        $user = $this->user ?? User::where('email', $this->email)->first();
        if ($user) {
            if ($this->item_type === self::TYPE_EARNINGS) {
                $user->notify_eps_earnings = true;
            } elseif ($this->item_type === self::TYPE_REVISION) {
                $user->notify_eps_revisions = true;
            } elseif ($this->item_type === self::TYPE_BUY_SETUP) {
                $user->notify_stock_buy_setup = true;
            }
            $user->save();
        }
    }

    public function rotateToken(): string
    {
        $newToken = Str::random(40);
        $this->update(['token' => $newToken]);

        return $newToken;
    }
}
