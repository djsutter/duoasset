<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Idempotent: column may already exist from a partial prior run.
        if (! Schema::hasColumn('earnings_alerts', 'direction')) {
            Schema::table('earnings_alerts', function (Blueprint $table) {
                $table->string('direction', 16)->default('positive')->after('alert_type')->index();
            });
        }

        // Backfill any nulls (defensive — column has a default but a prior
        // failed run could have left rows missing the value).
        DB::table('earnings_alerts')->whereNull('direction')->update(['direction' => 'positive']);

        // MySQL refuses to drop the old unique index because it's the only
        // index backing the `earnings_event_id` foreign key. Add a plain
        // index on that column first so the FK keeps an index to use, then
        // drop the old unique index, then add the new direction-aware one.
        if (! $this->indexExists('earnings_alerts', 'earnings_alerts_event_id_index')) {
            Schema::table('earnings_alerts', function (Blueprint $table) {
                $table->index('earnings_event_id', 'earnings_alerts_event_id_index');
            });
        }

        if ($this->indexExists('earnings_alerts', 'earnings_alerts_event_type_unique')) {
            Schema::table('earnings_alerts', function (Blueprint $table) {
                $table->dropUnique('earnings_alerts_event_type_unique');
            });
        }

        if (! $this->indexExists('earnings_alerts', 'earnings_alerts_event_type_dir_unique')) {
            Schema::table('earnings_alerts', function (Blueprint $table) {
                $table->unique(
                    ['earnings_event_id', 'alert_type', 'direction'],
                    'earnings_alerts_event_type_dir_unique',
                );
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('earnings_alerts', 'earnings_alerts_event_type_dir_unique')) {
            Schema::table('earnings_alerts', function (Blueprint $table) {
                $table->dropUnique('earnings_alerts_event_type_dir_unique');
            });
        }
        if (! $this->indexExists('earnings_alerts', 'earnings_alerts_event_type_unique')) {
            Schema::table('earnings_alerts', function (Blueprint $table) {
                $table->unique(['earnings_event_id', 'alert_type'], 'earnings_alerts_event_type_unique');
            });
        }
        if ($this->indexExists('earnings_alerts', 'earnings_alerts_event_id_index')) {
            Schema::table('earnings_alerts', function (Blueprint $table) {
                $table->dropIndex('earnings_alerts_event_id_index');
            });
        }
        if (Schema::hasColumn('earnings_alerts', 'direction')) {
            Schema::table('earnings_alerts', function (Blueprint $table) {
                $table->dropIndex(['direction']);
                $table->dropColumn('direction');
            });
        }
    }

    /**
     * Driver-agnostic check for a named index.
     */
    protected function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $rows = DB::select(
                'SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?',
                [$indexName],
            );

            return ! empty($rows);
        }

        if ($driver === 'sqlite') {
            $rows = DB::select(
                "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name = ? AND name = ?",
                [$table, $indexName],
            );

            return ! empty($rows);
        }

        // pgsql and others — try information_schema.
        $rows = DB::select(
            'SELECT 1 FROM information_schema.statistics WHERE table_name = ? AND index_name = ? LIMIT 1',
            [$table, $indexName],
        );

        return ! empty($rows);
    }
};
