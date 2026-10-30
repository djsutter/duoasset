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
 * @property string $name
 * @property string|null $description
 * @property string|null $sector_label
 * @property bool $is_default
 */
class Watchlist extends Model
{
    /** @use HasFactory<\Database\Factories\WatchlistFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'sector_label',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    protected static function booted(): void
    {
        $forgetGainers = function (Watchlist $watchlist) {
            if ($watchlist->user_id) {
                app(DashboardCacheService::class)->invalidateUserGainers((int) $watchlist->user_id);
            }
        };
        static::saved($forgetGainers);
        static::deleted($forgetGainers);
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
