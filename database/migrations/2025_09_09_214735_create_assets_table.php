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
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code');
            $table->unsignedTinyInteger('precision')->default(2);
            $table->decimal('quantity', 36, 18)->default('0');
            $table->decimal('acb', 36, 18)->default('0');
            $table->string('acb_currency')->default('CAD');
            $table->decimal('total_proceeds', 36, 18)->default('0');
            $table->decimal('total_cost', 36, 18)->default('0');
            $table->timestamp('last_transaction_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
