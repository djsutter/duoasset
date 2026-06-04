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
        Schema::create('lots', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code');
            $table->datetime('acquired_at');
            $table->decimal('original_quantity', 36, 18);
            $table->decimal('remaining_quantity', 36, 18);
            $table->decimal('original_acb_amount', 36, 18);
            $table->foreignId('acb_event_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lots');
    }
};
