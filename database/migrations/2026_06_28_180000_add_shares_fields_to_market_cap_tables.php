<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds shares_outstanding / float_shares / free_float columns to all
 * tables that previously stored a denormalized `market_cap` value.
 *
 * After this migration `market_cap` becomes a computed value
 * (current share price × shares_outstanding). The existing
 * `market_cap` columns are kept for backward compatibility and for
 * DB-level filtering (e.g. `where market_cap >= X`); models expose a
 * `market_cap` accessor that prefers the computed value and falls
 * back to the stored column when shares are missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('earnings_events', function (Blueprint $table) {
            $table->unsignedBigInteger('shares_outstanding')->nullable()->after('market_cap');
            $table->unsignedBigInteger('float_shares')->nullable()->after('shares_outstanding');
            $table->decimal('free_float', 10, 4)->nullable()->after('float_shares');
        });

        Schema::table('eps_revision_alerts', function (Blueprint $table) {
            $table->decimal('price', 14, 4)->nullable()->after('market_cap');
            $table->unsignedBigInteger('shares_outstanding')->nullable()->after('price');
            $table->unsignedBigInteger('float_shares')->nullable()->after('shares_outstanding');
            $table->decimal('free_float', 10, 4)->nullable()->after('float_shares');
        });

        Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
            $table->decimal('price', 14, 4)->nullable()->after('market_cap_category');
            $table->unsignedBigInteger('shares_outstanding')->nullable()->after('price');
            $table->unsignedBigInteger('float_shares')->nullable()->after('shares_outstanding');
            $table->decimal('free_float', 10, 4)->nullable()->after('float_shares');
        });

        Schema::table('stocks', function (Blueprint $table) {
            $table->unsignedBigInteger('shares_outstanding')->nullable()->after('market_cap');
            $table->unsignedBigInteger('float_shares')->nullable()->after('shares_outstanding');
            $table->decimal('free_float', 10, 4)->nullable()->after('float_shares');
        });
    }

    public function down(): void
    {
        Schema::table('earnings_events', function (Blueprint $table) {
            $table->dropColumn(['shares_outstanding', 'float_shares', 'free_float']);
        });

        Schema::table('eps_revision_alerts', function (Blueprint $table) {
            $table->dropColumn(['price', 'shares_outstanding', 'float_shares', 'free_float']);
        });

        Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
            $table->dropColumn(['price', 'shares_outstanding', 'float_shares', 'free_float']);
        });

        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn(['shares_outstanding', 'float_shares', 'free_float']);
        });
    }
};
