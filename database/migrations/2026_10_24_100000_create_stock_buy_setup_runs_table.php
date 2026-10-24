<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('stock_buy_setup_runs')) {
            Schema::create('stock_buy_setup_runs', function (Blueprint $table) {
                $table->id();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->string('status')->default('running')->index();
                $table->string('trigger')->default('cli');
                $table->unsignedInteger('symbols_considered')->default(0);
                $table->unsignedInteger('scored_count')->default(0);
                $table->unsignedInteger('matched_count')->default(0);
                $table->unsignedInteger('rejected_count')->default(0);
                $table->unsignedInteger('error_count')->default(0);
                $table->json('categorized_reasons')->nullable();
                $table->json('raw_reasons')->nullable();
                $table->json('top_matches')->nullable();
                $table->json('summary_data')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_buy_setup_runs');
    }
};
