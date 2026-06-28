<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eps_revision_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('symbol')->index();
            $table->string('company_name')->nullable();
            $table->string('exchange', 16)->nullable()->index();
            $table->date('next_quarter_end_date')->nullable()->index();

            $table->decimal('previous_estimate', 14, 6);
            $table->decimal('latest_estimate', 14, 6);
            $table->decimal('revision_percent', 12, 4)->index();

            $table->string('direction', 16)->index();    // positive | negative
            $table->string('alert_type', 32)->default('eps_revision');
            $table->string('status', 16)->default('new');

            $table->unsignedBigInteger('market_cap')->nullable()->index();
            $table->text('message')->nullable();
            $table->string('source', 32)->default('fmp');
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // Duplicate-prevention per requirements: symbol + report date
            // (we use next_quarter_end_date as the "report date" for revisions)
            // + scanner type + direction.
            $table->unique(
                ['source', 'symbol', 'next_quarter_end_date', 'alert_type', 'direction'],
                'eps_revision_alerts_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eps_revision_alerts');
    }
};
