<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchlist_alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('watchlist_item_id')->constrained('watchlist_items')->cascadeOnDelete();
            $table->string('type');                  // AlertRuleType value
            $table->string('severity')->default('info'); // AlertSeverity value
            $table->boolean('is_active')->default(true);

            // Optional FiatMoney threshold for price-based rules.
            $table->string('currency', 3)->nullable();
            $table->bigInteger('target_price')->nullable();

            // Free-form parameters (e.g. percent threshold, volume multiplier).
            $table->json('parameters')->nullable();

            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();

            $table->index(['watchlist_item_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlist_alert_rules');
    }
};
