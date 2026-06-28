<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'notify_stock_buy_setup')) {
                $table->boolean('notify_stock_buy_setup')->default(false)->after('notify_eps_revisions');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'notify_stock_buy_setup')) {
                $table->dropColumn('notify_stock_buy_setup');
            }
        });
    }
};
