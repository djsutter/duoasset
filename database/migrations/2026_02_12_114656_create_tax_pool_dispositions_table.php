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
        Schema::create('tax_pool_dispositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acb_event_id')->constrained()->cascadeOnDelete();
            $table->enum('origin_event_type', array_column(\App\Enums\AcbEventType::cases(), 'value'));
            $table->string('asset_code');
            $table->string('currency');
            $table->decimal('quantity_disposed', 36, 18);
            $table->decimal('proceeds', 36, 18);
            $table->decimal('acb_allocated', 36, 18);
            $table->decimal('capital_gain_loss_before_denial', 36, 18);
            $table->decimal('denied_loss_amount', 36, 18)->nullable();
            $table->decimal('capital_gain_loss_after_denial', 36, 18);
            $table->date('disposition_date');
            $table->timestamps();

            $table->unique('acb_event_id');
            $table->index('asset_code');
            $table->index('disposition_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_pool_dispositions');
    }
};
