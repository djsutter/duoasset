<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Livewire\Admin\Configuration;
use App\Models\CompanyFinancial;
use App\Models\User;
use App\Services\MarketData\FinancialStorageConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminFinancialStorageConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $guestUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'role' => UserRole::ADMINISTRATOR,
            'email' => 'admin@example.com',
        ]);

        $this->guestUser = User::factory()->create([
            'role' => UserRole::GUEST,
            'email' => 'guest@example.com',
        ]);
    }

    public function test_unauthenticated_user_cannot_access_or_toggle_financial_storage(): void
    {
        Livewire::test(Configuration::class)
            ->assertForbidden();
    }

    public function test_non_admin_cannot_access_or_toggle_financial_storage(): void
    {
        $this->actingAs($this->guestUser);

        Livewire::test(Configuration::class)
            ->assertForbidden();
    }

    public function test_admin_sees_financial_storage_card_and_default_enabled_status(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(Configuration::class)
            ->assertOk()
            ->assertSee('Financial Storage & Caching Control')
            ->assertSee('ENABLED (Storage & Cache Active)')
            ->assertSee('Disable Financial Storage & Caching');
    }

    public function test_admin_can_toggle_financial_storage_to_disabled(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(Configuration::class)
            ->call('toggleFinancialStorage')
            ->assertSee('Financial statement storage and caching has been DISABLED application-wide')
            ->assertSee('DISABLED (Direct Upstream Bypass)')
            ->assertSee('Enable Financial Storage & Caching');

        $this->assertDatabaseHas('settings', [
            'key' => FinancialStorageConfigService::SETTING_KEY,
            'value' => 'false',
        ]);

        $service = app(FinancialStorageConfigService::class);
        $this->assertFalse($service->isEnabled());
    }

    public function test_admin_can_toggle_financial_storage_back_to_enabled(): void
    {
        $this->actingAs($this->adminUser);

        // Pre-set to disabled
        app(FinancialStorageConfigService::class)->disable();

        Livewire::test(Configuration::class)
            ->assertSee('DISABLED (Direct Upstream Bypass)')
            ->call('toggleFinancialStorage')
            ->assertSee('Financial statement storage and caching has been ENABLED application-wide')
            ->assertSee('ENABLED (Storage & Cache Active)')
            ->assertSee('Disable Financial Storage & Caching');

        $this->assertDatabaseHas('settings', [
            'key' => FinancialStorageConfigService::SETTING_KEY,
            'value' => 'true',
        ]);

        $service = app(FinancialStorageConfigService::class);
        $this->assertTrue($service->isEnabled());
    }

    public function test_admin_can_dismiss_financial_storage_status_alert(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(Configuration::class)
            ->call('toggleFinancialStorage')
            ->assertSee('Financial statement storage and caching has been DISABLED')
            ->call('dismissFinancialStorageStatus')
            ->assertDontSee('Financial statement storage and caching has been DISABLED');
    }

    public function test_admin_can_navigate_to_storage_tab_and_see_metrics_and_controls(): void
    {
        $this->actingAs($this->adminUser);

        // Create sample company financial record
        CompanyFinancial::create([
            'symbol' => 'AAPL',
            'statement_type' => CompanyFinancial::TYPE_INCOME,
            'fiscal_date' => '2026-06-30',
            'period' => 'Q2',
            'revenue' => 85000000000,
            'net_income' => 20000000000,
            'eps' => 1.40,
            'retrieved_at' => now(),
        ]);

        Livewire::test(Configuration::class)
            ->call('setTab', 'storage')
            ->assertSet('tab', 'storage')
            ->assertSee('Market Data Storage & Caching Control')
            ->assertSee('Non-Destructive Debugging & Comparative Analysis')
            ->assertSee('Income Statements')
            ->assertSee('Balance Sheets')
            ->assertSee('Cash Flow Statements')
            ->call('toggleFinancialStorage')
            ->assertSee('DISABLED (Direct Upstream Bypass)');

        $this->assertFalse(app(FinancialStorageConfigService::class)->isEnabled());

        // Existing company financial record is still preserved in DB
        $this->assertDatabaseHas('company_financials', [
            'symbol' => 'AAPL',
            'statement_type' => CompanyFinancial::TYPE_INCOME,
        ]);
    }
}
