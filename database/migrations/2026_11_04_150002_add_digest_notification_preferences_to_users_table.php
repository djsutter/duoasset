<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'email_eps_surprise_digest')) {
                $table->boolean('email_eps_surprise_digest')->default(true)->after('notify_eps_earnings');
            }
            if (! Schema::hasColumn('users', 'email_setup_scoring_digest')) {
                $table->boolean('email_setup_scoring_digest')->default(false)->after('notify_stock_buy_setup');
            }
        });

        // Preserve existing explicit opt-in / opt-out preferences.
        DB::table('users')->update([
            'email_eps_surprise_digest' => DB::raw('notify_eps_earnings'),
            'email_setup_scoring_digest' => DB::raw('notify_stock_buy_setup'),
        ]);

        // Also respect any active opt-out records in notification_opt_outs.
        if (Schema::hasTable('notification_opt_outs')) {
            $earningsOptOuts = DB::table('notification_opt_outs')
                ->where('item_type', 'earnings')
                ->whereNotNull('opted_out_at')
                ->pluck('email')
                ->map(fn ($email) => strtolower(trim((string) $email)))
                ->filter()
                ->all();

            if (! empty($earningsOptOuts)) {
                DB::table('users')
                    ->whereIn(DB::raw('LOWER(email)'), $earningsOptOuts)
                    ->update([
                        'email_eps_surprise_digest' => false,
                        'notify_eps_earnings' => false,
                    ]);
            }

            $setupOptOuts = DB::table('notification_opt_outs')
                ->where('item_type', 'buy_setup')
                ->whereNotNull('opted_out_at')
                ->pluck('email')
                ->map(fn ($email) => strtolower(trim((string) $email)))
                ->filter()
                ->all();

            if (! empty($setupOptOuts)) {
                DB::table('users')
                    ->whereIn(DB::raw('LOWER(email)'), $setupOptOuts)
                    ->update([
                        'email_setup_scoring_digest' => false,
                        'notify_stock_buy_setup' => false,
                    ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $columns = [
            'email_eps_surprise_digest',
            'email_setup_scoring_digest',
        ];
        $existing = array_values(array_filter(
            $columns,
            fn (string $col) => Schema::hasColumn('users', $col),
        ));

        if (! empty($existing)) {
            Schema::table('users', function (Blueprint $table) use ($existing) {
                $table->dropColumn($existing);
            });
        }
    }
};
