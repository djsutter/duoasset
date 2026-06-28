<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('earnings_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('earnings_event_id')->constrained()->cascadeOnDelete();
            $table->string('symbol')->index();
            $table->string('alert_type')->default('eps_surprise');
            $table->integer('score')->default(0);
            $table->string('status')->default('new');
            $table->text('message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['earnings_event_id', 'alert_type'], 'earnings_alerts_event_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('earnings_alerts');
    }
};
