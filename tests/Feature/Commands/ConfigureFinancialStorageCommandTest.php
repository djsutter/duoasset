<?php

namespace Tests\Feature\Commands;

use App\Services\MarketData\FinancialStorageConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigureFinancialStorageCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_displays_status(): void
    {
        $this->artisan('market-data:financial-storage status')
            ->expectsOutputToContain('Financial Storage & Caching')
            ->expectsOutputToContain('ENABLED (Database & Memory Active)')
            ->assertSuccessful();
    }

    public function test_can_disable_financial_storage(): void
    {
        $this->artisan('market-data:financial-storage disable')
            ->expectsOutputToContain('Financial storage and caching has been DISABLED application-wide.')
            ->expectsOutputToContain('DISABLED (Direct Upstream Bypass)')
            ->assertSuccessful();

        $this->assertFalse(app(FinancialStorageConfigService::class)->isEnabled());
    }

    public function test_can_enable_financial_storage(): void
    {
        app(FinancialStorageConfigService::class)->disable();

        $this->artisan('market-data:financial-storage enable')
            ->expectsOutputToContain('Financial storage and caching has been ENABLED application-wide.')
            ->expectsOutputToContain('ENABLED (Database & Memory Active)')
            ->assertSuccessful();

        $this->assertTrue(app(FinancialStorageConfigService::class)->isEnabled());
    }

    public function test_can_toggle_financial_storage(): void
    {
        $this->artisan('market-data:financial-storage toggle')
            ->expectsOutputToContain('Financial storage and caching has been toggled to: DISABLED')
            ->assertSuccessful();

        $this->assertFalse(app(FinancialStorageConfigService::class)->isEnabled());

        $this->artisan('market-data:financial-storage toggle')
            ->expectsOutputToContain('Financial storage and caching has been toggled to: ENABLED')
            ->assertSuccessful();

        $this->assertTrue(app(FinancialStorageConfigService::class)->isEnabled());
    }

    public function test_rejects_invalid_action(): void
    {
        $this->artisan('market-data:financial-storage invalid-action')
            ->expectsOutputToContain('Invalid action [invalid-action]')
            ->assertFailed();
    }
}
