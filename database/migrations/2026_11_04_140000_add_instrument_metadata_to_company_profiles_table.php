<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_profiles')) {
            Schema::table('company_profiles', function (Blueprint $table) {
                if (! Schema::hasColumn('company_profiles', 'is_actively_trading')) {
                    $table->boolean('is_actively_trading')->nullable();
                }
                if (! Schema::hasColumn('company_profiles', 'is_etf')) {
                    $table->boolean('is_etf')->nullable();
                }
                if (! Schema::hasColumn('company_profiles', 'is_fund')) {
                    $table->boolean('is_fund')->nullable();
                }
                if (! Schema::hasColumn('company_profiles', 'is_adr')) {
                    $table->boolean('is_adr')->nullable();
                }
                if (! Schema::hasColumn('company_profiles', 'type')) {
                    $table->string('type', 64)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('company_profiles')) {
            Schema::table('company_profiles', function (Blueprint $table) {
                $columns = ['is_actively_trading', 'is_etf', 'is_fund', 'is_adr', 'type'];
                $existing = array_filter($columns, fn ($column) => Schema::hasColumn('company_profiles', $column));
                if (! empty($existing)) {
                    $table->dropColumn(array_values($existing));
                }
            });
        }
    }
};
