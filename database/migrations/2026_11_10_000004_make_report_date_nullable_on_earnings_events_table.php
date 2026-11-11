<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('earnings_events') && Schema::hasColumn('earnings_events', 'report_date')) {
            Schema::table('earnings_events', function (Blueprint $table) {
                $table->date('report_date')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('earnings_events') && Schema::hasColumn('earnings_events', 'report_date')) {
            Schema::table('earnings_events', function (Blueprint $table) {
                $table->date('report_date')->nullable(false)->change();
            });
        }
    }
};
