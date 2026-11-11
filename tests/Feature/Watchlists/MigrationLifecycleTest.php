<?php

namespace Tests\Feature\Watchlists;

use App\Models\EarningsEvent;
use App\Models\Watchlist;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationLifecycleTest extends TestCase
{
    public function test_fresh_database_migrations_and_rollbacks_execute_cleanly(): void
    {
        // 1. Fresh migrations on a fresh schema
        $this->artisan('migrate:fresh')->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('watchlists'));
        $this->assertTrue(Schema::hasColumn('watchlists', 'auto_provisioned_at'));
        $this->assertTrue(Schema::hasTable('earnings_events'));
        $this->assertTrue(Schema::hasColumn('earnings_events', 'report_date'));

        // 2. Rollback migration 000004 (earnings_events nullable report_date)
        $this->artisan('migrate:rollback', ['--step' => 1])->assertExitCode(0);

        // 3. Rollback migration 000003 (no-op migration: must NOT drop auto_provisioned_at because 000002 owns it)
        $this->artisan('migrate:rollback', ['--step' => 1])->assertExitCode(0);
        $this->assertTrue(
            Schema::hasColumn('watchlists', 'auto_provisioned_at'),
            'Migration 000003 must not drop auto_provisioned_at column owned by migration 000002'
        );

        // 4. Rollback migration 000002 (which owns auto_provisioned_at)
        $this->artisan('migrate:rollback', ['--step' => 1])->assertExitCode(0);
        $this->assertFalse(
            Schema::hasColumn('watchlists', 'auto_provisioned_at'),
            'Migration 000002 rollback should drop auto_provisioned_at column'
        );

        // 5. Re-run migrations to bring database up to date
        $this->artisan('migrate')->assertExitCode(0);
        $this->assertTrue(Schema::hasColumn('watchlists', 'auto_provisioned_at'));
        $this->assertTrue(Schema::hasColumn('earnings_events', 'report_date'));
    }
}
