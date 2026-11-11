<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $userIds = DB::table('watchlists')
            ->where('is_global', false)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            $hasDefault = DB::table('watchlists')
                ->where('user_id', $userId)
                ->where('is_global', false)
                ->where('is_default', true)
                ->exists();

            if (! $hasDefault) {
                $oldestId = DB::table('watchlists')
                    ->where('user_id', $userId)
                    ->where('is_global', false)
                    ->orderBy('id')
                    ->value('id');

                if ($oldestId) {
                    DB::table('watchlists')
                        ->where('id', $oldestId)
                        ->update(['is_default' => true]);
                }
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: down migration preserves current default status
    }
};
