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
        Schema::create('tax_pools', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code');
            $table->decimal('total_quantity', 36, 18);
            $table->decimal('total_acb', 36, 18);
            $table->timestamps();

            $table->unique('asset_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_pools');
    }
};
