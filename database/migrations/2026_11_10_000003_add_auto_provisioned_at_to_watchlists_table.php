<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // No-op: auto_provisioned_at column and backfill are owned and managed by migration 000002
    }

    public function down(): void
    {
        // No-op: auto_provisioned_at column is owned and managed by migration 000002
    }
};
