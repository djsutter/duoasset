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
        Schema::create('pooled_superficial_allocations', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code');
            $table->foreignId('disposition_id');
            $table->date('window_start');
            $table->date('window_end');
            $table->decimal('allocated_units', 36, 18);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pooled_superficial_allocations');
    }
};
