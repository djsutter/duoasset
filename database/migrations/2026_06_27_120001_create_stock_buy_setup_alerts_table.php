<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_buy_setup_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32)->default('fmp');
            $table->string('symbol', 32)->index();
            $table->string('setup_type', 64)->default('heartbeat_consolidation_spike')->index();
            $table->unsignedInteger('setup_score')->default(0)->index();
            $table->string('company_name')->nullable();
            $table->string('exchange', 16)->nullable()->index();
            $table->unsignedBigInteger('market_cap')->nullable()->index();
            $table->string('market_cap_category', 16)->nullable();

            $table->date('spike_date');
            $table->unsignedBigInteger('spike_volume')->nullable();
            $table->unsignedBigInteger('prior_52w_max_volume')->nullable();
            $table->unsignedBigInteger('max_104w_volume')->nullable();
            $table->boolean('is_52w_high_volume')->default(false);
            $table->boolean('is_104w_high_volume')->default(false);
            $table->integer('days_since_previous_comparable_spike')->nullable();

            $table->date('base_start_date')->nullable();
            $table->date('base_end_date')->nullable();
            $table->integer('base_duration_days')->nullable();
            $table->decimal('base_high', 18, 6)->nullable();
            $table->decimal('base_low', 18, 6)->nullable();
            $table->decimal('range_compression_pct', 10, 4)->nullable();
            $table->decimal('atr_contraction_ratio', 10, 4)->nullable();
            $table->decimal('volume_dry_up_score', 10, 4)->nullable();
            $table->decimal('slope', 18, 8)->nullable();

            $table->decimal('distance_to_breakout_pct', 10, 4)->nullable();
            $table->string('ma_alignment')->nullable();
            $table->decimal('relative_strength_score', 10, 4)->nullable();
            $table->decimal('earnings_acceleration', 10, 4)->nullable();
            $table->decimal('sales_acceleration', 10, 4)->nullable();

            $table->unsignedInteger('heartbeat_score')->default(0)->index();
            $table->text('reason_summary')->nullable();
            $table->string('status', 16)->default('new');
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'symbol', 'setup_type', 'spike_date'], 'stock_buy_setup_alerts_type_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_buy_setup_alerts');
    }
};
