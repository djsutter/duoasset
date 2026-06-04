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
        Schema::create('superficial_loss_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acb_event_id')->unique();
            $table->decimal('capital_loss_before_denial', 36, 18);
            $table->decimal('denied_loss_amount', 36, 18);
            $table->decimal('allowable_loss_amount', 36, 18);
            $table->date('window_start');
            $table->date('window_end');
            $table->string('reason_code', 50);
            $table->string('resolution_type', 20);
            $table->foreignId('replacement_acb_event_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('superficial_loss_events');
    }
};
