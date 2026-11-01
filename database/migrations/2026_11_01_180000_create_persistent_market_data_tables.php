<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_daily_bars') && ! Schema::hasColumn('stock_daily_bars', 'retrieved_at')) {
            Schema::table('stock_daily_bars', function (Blueprint $table) {
                $table->timestamp('retrieved_at')->nullable()->after('volume');
            });
        }

        if (! Schema::hasTable('market_data_payloads')) {
            Schema::create('market_data_payloads', function (Blueprint $table) {
                $table->id();
                $table->string('symbol', 32)->index();
                $table->string('data_type', 64);
                $table->string('period', 64)->default('default');
                $table->json('payload');
                $table->timestamp('retrieved_at')->useCurrent();
                $table->timestamps();

                $table->unique(['symbol', 'data_type', 'period'], 'market_data_payloads_uniq');
            });
        }

        if (! Schema::hasTable('company_profiles')) {
            Schema::create('company_profiles', function (Blueprint $table) {
                $table->id();
                $table->string('symbol', 32)->unique();
                $table->string('company_name', 255)->nullable();
                $table->string('exchange', 32)->nullable();
                $table->string('currency', 16)->nullable();
                $table->string('country', 64)->nullable();
                $table->string('sector', 128)->nullable();
                $table->string('industry', 128)->nullable();
                $table->string('sub_industry', 128)->nullable();
                $table->unsignedBigInteger('market_cap')->nullable();
                $table->decimal('price', 18, 6)->nullable();
                $table->unsignedBigInteger('shares_outstanding')->nullable();
                $table->unsignedBigInteger('float_shares')->nullable();
                $table->decimal('free_float', 8, 4)->nullable();
                $table->timestamp('retrieved_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('company_financials')) {
            Schema::create('company_financials', function (Blueprint $table) {
                $table->id();
                $table->string('symbol', 32)->index();
                $table->string('statement_type', 32);
                $table->date('fiscal_date');
                $table->string('period', 16)->default('quarter');
                $table->integer('fiscal_year')->nullable();
                $table->decimal('revenue', 20, 2)->nullable();
                $table->decimal('net_income', 20, 2)->nullable();
                $table->decimal('eps', 12, 4)->nullable();
                $table->decimal('stockholders_equity', 20, 2)->nullable();
                $table->decimal('free_cash_flow', 20, 2)->nullable();
                $table->string('reported_currency', 16)->nullable();
                $table->json('raw_metrics')->nullable();
                $table->timestamp('retrieved_at')->nullable();
                $table->timestamps();

                $table->unique(['symbol', 'statement_type', 'fiscal_date', 'period'], 'company_financials_uniq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_financials');
        Schema::dropIfExists('company_profiles');
        Schema::dropIfExists('market_data_payloads');

        if (Schema::hasTable('stock_daily_bars') && Schema::hasColumn('stock_daily_bars', 'retrieved_at')) {
            Schema::table('stock_daily_bars', function (Blueprint $table) {
                $table->dropColumn('retrieved_at');
            });
        }
    }
};
