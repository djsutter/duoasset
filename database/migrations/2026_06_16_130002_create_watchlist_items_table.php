<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('watchlist_id')->constrained('watchlists')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained('stocks')->cascadeOnDelete();
            $table->text('thesis')->nullable();
            $table->string('moat_level');
            $table->string('currency', 3);
            $table->bigInteger('target_price')->nullable();
            $table->bigInteger('stop_price')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['watchlist_id', 'stock_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlist_items');
    }
};
