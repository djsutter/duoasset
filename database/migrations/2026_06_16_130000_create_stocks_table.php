<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->string('exchange');
            $table->string('currency', 3);
            $table->string('company_name');
            $table->foreignId('sector_id')->constrained('sectors')->cascadeOnDelete();
            $table->foreignId('industry_id')->constrained('industries')->cascadeOnDelete();
            $table->foreignId('sub_industry_id')->constrained('sub_industries')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['symbol', 'exchange']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
