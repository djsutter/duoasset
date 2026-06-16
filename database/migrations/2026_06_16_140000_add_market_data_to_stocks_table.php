<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            // Prices are stored in FiatMoney minor units (10^4 scale).
            $table->bigInteger('last_price')->nullable()->after('company_name');
            $table->bigInteger('daily_change')->nullable()->after('last_price');
            // change percent stored as basis points * 100 (i.e. 4 decimal places).
            $table->integer('daily_change_percent')->nullable()->after('daily_change');
            $table->unsignedBigInteger('volume')->nullable()->after('daily_change_percent');
            $table->bigInteger('market_cap')->nullable()->after('volume');
            $table->timestamp('last_checked_at')->nullable()->after('market_cap');
        });
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn([
                'last_price',
                'daily_change',
                'daily_change_percent',
                'volume',
                'market_cap',
                'last_checked_at',
            ]);
        });
    }
};
