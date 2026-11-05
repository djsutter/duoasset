<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EarningsScanRun extends Model
{
    use HasFactory;

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'earnings_scan_runs';

    protected $fillable = [
        'started_at',
        'completed_at',
        'status',
        'trigger',
        'from_date',
        'to_date',
        'events_processed',
        'alerts_created',
        'alerts_updated',
        'error_count',
        'summary_data',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'from_date' => 'date',
        'to_date' => 'date',
        'events_processed' => 'integer',
        'alerts_created' => 'integer',
        'alerts_updated' => 'integer',
        'error_count' => 'integer',
        'summary_data' => 'array',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(EarningsEvent::class, 'scan_run_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(EarningsAlert::class, 'scan_run_id');
    }

    public function digestDeliveries(): HasMany
    {
        return $this->hasMany(NotificationDigestDelivery::class, 'scan_run_id')
            ->where('category', NotificationDigestDelivery::CATEGORY_EPS_SURPRISE);
    }
}
