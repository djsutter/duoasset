<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchlist_alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('watchlist_alert_rule_id')
                ->constrained('watchlist_alert_rules')
                ->cascadeOnDelete();
            $table->foreignId('watchlist_item_id')
                ->constrained('watchlist_items')
                ->cascadeOnDelete();

            $table->string('severity'); // AlertSeverity value
            $table->timestamp('triggered_at');
            $table->timestamp('seen_at')->nullable();

            // Snapshot of values at trigger time, plus a human message.
            $table->string('currency', 3)->nullable();
            $table->bigInteger('observed_price')->nullable();
            $table->json('context')->nullable();
            $table->text('message')->nullable();

            // Notification history (channel => sent_at, ...).
            $table->json('notifications')->nullable();

            $table->timestamps();

            $table->index(['watchlist_item_id', 'triggered_at'], 'wae_item_triggered_idx');
            $table->index(['watchlist_alert_rule_id', 'triggered_at'], 'wae_rule_triggered_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlist_alert_events');
    }
};
