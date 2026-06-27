<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per (user, alert_type, alert_id) — guarantees we never email
 * the same earnings/revision alert to the same recipient twice.
 *
 * `alert_type` is 'earnings' (App\Models\EarningsAlert) or 'revision'
 * (App\Models\EpsRevisionAlert).
 */
class EarningsNotificationDelivery extends Model
{
    protected $fillable = [
        'user_id',
        'alert_type',
        'alert_id',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public const TYPE_EARNINGS = 'earnings';
    public const TYPE_REVISION = 'revision';
}
