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
        Schema::create('pending_superficial_losses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('asset_code');
            $table->string('currency', 3);
            $table->foreignId('acb_event_id');
            $table->timestamp('window_start');
            $table->timestamp('window_end');
            $table->decimal('original_loss_amount', 36, 18);
            $table->decimal('original_units', 36, 18);
            $table->decimal('remaining_loss_amount', 36, 18);
            $table->decimal('remaining_units', 36, 18);
            $table->string('status');
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pending_superficial_losses');
    }
};
