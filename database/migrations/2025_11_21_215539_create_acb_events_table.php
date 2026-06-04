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
        Schema::create('acb_events', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code', 12);
            $table->foreignId('tx_id');
            $table->datetime('event_at');
            $table->enum('event_type', array_column(\App\Enums\AcbEventType::cases(), 'value'));
            $table->decimal('quantity', 36, 18);
            $table->decimal('cost_amount', 36, 18);
            $table->decimal('proceeds', 36, 18)->default(0);
            $table->string('adjustment_reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acb_events');
    }
};
