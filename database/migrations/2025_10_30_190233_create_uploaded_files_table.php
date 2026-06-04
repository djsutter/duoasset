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
        Schema::create('uploaded_files', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('directory');
            $table->integer('size')->nullable();
            $table->string('mapper')->nullable();
            $table->string('platform')->nullable();
            $table->string('wallet')->nullable();
            $table->string('wallet_prefix')->nullable();
            $table->enum('status', ['pending', 'detected', 'importing', 'imported', 'failed']);
            $table->datetime('imported_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('uploaded_files');
    }
};
