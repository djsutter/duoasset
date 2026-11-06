<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_digest_deliveries')) {
            Schema::create('notification_digest_deliveries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('scan_run_id');
                $table->string('category', 32);
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('status', 32)->default('queued')->index();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->json('watchlist_snapshot')->nullable();
                $table->json('summary_counts')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->unique(['scan_run_id', 'category', 'user_id'], 'notif_digest_run_cat_user_uniq');
                $table->index(['category', 'scan_run_id', 'status'], 'notif_digest_cat_run_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_digest_deliveries');
    }
};
