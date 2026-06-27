<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'notify_eps_earnings')) {
                $table->boolean('notify_eps_earnings')->default(true)->after('email');
            }
            if (! Schema::hasColumn('users', 'notify_eps_revisions')) {
                $table->boolean('notify_eps_revisions')->default(true)->after('notify_eps_earnings');
            }
        });

        if (! Schema::hasTable('earnings_notification_deliveries')) {
            Schema::create('earnings_notification_deliveries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                // 'earnings' for EarningsAlert, 'revision' for EpsRevisionAlert.
                $table->string('alert_type', 32);
                $table->unsignedBigInteger('alert_id');
                $table->timestamp('sent_at')->useCurrent();
                $table->timestamps();

                $table->unique(['user_id', 'alert_type', 'alert_id'], 'earn_notif_deliv_uniq');
                $table->index(['alert_type', 'alert_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('earnings_notification_deliveries');

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'notify_eps_revisions')) {
                $table->dropColumn('notify_eps_revisions');
            }
            if (Schema::hasColumn('users', 'notify_eps_earnings')) {
                $table->dropColumn('notify_eps_earnings');
            }
        });
    }
};
