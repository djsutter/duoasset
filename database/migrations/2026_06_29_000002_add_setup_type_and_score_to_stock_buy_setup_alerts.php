<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_buy_setup_alerts', 'setup_type')) {
                $table->string('setup_type', 64)
                    ->default('heartbeat_consolidation_spike')
                    ->after('symbol')
                    ->index();
            }

            if (! Schema::hasColumn('stock_buy_setup_alerts', 'setup_score')) {
                $table->unsignedInteger('setup_score')
                    ->default(0)
                    ->after('setup_type')
                    ->index();
            }
        });

        DB::table('stock_buy_setup_alerts')
            ->where('setup_score', 0)
            ->update(['setup_score' => DB::raw('heartbeat_score')]);

        // The original unique key prevented more than one setup type for the
        // same symbol/spike date. Replace it so each detector can save its own
        // row while staying idempotent. Run the drop and add in separate
        // Schema::table() calls so the queued Blueprint commands are flushed
        // (and any failures caught) independently — otherwise a failing
        // dropUnique() at flush time aborts the whole closure.
        try {
            Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
                $table->dropUnique('stock_buy_setup_alerts_uniq');
            });
        } catch (Throwable) {
            // Already dropped or unavailable on this driver (e.g. fresh
            // schema created without the legacy index).
        }

        try {
            Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
                $table->unique(['source', 'symbol', 'setup_type', 'spike_date'], 'stock_buy_setup_alerts_type_uniq');
            });
        } catch (Throwable) {
            // Index may already exist.
        }
    }

    public function down(): void
    {
        Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
            try {
                $table->dropUnique('stock_buy_setup_alerts_type_uniq');
            } catch (Throwable) {
                // Ignore.
            }

            try {
                $table->unique(['source', 'symbol', 'spike_date'], 'stock_buy_setup_alerts_uniq');
            } catch (Throwable) {
                // Existing multi-type rows may prevent reverting this unique key.
            }

            if (Schema::hasColumn('stock_buy_setup_alerts', 'setup_score')) {
                $table->dropColumn('setup_score');
            }

            if (Schema::hasColumn('stock_buy_setup_alerts', 'setup_type')) {
                $table->dropColumn('setup_type');
            }
        });
    }
};
