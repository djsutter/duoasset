<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

        Schema::table('earnings_events', function (Blueprint $table) {
            $table->unsignedBigInteger('scan_run_id')->nullable()->after('source');
            $table->index('scan_run_id', 'earnings_events_scan_run_id_index');
        });

        Schema::table('earnings_alerts', function (Blueprint $table) {
            $table->unsignedBigInteger('scan_run_id')->nullable()->after('earnings_event_id');
            $table->index(['scan_run_id', 'symbol'], 'earnings_alerts_run_symbol_index');
        });
    }

    public function down(): void
    {
        Schema::table('earnings_alerts', function (Blueprint $table) {
            $table->dropIndex('earnings_alerts_run_symbol_index');
            $table->dropColumn('scan_run_id');
        });

        Schema::table('earnings_events', function (Blueprint $table) {
            $table->dropIndex('earnings_events_scan_run_id_index');
            $table->dropColumn('scan_run_id');
        });

        Schema::dropIfExists('earnings_scan_runs');
    }
};
