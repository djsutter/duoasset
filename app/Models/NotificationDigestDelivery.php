<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDigestDelivery extends Model
{
    public const CATEGORY_EPS_SURPRISE = 'eps_surprise';

    public const CATEGORY_SETUP_SCORING = 'setup_scoring';

    public const VALID_CATEGORIES = [
        self::CATEGORY_EPS_SURPRISE,
        self::CATEGORY_SETUP_SCORING,
    ];

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SENT = 'sent';

    public const STATUS_SKIPPED_UNSUBSCRIBED = 'skipped_unsubscribed';

    public const STATUS_SKIPPED_EMPTY = 'skipped_empty';

    public const STATUS_FAILED = 'failed';

    protected $table = 'notification_digest_deliveries';

    protected $fillable = [
        'scan_run_id',
        'category',
        'user_id',
        'status',
        'attempts',
        'watchlist_snapshot',
        'summary_counts',
        'last_error',
        'claimed_at',
        'sent_at',
    ];

    protected $casts = [
        'scan_run_id' => 'integer',
        'user_id' => 'integer',
        'attempts' => 'integer',
        'watchlist_snapshot' => 'array',
        'summary_counts' => 'array',
        'claimed_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function optOutTypeForCategory(string $category): string
    {
        return match ($category) {
            self::CATEGORY_EPS_SURPRISE => NotificationOptOut::TYPE_EARNINGS,
            self::CATEGORY_SETUP_SCORING => NotificationOptOut::TYPE_BUY_SETUP,
            default => $category,
        };
    }
}
