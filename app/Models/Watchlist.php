<?php

namespace App\Models;

use App\Services\Dashboard\DashboardCacheService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property bool $is_global
 * @property string $name
 * @property string|null $description
 * @property string|null $sector_label
 * @property bool $is_default
 * @property \Illuminate\Support\Carbon|null $auto_provisioned_at
 */
class Watchlist extends Model
{
    /** @use HasFactory<\Database\Factories\WatchlistFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'is_global',
        'name',
        'description',
        'sector_label',
        'is_default',
        'auto_provisioned_at',
    ];

    protected $casts = [
        'is_global' => 'boolean',
        'is_default' => 'boolean',
        'auto_provisioned_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Watchlist $watchlist) {
            if ($watchlist->is_global) {
                $watchlist->is_default = false;
            }
        });

        static::saved(function (Watchlist $watchlist) {
            if ($watchlist->user_id && ! $watchlist->is_global) {
                if ($watchlist->is_default) {
                    Watchlist::withoutEvents(function () use ($watchlist) {
                        Watchlist::query()
                            ->where('user_id', $watchlist->user_id)
                            ->where('id', '!=', $watchlist->id)
                            ->where('is_default', true)
                            ->update(['is_default' => false]);
                    });
                } else {
                    $hasDefault = Watchlist::query()
                        ->where('user_id', $watchlist->user_id)
                        ->where('is_global', false)
                        ->where('is_default', true)
                        ->exists();

                    if (! $hasDefault) {
                        $oldest = Watchlist::query()
                            ->where('user_id', $watchlist->user_id)
                            ->where('is_global', false)
                            ->orderBy('id')
                            ->first();

                        if ($oldest) {
                            Watchlist::withoutEvents(function () use ($oldest) {
                                $oldest->update(['is_default' => true]);
                            });
                        }
                    }
                }
            }

            if ($watchlist->user_id) {
                app(DashboardCacheService::class)->invalidateUserGainers((int) $watchlist->user_id);
            }

            if ($watchlist->is_global) {
                app(DashboardCacheService::class)->invalidateGlobalWatchlistGainers();
            }
        });

        static::deleted(function (Watchlist $watchlist) {
            if ($watchlist->user_id) {
                app(DashboardCacheService::class)->invalidateUserGainers((int) $watchlist->user_id);
            }

            if ($watchlist->is_global) {
                app(DashboardCacheService::class)->invalidateGlobalWatchlistGainers();
            }

            if ($watchlist->user_id && ! $watchlist->is_global) {
                $hasDefault = Watchlist::query()
                    ->where('user_id', $watchlist->user_id)
                    ->where('is_global', false)
                    ->where('is_default', true)
                    ->exists();

                if (! $hasDefault) {
                    $next = Watchlist::query()
                        ->where('user_id', $watchlist->user_id)
                        ->where('is_global', false)
                        ->orderBy('id')
                        ->first();

                    if ($next) {
                        Watchlist::withoutEvents(function () use ($next) {
                            $next->update(['is_default' => true]);
                        });
                    }
                }
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(WatchlistItem::class);
    }
}
