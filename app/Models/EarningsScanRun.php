<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Throwable;

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

    protected static ?bool $hasAlertScanRunColumnCache = null;

    public static function clearSchemaCache(): void
    {
        self::$hasAlertScanRunColumnCache = null;
    }

    public static function hasAlertScanRunColumn(): bool
    {
        if (self::$hasAlertScanRunColumnCache !== null) {
            return self::$hasAlertScanRunColumnCache;
        }

        try {
            return self::$hasAlertScanRunColumnCache = Schema::hasTable('earnings_alerts')
                && Schema::hasColumn('earnings_alerts', 'scan_run_id');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Idempotently ensure that earnings_scan_runs and the scan_run_id columns on
     * earnings_events and earnings_alerts exist (self-heals unmigrated environments).
     */
    public static function ensureRunTrackingSchema(): bool
    {
        try {
            self::clearSchemaCache();
            if (! Schema::hasTable('earnings_scan_runs')) {
                Schema::create('earnings_scan_runs', function (Blueprint $table) {
                    $table->id();
                    $table->timestamp('started_at')->nullable();
                    $table->timestamp('completed_at')->nullable();
                    $table->string('status', 32)->default('running')->index();
                    $table->string('trigger', 32)->default('cli');
                    $table->date('from_date')->nullable();
                    $table->date('to_date')->nullable();
                    $table->unsignedInteger('events_processed')->default(0);
                    $table->unsignedInteger('alerts_created')->default(0);
                    $table->unsignedInteger('alerts_updated')->default(0);
                    $table->unsignedInteger('error_count')->default(0);
                    $table->json('summary_data')->nullable();
                    $table->timestamps();
                });
            }

            if (Schema::hasTable('earnings_events') && ! Schema::hasColumn('earnings_events', 'scan_run_id')) {
                Schema::table('earnings_events', function (Blueprint $table) {
                    $table->unsignedBigInteger('scan_run_id')->nullable()->after('source');
                    $table->index('scan_run_id', 'earnings_events_scan_run_id_index');
                });
            }

            if (Schema::hasTable('earnings_alerts') && ! Schema::hasColumn('earnings_alerts', 'scan_run_id')) {
                Schema::table('earnings_alerts', function (Blueprint $table) {
                    $table->unsignedBigInteger('scan_run_id')->nullable()->after('earnings_event_id');
                    $table->index(['scan_run_id', 'symbol'], 'earnings_alerts_run_symbol_index');
                });
            }

            return Schema::hasTable('earnings_scan_runs')
                && Schema::hasColumn('earnings_alerts', 'scan_run_id');
        } catch (Throwable) {
            return false;
        }
    }
}
