<?php

namespace Tests\Unit\MarketData;

use App\Services\MarketData\FinancialStorageConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialStorageConfigServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_to_true_when_no_setting_is_persisted(): void
    {
        $service = new FinancialStorageConfigService;

        $this->assertTrue($service->isEnabled());
        $this->assertFalse($service->isDisabled());
    }

    public function test_can_disable_and_persist_to_settings_table(): void
    {
        $service = new FinancialStorageConfigService;

        $service->disable();

        $this->assertFalse($service->isEnabled());
        $this->assertTrue($service->isDisabled());

        $this->assertDatabaseHas('settings', [
            'key' => FinancialStorageConfigService::SETTING_KEY,
            'value' => 'false',
        ]);
    }

    public function test_can_enable_and_persist_to_settings_table(): void
    {
        $service = new FinancialStorageConfigService;

        $service->disable();
        $this->assertFalse($service->isEnabled());

        $service->enable();
        $this->assertTrue($service->isEnabled());

        $this->assertDatabaseHas('settings', [
            'key' => FinancialStorageConfigService::SETTING_KEY,
            'value' => 'true',
        ]);
    }

    public function test_can_toggle_between_enabled_and_disabled(): void
    {
        $service = new FinancialStorageConfigService;

        // Defaults to true, toggle to false
        $newVal = $service->toggle();
        $this->assertFalse($newVal);
        $this->assertFalse($service->isEnabled());

        // Toggle back to true
        $newVal2 = $service->toggle();
        $this->assertTrue($newVal2);
        $this->assertTrue($service->isEnabled());
    }

    public function test_reset_to_default_removes_persisted_setting(): void
    {
        $service = new FinancialStorageConfigService;

        $service->disable();
        $this->assertDatabaseHas('settings', ['key' => FinancialStorageConfigService::SETTING_KEY]);

        $service->resetToDefault();
        $this->assertDatabaseMissing('settings', ['key' => FinancialStorageConfigService::SETTING_KEY]);
        $this->assertTrue($service->isEnabled());
    }

    public function test_falls_back_to_config_when_setting_is_missing(): void
    {
        config(['market_data.financial_storage_enabled' => false]);
        $service = new FinancialStorageConfigService;

        $this->assertFalse($service->isEnabled());

        config(['market_data.financial_storage_enabled' => true]);
        $this->assertTrue($service->isEnabled());
    }
}
