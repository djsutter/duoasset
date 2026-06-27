<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('eps_estimate_history', function (Blueprint $table) {
            $table->id();
            $table->string('symbol')->index();
            $table->date('next_quarter_end_date')->nullable()->index();
            $table->decimal('eps_estimate', 14, 6);
            $table->timestamp('collected_at')->nullable()->index();
            $table->string('source', 32)->default('fmp');
            $table->timestamps();

            // One snapshot per (symbol, period, source) — the scanner upserts
            // into this on each run after detecting a meaningful change.
            $table->unique(
                ['source', 'symbol', 'next_quarter_end_date'],
                'eps_estimate_history_source_symbol_period_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eps_estimate_history');
    }
};
