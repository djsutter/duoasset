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
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('currency_code', 12)->unique();
            $table->string('numeric_code', 3)->nullable();
            $table->string('name', 100);
            $table->string('symbol', 5)->nullable();
            $table->enum('type', array_column(\App\Enums\CurrencyType::cases(), 'value'));
            $table->tinyInteger('scale');
            $table->tinyInteger('display_scale');
            $table->boolean('is_active')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
