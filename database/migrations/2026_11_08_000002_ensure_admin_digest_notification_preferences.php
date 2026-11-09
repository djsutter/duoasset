<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $hasEpsDigestCol = Schema::hasColumn('users', 'email_eps_surprise_digest');
        $hasSetupDigestCol = Schema::hasColumn('users', 'email_setup_scoring_digest');
        $hasNotifyEpsCol = Schema::hasColumn('users', 'notify_eps_earnings');
        $hasNotifySetupCol = Schema::hasColumn('users', 'notify_stock_buy_setup');
        $hasAnonCol = Schema::hasColumn('users', 'is_anonymous');
        $hasOptOutsTable = Schema::hasTable('notification_opt_outs');

        $earningsOptOuts = [];
        $setupOptOuts = [];
        if ($hasOptOutsTable) {
            $earningsOptOuts = DB::table('notification_opt_outs')
                ->where('item_type', 'earnings')
                ->whereNotNull('opted_out_at')
                ->pluck('email')
                ->map(fn ($e) => strtolower(trim((string) $e)))
                ->filter()
                ->all();

            $setupOptOuts = DB::table('notification_opt_outs')
                ->where('item_type', 'buy_setup')
                ->whereNotNull('opted_out_at')
                ->pluck('email')
                ->map(fn ($e) => strtolower(trim((string) $e)))
                ->filter()
                ->all();
        }

        // 1. Ensure seeded administrator accounts (Admin1 / Admin2) have both EPS and Setup
        // digest notifications enabled unless they have an active opt-out record.
        $adminQuery = DB::table('users')
            ->where(function ($q) {
                $q->whereIn('email', ['dsutter@qualivera.com', 'joe@7pro.ca', 'j@7pro.ca'])
                    ->orWhereIn('name', ['Admin1', 'Admin2'])
                    ->orWhere('role', UserRole::ADMINISTRATOR->value);
            })
            ->where('email', '!=', User::ANONYMOUS_EMAIL);

        if ($hasAnonCol) {
            $adminQuery->where('is_anonymous', false);
        }

        foreach ($adminQuery->get() as $adminRow) {
            $email = strtolower(trim((string) $adminRow->email));
            $updates = [];

            if (! in_array($email, $earningsOptOuts, true)) {
                if ($hasNotifyEpsCol) {
                    $updates['notify_eps_earnings'] = true;
                }
                if ($hasEpsDigestCol) {
                    $updates['email_eps_surprise_digest'] = true;
                }
            }

            if (! in_array($email, $setupOptOuts, true)) {
                if ($hasNotifySetupCol) {
                    $updates['notify_stock_buy_setup'] = true;
                }
                if ($hasSetupDigestCol) {
                    $updates['email_setup_scoring_digest'] = true;
                }
            }

            if (! empty($updates)) {
                DB::table('users')->where('id', $adminRow->id)->update($updates);
            }
        }

        // 2. Reconcile any non-anonymous user rows where the legacy notify_* column and
        // the digest email_* column drifted out of sync.
        if ($hasEpsDigestCol && $hasNotifyEpsCol) {
            $epsSyncQuery = DB::table('users')
                ->where('email', '!=', User::ANONYMOUS_EMAIL)
                ->where(function ($q) {
                    $q->where('email_eps_surprise_digest', true)
                        ->orWhere('notify_eps_earnings', true);
                });
            if ($hasAnonCol) {
                $epsSyncQuery->where('is_anonymous', false);
            }
            if (! empty($earningsOptOuts)) {
                $epsSyncQuery->whereNotIn(DB::raw('LOWER(email)'), $earningsOptOuts);
            }
            $epsSyncQuery->update([
                'email_eps_surprise_digest' => true,
                'notify_eps_earnings' => true,
            ]);
        }

        if ($hasSetupDigestCol && $hasNotifySetupCol) {
            $setupSyncQuery = DB::table('users')
                ->where('email', '!=', User::ANONYMOUS_EMAIL)
                ->where(function ($q) {
                    $q->where('email_setup_scoring_digest', true)
                        ->orWhere('notify_stock_buy_setup', true);
                });
            if ($hasAnonCol) {
                $setupSyncQuery->where('is_anonymous', false);
            }
            if (! empty($setupOptOuts)) {
                $setupSyncQuery->whereNotIn(DB::raw('LOWER(email)'), $setupOptOuts);
            }
            $setupSyncQuery->update([
                'email_setup_scoring_digest' => true,
                'notify_stock_buy_setup' => true,
            ]);
        }
    }

    public function down(): void
    {
        // Corrective data migration for admin notification preferences; no schema rollback needed.
    }
};
