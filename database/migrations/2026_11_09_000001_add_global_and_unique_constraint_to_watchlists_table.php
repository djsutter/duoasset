<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('watchlists', function (Blueprint $table) {
            $table->boolean('is_global')->default(false)->after('user_id')->index();
            $table->unique(['user_id', 'name'], 'watchlists_user_id_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('watchlists', function (Blueprint $table) {
            $table->dropUnique('watchlists_user_id_name_unique');
            $table->dropColumn('is_global');
        });
    }
};
