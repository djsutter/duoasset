<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('earnings_events', function (Blueprint $table) {
            $table->id();
            $table->string('symbol')->index();
            $table->string('exchange')->nullable()->index();
            $table->string('company_name')->nullable();
            $table->date('report_date')->index();
            $table->string('report_time')->nullable();
            $table->string('fiscal_period')->nullable();
            $table->decimal('eps_estimated', 14, 4)->nullable();
            $table->decimal('eps_actual', 14, 4)->nullable();
            $table->decimal('eps_surprise', 14, 4)->nullable();
            $table->decimal('eps_surprise_percent', 14, 4)->nullable()->index();
            $table->bigInteger('revenue_estimated')->nullable();
            $table->bigInteger('revenue_actual')->nullable();
            $table->decimal('revenue_surprise_percent', 14, 4)->nullable();
            $table->unsignedBigInteger('market_cap')->nullable()->index();
            $table->decimal('price', 14, 4)->nullable();
            $table->unsignedBigInteger('volume')->nullable();
            $table->unsignedBigInteger('avg_volume')->nullable();
            $table->decimal('relative_volume', 14, 4)->nullable();
            $table->string('source')->default('fmp');
            $table->json('raw')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'symbol', 'report_date'], 'earnings_events_source_symbol_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('earnings_events');
    }
};
