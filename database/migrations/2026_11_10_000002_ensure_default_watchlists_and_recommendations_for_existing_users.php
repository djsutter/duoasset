<?php

use App\Services\Watchlists\DefaultWatchlistService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('watchlists') && ! Schema::hasColumn('watchlists', 'auto_provisioned_at')) {
            Schema::table('watchlists', function (Blueprint $table) {
                $table->timestamp('auto_provisioned_at')->nullable()->after('is_default');
            });
        }

        // Reconcile any existing system-generated watchlists that were populated prior to this column existing
        if (Schema::hasTable('watchlists') && Schema::hasColumn('watchlists', 'auto_provisioned_at')) {
            DB::table('watchlists')
                ->where('description', 'System-generated default watchlist')
                ->whereNull('auto_provisioned_at')
                ->whereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('watchlist_items')
                        ->whereColumn('watchlist_items.watchlist_id', 'watchlists.id');
                })
                ->update(['auto_provisioned_at' => now()]);
        }

        app(DefaultWatchlistService::class)->backfillExistingUsers();
    }

    public function down(): void
    {
        if (Schema::hasTable('watchlists') && Schema::hasColumn('watchlists', 'auto_provisioned_at')) {
            Schema::table('watchlists', function (Blueprint $table) {
                $table->dropColumn('auto_provisioned_at');
            });
        }
    }
};
