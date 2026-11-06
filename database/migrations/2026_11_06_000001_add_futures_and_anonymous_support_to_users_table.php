<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'assigned_roles')) {
                    $table->json('assigned_roles')->nullable()->after('role');
                }
                if (! Schema::hasColumn('users', 'is_anonymous')) {
                    $table->boolean('is_anonymous')->default(false)->index()->after('view_mode');
                }
                if (! Schema::hasColumn('users', 'recent_futures')) {
                    $table->json('recent_futures')->nullable()->after('view_mode');
                }
            });
        }

        if (Schema::hasTable('stock_buy_setup_alerts')) {
            try {
                Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
                    $table->index(['setup_score', 'spike_date', 'id'], 'sbsa_score_spikedate_id_idx');
                });
            } catch (\Throwable) {
                // Index may already exist
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stock_buy_setup_alerts')) {
            try {
                Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
                    $table->dropIndex('sbsa_score_spikedate_id_idx');
                });
            } catch (\Throwable) {
            }
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $cols = [];
                foreach (['assigned_roles', 'is_anonymous', 'recent_futures'] as $col) {
                    if (Schema::hasColumn('users', $col)) {
                        $cols[] = $col;
                    }
                }
                if (! empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
