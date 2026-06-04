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
        Schema::create('lot_dispositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id');
            $table->string('asset_code');
            $table->decimal('disposed_quantity', 36, 18);
            $table->decimal('proceeds', 36, 18);
            $table->string('proceeds_currency');
            $table->decimal('acb_allocated', 36, 18);
            $table->decimal('denied_loss_amount', 36, 18);
            $table->datetime('disposed_at');
            $table->foreignId('acb_event_id');
            $table->timestamps();
            $table->unique(['lot_id', 'acb_event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lot_dispositions');
    }
};
