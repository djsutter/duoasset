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
        Schema::create('acb_dailies', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code', 12);
            $table->date('date');
            $table->decimal('quantity_total', 36, 18);
            $table->decimal('acb_total', 36, 18);
            $table->decimal('avg_cost_basis', 36, 18);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acb_dailies');
    }
};
