<?php

namespace App\Services\MarketData;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;

class FinancialStorageConfigService
{
    public const SETTING_KEY = 'financial_storage_enabled';

    /**
     * Optional memory override for unit tests.
     */
    protected ?bool $testOverride = null;

    /**
     * Determine whether financial statement storage and caching is enabled application-wide.
     */
    public function isEnabled(): bool
    {
        if ($this->testOverride !== null) {
            return $this->testOverride;
        }

        try {
            $setting = Setting::query()->where('key', self::SETTING_KEY)->first();
            if ($setting !== null) {
                return filter_var($setting->value, FILTER_VALIDATE_BOOLEAN);
            }
        } catch (\Throwable $e) {
            Log::warning('financial_storage_config.read_failed', ['error' => $e->getMessage()]);
        }

        return (bool) config('market_data.financial_storage_enabled', true);
    }

    /**
     * Determine whether financial statement storage and caching is disabled.
     */
    public function isDisabled(): bool
    {
        return ! $this->isEnabled();
    }

    /**
     * Enable or disable financial statement storage and caching application-wide.
     * Non-destructive: this only adjusts the active setting and preserves all existing database records.
     */
    public function setEnabled(bool $enabled): void
    {
        $this->testOverride = null;

        Setting::updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => $enabled ? 'true' : 'false']
        );

        Log::info('financial_storage_config.updated', [
            'enabled' => $enabled,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Enable financial statement storage and caching.
     */
    public function enable(): void
    {
        $this->setEnabled(true);
    }

    /**
     * Disable financial statement storage and caching.
     */
    public function disable(): void
    {
        $this->setEnabled(false);
    }

    /**
     * Toggle the current setting between enabled and disabled.
     *
     * @return bool The newly active state.
     */
    public function toggle(): bool
    {
        $newState = ! $this->isEnabled();
        $this->setEnabled($newState);

        return $newState;
    }

    /**
     * Reset the configuration to fallback to the default config value.
     */
    public function resetToDefault(): void
    {
        $this->testOverride = null;
        Setting::query()->where('key', self::SETTING_KEY)->delete();

        Log::info('financial_storage_config.reset_to_default');
    }

    /**
     * Override the setting in-memory for testing purposes.
     */
    public function overrideForTesting(?bool $override): void
    {
        $this->testOverride = $override;
    }
}
