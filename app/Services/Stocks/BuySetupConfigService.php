<?php

namespace App\Services\Stocks;

use App\Models\Setting;
use Illuminate\Support\Str;

class BuySetupConfigService
{
    public const SETTING_KEY = 'buy_setup_config';

    /**
     * Default configuration matching the initial specification.
     */
    public const DEFAULT_CONFIG = [
        'scanner_enabled' => true,
        'min_setup_score' => 0,
        'notify_min_setup_score' => 50,
        'min_heartbeat_score' => 50,
        'min_market_cap' => 100000000,
        'max_symbols' => 4000,
        'exchanges' => ['NYSE', 'NASDAQ', 'TSX', 'TSXV', 'AMEX', 'OTC'],
        'history_lookback_days' => 504,
        'benchmark_symbols' => ['SPY', 'IWM'],
        'notification_email' => 'j@7pro.ca',
        'setup_types' => [
            'heartbeat_consolidation_spike' => [
                'key' => 'heartbeat_consolidation_spike',
                'label' => 'Heartbeat consolidation + spike',
                'enabled' => true,
                'recent_spike_window_days' => 60,
                'max_spike_age_days' => 84,
                'min_base_days' => 45,
                'max_base_days' => 120,
                'max_range_compression_pct' => 40.0,
                'max_atr_ratio' => 0.85,
                'sleepy_volume_large_cap_penalty_pct' => 40.0,
                'sleepy_volume_medium_cap_penalty_pct' => 30.0,
                'sleepy_volume_small_cap_penalty_pct' => 20.0,
                'sleepy_volume_micro_cap_penalty_pct' => 15.0,
                'score_weights' => [
                    'spike_rarity' => ['weight' => 25, 'enabled' => true],
                    'base_duration' => ['weight' => 10, 'enabled' => true],
                    'range_compression' => ['weight' => 15, 'enabled' => true],
                    'atr_contraction' => ['weight' => 10, 'enabled' => true],
                    'volume_dry_up' => ['weight' => 10, 'enabled' => true],
                    'breakout_distance' => ['weight' => 10, 'enabled' => true],
                    'ma_alignment' => ['weight' => 10, 'enabled' => true],
                    'relative_strength' => ['weight' => 10, 'enabled' => true],
                    'earnings_acceleration' => ['weight' => 5, 'enabled' => true],
                    'sales_acceleration' => ['weight' => 5, 'enabled' => true],
                ],
            ],
            'range_compression_breakout' => [
                'key' => 'range_compression_breakout',
                'label' => 'Range compression breakout',
                'enabled' => false,
                'recent_spike_window_days' => 60,
                'max_spike_age_days' => 84,
                'min_base_days' => 45,
                'max_base_days' => 120,
                'max_range_compression_pct' => 40.0,
                'max_atr_ratio' => 0.85,
                'sleepy_volume_large_cap_penalty_pct' => 40.0,
                'sleepy_volume_medium_cap_penalty_pct' => 30.0,
                'sleepy_volume_small_cap_penalty_pct' => 20.0,
                'sleepy_volume_micro_cap_penalty_pct' => 15.0,
                'score_weights' => [
                    'spike_rarity' => ['weight' => 25, 'enabled' => true],
                    'base_duration' => ['weight' => 10, 'enabled' => true],
                    'range_compression' => ['weight' => 15, 'enabled' => true],
                    'atr_contraction' => ['weight' => 10, 'enabled' => true],
                    'volume_dry_up' => ['weight' => 10, 'enabled' => true],
                    'breakout_distance' => ['weight' => 10, 'enabled' => true],
                    'ma_alignment' => ['weight' => 10, 'enabled' => true],
                    'relative_strength' => ['weight' => 10, 'enabled' => true],
                    'earnings_acceleration' => ['weight' => 5, 'enabled' => true],
                    'sales_acceleration' => ['weight' => 5, 'enabled' => true],
                ],
            ],
            'floor_reversal_accumulation' => [
                'key' => 'floor_reversal_accumulation',
                'label' => 'Floor reversal / accumulation',
                'enabled' => false,
                'recent_spike_window_days' => 60,
                'max_spike_age_days' => 84,
                'min_base_days' => 45,
                'max_base_days' => 120,
                'max_range_compression_pct' => 40.0,
                'max_atr_ratio' => 0.85,
                'sleepy_volume_large_cap_penalty_pct' => 40.0,
                'sleepy_volume_medium_cap_penalty_pct' => 30.0,
                'sleepy_volume_small_cap_penalty_pct' => 20.0,
                'sleepy_volume_micro_cap_penalty_pct' => 15.0,
                'score_weights' => [
                    'spike_rarity' => ['weight' => 25, 'enabled' => true],
                    'base_duration' => ['weight' => 10, 'enabled' => true],
                    'range_compression' => ['weight' => 15, 'enabled' => true],
                    'atr_contraction' => ['weight' => 10, 'enabled' => true],
                    'volume_dry_up' => ['weight' => 10, 'enabled' => true],
                    'breakout_distance' => ['weight' => 10, 'enabled' => true],
                    'ma_alignment' => ['weight' => 10, 'enabled' => true],
                    'relative_strength' => ['weight' => 10, 'enabled' => true],
                    'earnings_acceleration' => ['weight' => 5, 'enabled' => true],
                    'sales_acceleration' => ['weight' => 5, 'enabled' => true],
                ],
            ],
            'early_breakout_followthrough' => [
                'key' => 'early_breakout_followthrough',
                'label' => 'Early breakout follow-through',
                'enabled' => false,
                'recent_spike_window_days' => 60,
                'max_spike_age_days' => 84,
                'min_base_days' => 45,
                'max_base_days' => 120,
                'max_range_compression_pct' => 40.0,
                'max_atr_ratio' => 0.85,
                'sleepy_volume_large_cap_penalty_pct' => 40.0,
                'sleepy_volume_medium_cap_penalty_pct' => 30.0,
                'sleepy_volume_small_cap_penalty_pct' => 20.0,
                'sleepy_volume_micro_cap_penalty_pct' => 15.0,
                'score_weights' => [
                    'spike_rarity' => ['weight' => 25, 'enabled' => true],
                    'base_duration' => ['weight' => 10, 'enabled' => true],
                    'range_compression' => ['weight' => 15, 'enabled' => true],
                    'atr_contraction' => ['weight' => 10, 'enabled' => true],
                    'volume_dry_up' => ['weight' => 10, 'enabled' => true],
                    'breakout_distance' => ['weight' => 10, 'enabled' => true],
                    'ma_alignment' => ['weight' => 10, 'enabled' => true],
                    'relative_strength' => ['weight' => 10, 'enabled' => true],
                    'earnings_acceleration' => ['weight' => 5, 'enabled' => true],
                    'sales_acceleration' => ['weight' => 5, 'enabled' => true],
                ],
            ],
        ],
    ];

    /**
     * Get the full configuration array.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        try {
            $setting = Setting::query()->where('key', self::SETTING_KEY)->first();
            if ($setting && ! empty($setting->value)) {
                $saved = json_decode($setting->value, true);
                if (is_array($saved)) {
                    return $this->mergeWithDefaults($saved);
                }
            }
        } catch (\Throwable) {
            // Fall back to defaults if DB is unavailable during migrations or tests
        }

        return $this->mergeWithDefaults([]);
    }

    /**
     * Save configuration array to settings table and runtime config.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function saveConfig(array $config): array
    {
        $normalized = $this->sanitizeConfig($config);

        Setting::updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => json_encode($normalized)]
        );

        $this->syncToRuntimeConfig($normalized);

        return $normalized;
    }

    /**
     * Reset configuration to default template.
     *
     * @return array<string, mixed>
     */
    public function resetToDefaults(): array
    {
        Setting::query()->where('key', self::SETTING_KEY)->delete();
        $defaults = self::DEFAULT_CONFIG;
        $this->syncToRuntimeConfig($defaults);

        return $defaults;
    }

    public function isScannerEnabled(): bool
    {
        return (bool) ($this->getConfig()['scanner_enabled'] ?? true);
    }

    public function getMinSetupScore(): int
    {
        return (int) ($this->getConfig()['min_setup_score'] ?? 0);
    }

    public function getNotifyMinSetupScore(): int
    {
        return (int) ($this->getConfig()['notify_min_setup_score'] ?? 50);
    }

    public function getMinHeartbeatScore(): int
    {
        return (int) ($this->getConfig()['min_heartbeat_score'] ?? 50);
    }

    public function getMinMarketCap(): int
    {
        return (int) ($this->getConfig()['min_market_cap'] ?? 100000000);
    }

    public function getMaxSymbols(): int
    {
        return (int) ($this->getConfig()['max_symbols'] ?? 4000);
    }

    /**
     * @return array<int, string>
     */
    public function getExchanges(): array
    {
        $exchanges = $this->getConfig()['exchanges'] ?? ['NYSE', 'NASDAQ', 'TSX', 'TSXV', 'AMEX', 'OTC'];
        if (is_string($exchanges)) {
            $exchanges = explode(',', $exchanges);
        }

        return array_values(array_filter(array_map('trim', (array) $exchanges)));
    }

    public function getHistoryLookbackDays(): int
    {
        return (int) ($this->getConfig()['history_lookback_days'] ?? 504);
    }

    /**
     * @return array<int, string>
     */
    public function getBenchmarkSymbols(): array
    {
        $benchmarks = $this->getConfig()['benchmark_symbols'] ?? ['SPY', 'IWM'];
        if (is_string($benchmarks)) {
            $benchmarks = explode(',', $benchmarks);
        }

        return array_values(array_filter(array_map('trim', (array) $benchmarks)));
    }

    public function getNotificationEmail(): ?string
    {
        $email = $this->getConfig()['notification_email'] ?? null;

        return ! empty($email) ? trim((string) $email) : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getSetupTypes(): array
    {
        return (array) ($this->getConfig()['setup_types'] ?? []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSetupType(?string $key): ?array
    {
        if ($key === null) {
            $key = 'heartbeat_consolidation_spike';
        }

        $types = $this->getSetupTypes();

        return $types[$key] ?? ($types['heartbeat_consolidation_spike'] ?? null);
    }

    /**
     * Returns effective component weights (0 for disabled components).
     *
     * @return array<string, int>
     */
    public function getScoreWeights(?string $setupType = null): array
    {
        $meta = $this->getScoreWeightsMeta($setupType);

        $weights = [];
        foreach ($meta as $key => $data) {
            $enabled = (bool) ($data['enabled'] ?? true);
            $weight = (int) ($data['weight'] ?? 0);
            $weights[$key] = $enabled ? max(0, $weight) : 0;
        }

        return $weights;
    }

    /**
     * Returns component weights with their enabled status.
     *
     * @return array<string, array{weight: int, enabled: bool}>
     */
    public function getScoreWeightsMeta(?string $setupType = null): array
    {
        $type = $this->getSetupType($setupType);
        $defaultWeights = self::DEFAULT_CONFIG['setup_types']['heartbeat_consolidation_spike']['score_weights'];

        if (! isset($type['score_weights']) || ! is_array($type['score_weights'])) {
            return $defaultWeights;
        }

        $weights = [];
        foreach ($defaultWeights as $key => $default) {
            if (isset($type['score_weights'][$key])) {
                $item = $type['score_weights'][$key];
                if (is_array($item)) {
                    $weights[$key] = [
                        'weight' => max(0, (int) ($item['weight'] ?? $default['weight'])),
                        'enabled' => (bool) ($item['enabled'] ?? true),
                    ];
                } else {
                    $weights[$key] = [
                        'weight' => max(0, (int) $item),
                        'enabled' => true,
                    ];
                }
            } else {
                $weights[$key] = $default;
            }
        }

        foreach ($type['score_weights'] as $key => $item) {
            if (! isset($weights[$key])) {
                if (is_array($item)) {
                    $weights[$key] = [
                        'weight' => max(0, (int) ($item['weight'] ?? 0)),
                        'enabled' => (bool) ($item['enabled'] ?? true),
                    ];
                } else {
                    $weights[$key] = [
                        'weight' => max(0, (int) $item),
                        'enabled' => true,
                    ];
                }
            }
        }

        return $weights;
    }

    /**
     * @return array<string, float|int>
     */
    public function getSleepyVolumePenalties(?string $setupType = null): array
    {
        $type = $this->getSetupType($setupType);

        return [
            'large' => (float) ($type['sleepy_volume_large_cap_penalty_pct'] ?? 40),
            'medium' => (float) ($type['sleepy_volume_medium_cap_penalty_pct'] ?? 30),
            'small' => (float) ($type['sleepy_volume_small_cap_penalty_pct'] ?? 20),
            'micro' => (float) ($type['sleepy_volume_micro_cap_penalty_pct'] ?? 15),
        ];
    }

    /**
     * Default technical thresholds for a setup type.
     *
     * @return array<string, mixed>
     */
    public function createDefaultSetupType(string $key, string $label): array
    {
        $defaultType = self::DEFAULT_CONFIG['setup_types']['heartbeat_consolidation_spike'];

        return [
            'key' => Str::slug($key, '_'),
            'label' => $label,
            'enabled' => true,
            'recent_spike_window_days' => $defaultType['recent_spike_window_days'],
            'max_spike_age_days' => $defaultType['max_spike_age_days'],
            'min_base_days' => $defaultType['min_base_days'],
            'max_base_days' => $defaultType['max_base_days'],
            'max_range_compression_pct' => $defaultType['max_range_compression_pct'],
            'max_atr_ratio' => $defaultType['max_atr_ratio'],
            'sleepy_volume_large_cap_penalty_pct' => $defaultType['sleepy_volume_large_cap_penalty_pct'],
            'sleepy_volume_medium_cap_penalty_pct' => $defaultType['sleepy_volume_medium_cap_penalty_pct'],
            'sleepy_volume_small_cap_penalty_pct' => $defaultType['sleepy_volume_small_cap_penalty_pct'],
            'sleepy_volume_micro_cap_penalty_pct' => $defaultType['sleepy_volume_micro_cap_penalty_pct'],
            'score_weights' => $defaultType['score_weights'],
        ];
    }

    /**
     * Merge saved config with defaults and env configurations.
     *
     * @param  array<string, mixed>  $saved
     * @return array<string, mixed>
     */
    private function mergeWithDefaults(array $saved): array
    {
        $defaults = self::DEFAULT_CONFIG;
        $isConfigBound = function_exists('config')
            && \Illuminate\Container\Container::getInstance()
            && \Illuminate\Container\Container::getInstance()->bound('config');

        $cfg = fn (string $key, mixed $default) => $isConfigBound ? config($key, $default) : $default;

        // Fallback to config/env if saved has missing global fields
        $config = [
            'scanner_enabled' => (bool) ($saved['scanner_enabled'] ?? $cfg('market_data.buy_setup_scanner.enabled', $defaults['scanner_enabled'])),
            'min_setup_score' => (int) ($saved['min_setup_score'] ?? $cfg('market_data.buy_setup_scanner.min_setup_score', $defaults['min_setup_score'])),
            'notify_min_setup_score' => (int) ($saved['notify_min_setup_score'] ?? $cfg('market_data.buy_setup_scanner.notify_min_setup_score', $defaults['notify_min_setup_score'])),
            'min_heartbeat_score' => (int) ($saved['min_heartbeat_score'] ?? $cfg('market_data.buy_setup_scanner.min_heartbeat_score', $defaults['min_heartbeat_score'])),
            'min_market_cap' => (int) ($saved['min_market_cap'] ?? $cfg('market_data.buy_setup_scanner.min_market_cap', $defaults['min_market_cap'])),
            'max_symbols' => (int) ($saved['max_symbols'] ?? $cfg('market_data.buy_setup_scanner.max_symbols_per_run', $defaults['max_symbols'])),
            'exchanges' => ! empty($saved['exchanges']) ? (array) $saved['exchanges'] : (array) $cfg('market_data.buy_setup_scanner.exchanges', $defaults['exchanges']),
            'history_lookback_days' => (int) ($saved['history_lookback_days'] ?? $cfg('market_data.buy_setup_scanner.history_lookback_days', $defaults['history_lookback_days'])),
            'benchmark_symbols' => ! empty($saved['benchmark_symbols']) ? (array) $saved['benchmark_symbols'] : (array) $cfg('market_data.buy_setup_scanner.benchmark_symbols', $defaults['benchmark_symbols']),
            'notification_email' => $saved['notification_email'] ?? $cfg('market_data.buy_setup_scanner.notification_email', $defaults['notification_email']),
            'setup_types' => [],
        ];

        // Format exchanges and benchmark_symbols as arrays
        if (is_string($config['exchanges'])) {
            $config['exchanges'] = array_values(array_filter(array_map('trim', explode(',', $config['exchanges']))));
        }
        if (is_string($config['benchmark_symbols'])) {
            $config['benchmark_symbols'] = array_values(array_filter(array_map('trim', explode(',', $config['benchmark_symbols']))));
        }

        $savedTypes = (array) ($saved['setup_types'] ?? []);
        $defaultTypes = $defaults['setup_types'];

        if (! empty($savedTypes)) {
            foreach ($savedTypes as $key => $savedType) {
                if (is_array($savedType)) {
                    $defaultType = $defaultTypes[$key] ?? $defaultTypes['heartbeat_consolidation_spike'];
                    $config['setup_types'][$key] = $this->mergeSetupType($key, $savedType, $defaultType);
                }
            }
            // Ensure heartbeat_consolidation_spike always exists
            if (! isset($config['setup_types']['heartbeat_consolidation_spike'])) {
                $config['setup_types']['heartbeat_consolidation_spike'] = $defaultTypes['heartbeat_consolidation_spike'];
            }
        } else {
            foreach ($defaultTypes as $key => $defaultType) {
                $config['setup_types'][$key] = $defaultType;
            }
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $saved
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    private function mergeSetupType(string $key, array $saved, array $default): array
    {
        $weights = [];
        $savedWeights = (array) ($saved['score_weights'] ?? []);
        foreach ($default['score_weights'] as $wKey => $wDefault) {
            if (isset($savedWeights[$wKey])) {
                $val = $savedWeights[$wKey];
                $weights[$wKey] = [
                    'weight' => max(0, (int) (is_array($val) ? ($val['weight'] ?? $wDefault['weight']) : $val)),
                    'enabled' => is_array($val) ? (bool) ($val['enabled'] ?? true) : true,
                ];
            } else {
                $weights[$wKey] = $wDefault;
            }
        }

        foreach ($savedWeights as $wKey => $val) {
            if (! isset($weights[$wKey])) {
                $weights[$wKey] = [
                    'weight' => max(0, (int) (is_array($val) ? ($val['weight'] ?? 0) : $val)),
                    'enabled' => is_array($val) ? (bool) ($val['enabled'] ?? true) : true,
                ];
            }
        }

        return [
            'key' => $key,
            'label' => (string) ($saved['label'] ?? $default['label'] ?? $key),
            'enabled' => (bool) ($saved['enabled'] ?? $default['enabled'] ?? false),
            'recent_spike_window_days' => (int) ($saved['recent_spike_window_days'] ?? $default['recent_spike_window_days']),
            'max_spike_age_days' => (int) ($saved['max_spike_age_days'] ?? $default['max_spike_age_days']),
            'min_base_days' => (int) ($saved['min_base_days'] ?? $default['min_base_days']),
            'max_base_days' => (int) ($saved['max_base_days'] ?? $default['max_base_days']),
            'max_range_compression_pct' => (float) ($saved['max_range_compression_pct'] ?? $default['max_range_compression_pct']),
            'max_atr_ratio' => (float) ($saved['max_atr_ratio'] ?? $default['max_atr_ratio']),
            'sleepy_volume_large_cap_penalty_pct' => (float) ($saved['sleepy_volume_large_cap_penalty_pct'] ?? $default['sleepy_volume_large_cap_penalty_pct']),
            'sleepy_volume_medium_cap_penalty_pct' => (float) ($saved['sleepy_volume_medium_cap_penalty_pct'] ?? $default['sleepy_volume_medium_cap_penalty_pct']),
            'sleepy_volume_small_cap_penalty_pct' => (float) ($saved['sleepy_volume_small_cap_penalty_pct'] ?? $default['sleepy_volume_small_cap_penalty_pct']),
            'sleepy_volume_micro_cap_penalty_pct' => (float) ($saved['sleepy_volume_micro_cap_penalty_pct'] ?? $default['sleepy_volume_micro_cap_penalty_pct']),
            'score_weights' => $weights,
        ];
    }

    /**
     * Sanitize input config before saving.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function sanitizeConfig(array $config): array
    {
        $sanitized = $this->mergeWithDefaults($config);

        // Additional normalization
        if (is_string($sanitized['exchanges'])) {
            $sanitized['exchanges'] = array_values(array_filter(array_map('trim', explode(',', $sanitized['exchanges']))));
        }
        if (is_string($sanitized['benchmark_symbols'])) {
            $sanitized['benchmark_symbols'] = array_values(array_filter(array_map('trim', explode(',', $sanitized['benchmark_symbols']))));
        }

        return $sanitized;
    }

    /**
     * Synchronize config changes to Laravel runtime config.
     *
     * @param  array<string, mixed>  $config
     */
    private function syncToRuntimeConfig(array $config): void
    {
        $isConfigBound = function_exists('config')
            && \Illuminate\Container\Container::getInstance()
            && \Illuminate\Container\Container::getInstance()->bound('config');

        if (! $isConfigBound) {
            return;
        }

        $setupTypesConfig = [];
        foreach ($config['setup_types'] as $key => $type) {
            $setupTypesConfig[$key] = [
                'enabled' => (bool) $type['enabled'],
                'label' => $type['label'],
            ];
        }

        $defaultType = $config['setup_types']['heartbeat_consolidation_spike'] ?? reset($config['setup_types']);

        $weights = [];
        if ($defaultType && isset($defaultType['score_weights'])) {
            foreach ($defaultType['score_weights'] as $k => $w) {
                $weights[$k] = (bool) ($w['enabled'] ?? true) ? (int) ($w['weight'] ?? 0) : 0;
            }
        }

        config([
            'market_data.buy_setup_scanner.enabled' => (bool) $config['scanner_enabled'],
            'market_data.buy_setup_scanner.min_setup_score' => (int) $config['min_setup_score'],
            'market_data.buy_setup_scanner.notify_min_setup_score' => (int) $config['notify_min_setup_score'],
            'market_data.buy_setup_scanner.min_heartbeat_score' => (int) $config['min_heartbeat_score'],
            'market_data.buy_setup_scanner.min_market_cap' => (int) $config['min_market_cap'],
            'market_data.buy_setup_scanner.max_symbols_per_run' => (int) $config['max_symbols'],
            'market_data.buy_setup_scanner.exchanges' => (array) $config['exchanges'],
            'market_data.buy_setup_scanner.history_lookback_days' => (int) $config['history_lookback_days'],
            'market_data.buy_setup_scanner.benchmark_symbols' => (array) $config['benchmark_symbols'],
            'market_data.buy_setup_scanner.notification_email' => $config['notification_email'],
            'market_data.buy_setup_scanner.setup_types' => $setupTypesConfig,
            'market_data.buy_setup_scanner.score_weights' => $weights,
        ]);
    }
}
