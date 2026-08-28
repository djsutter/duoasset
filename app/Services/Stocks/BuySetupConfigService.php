<?php

namespace App\Services\Stocks;

use App\Models\Setting;
use Illuminate\Support\Str;

class BuySetupConfigService
{
    public const SETTING_KEY = 'buy_setup_config';

    /**
     * Default Operating Margin Expansion (TTM YoY) threshold interpolation
     * points, expressed in basis points. See ThresholdInterpolationScorer.
     */
    public const DEFAULT_OPERATING_MARGIN_EXPANSION_THRESHOLDS = [
        'threshold_25' => 250,
        'threshold_50' => 500,
        'threshold_75' => 1000,
        'threshold_100' => 1500,
    ];

    /**
     * Default Free Cash Flow Margin Expansion (TTM YoY) threshold
     * interpolation points, expressed in basis points. Mirrors Operating
     * Margin Expansion. See ThresholdInterpolationScorer.
     */
    public const DEFAULT_FCF_MARGIN_EXPANSION_THRESHOLDS = [
        'threshold_25' => 250,
        'threshold_50' => 500,
        'threshold_75' => 1000,
        'threshold_100' => 1500,
    ];

    /**
     * Default Growth Synergy Bonus configuration. Disabled by default so
     * existing setup scores never change unless a user explicitly opts in.
     * See StockBuySetupScorer::growthSynergyBonus().
     */
    public const DEFAULT_GROWTH_SYNERGY_BONUS = [
        'enabled' => false,
        'max_points' => 10,
        'min_sales_yoy' => 20,
        'medium_threshold' => 50,
        'strong_threshold' => 75,
        'exceptional_threshold' => 90,
    ];

    /**
     * Default per-setup-type market-cap eligibility range, in whole
     * dollars. Applied to existing setup types missing this setting and
     * to newly created setup types.
     */
    public const DEFAULT_MIN_MARKET_CAP = 50000000;

    public const DEFAULT_MAX_MARKET_CAP = 1000000000000;

    /**
     * Default configuration matching the initial specification.
     */
    public const DEFAULT_CONFIG = [
        'scanner_enabled' => true,
        'min_setup_score' => 0,
        'notify_min_setup_score' => 50,
        'min_heartbeat_score' => 50,
        'min_market_cap' => 100000000,
        'max_market_cap' => 1000000000000,
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
                'prior_year_revenue_penalties' => [
                    ['threshold' => 100000, 'penalty_pct' => 25],
                ],
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
                    'operating_margin_expansion' => ['weight' => 10, 'enabled' => false],
                    'fcf_margin_expansion' => ['weight' => 10, 'enabled' => false],
                ],
                'operating_margin_expansion_thresholds' => self::DEFAULT_OPERATING_MARGIN_EXPANSION_THRESHOLDS,
                'fcf_margin_expansion_thresholds' => self::DEFAULT_FCF_MARGIN_EXPANSION_THRESHOLDS,
                'growth_synergy_bonus' => self::DEFAULT_GROWTH_SYNERGY_BONUS,
                'min_market_cap' => self::DEFAULT_MIN_MARKET_CAP,
                'max_market_cap' => self::DEFAULT_MAX_MARKET_CAP,
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
                'prior_year_revenue_penalties' => [
                    ['threshold' => 100000, 'penalty_pct' => 25],
                ],
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
                    'operating_margin_expansion' => ['weight' => 10, 'enabled' => false],
                    'fcf_margin_expansion' => ['weight' => 10, 'enabled' => false],
                ],
                'operating_margin_expansion_thresholds' => self::DEFAULT_OPERATING_MARGIN_EXPANSION_THRESHOLDS,
                'fcf_margin_expansion_thresholds' => self::DEFAULT_FCF_MARGIN_EXPANSION_THRESHOLDS,
                'growth_synergy_bonus' => self::DEFAULT_GROWTH_SYNERGY_BONUS,
                'min_market_cap' => self::DEFAULT_MIN_MARKET_CAP,
                'max_market_cap' => self::DEFAULT_MAX_MARKET_CAP,
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
                'prior_year_revenue_penalties' => [
                    ['threshold' => 100000, 'penalty_pct' => 25],
                ],
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
                    'operating_margin_expansion' => ['weight' => 10, 'enabled' => false],
                    'fcf_margin_expansion' => ['weight' => 10, 'enabled' => false],
                ],
                'operating_margin_expansion_thresholds' => self::DEFAULT_OPERATING_MARGIN_EXPANSION_THRESHOLDS,
                'fcf_margin_expansion_thresholds' => self::DEFAULT_FCF_MARGIN_EXPANSION_THRESHOLDS,
                'growth_synergy_bonus' => self::DEFAULT_GROWTH_SYNERGY_BONUS,
                'min_market_cap' => self::DEFAULT_MIN_MARKET_CAP,
                'max_market_cap' => self::DEFAULT_MAX_MARKET_CAP,
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
                'prior_year_revenue_penalties' => [
                    ['threshold' => 100000, 'penalty_pct' => 25],
                ],
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
                    'operating_margin_expansion' => ['weight' => 10, 'enabled' => false],
                    'fcf_margin_expansion' => ['weight' => 10, 'enabled' => false],
                ],
                'operating_margin_expansion_thresholds' => self::DEFAULT_OPERATING_MARGIN_EXPANSION_THRESHOLDS,
                'fcf_margin_expansion_thresholds' => self::DEFAULT_FCF_MARGIN_EXPANSION_THRESHOLDS,
                'growth_synergy_bonus' => self::DEFAULT_GROWTH_SYNERGY_BONUS,
                'min_market_cap' => self::DEFAULT_MIN_MARKET_CAP,
                'max_market_cap' => self::DEFAULT_MAX_MARKET_CAP,
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

    public function getMaxMarketCap(): int
    {
        return (int) ($this->getConfig()['max_market_cap'] ?? self::DEFAULT_MAX_MARKET_CAP);
    }

    /**
     * Market-cap eligibility range for the given setup type, in whole
     * dollars. Falls back to the global scanner defaults (config/env)
     * when a setup type predates this setting.
     *
     * @return array{min: int, max: int}
     */
    public function getSetupMarketCapRange(?string $setupType = null): array
    {
        $type = $this->getSetupType($setupType);

        $min = isset($type['min_market_cap']) && is_numeric($type['min_market_cap'])
            ? (int) $type['min_market_cap']
            : $this->getMinMarketCap();
        $max = isset($type['max_market_cap']) && is_numeric($type['max_market_cap'])
            ? (int) $type['max_market_cap']
            : $this->getMaxMarketCap();

        if ($min < 0 || $max <= 0 || $min > $max) {
            return [
                'min' => self::DEFAULT_MIN_MARKET_CAP,
                'max' => self::DEFAULT_MAX_MARKET_CAP,
            ];
        }

        return ['min' => $min, 'max' => $max];
    }

    /**
     * Widened market-cap range spanning every enabled setup type: the
     * lowest configured minimum and the highest configured maximum.
     *
     * Intended for the scanner's upstream FMP company-screener query so
     * candidate stocks are never excluded before per-setup eligibility
     * (min_market_cap / max_market_cap) is evaluated for each setup type.
     *
     * @return array{min: int, max: int}
     */
    public function getEnabledSetupTypesMarketCapRange(): array
    {
        $min = null;
        $max = null;

        foreach ($this->getSetupTypes() as $key => $type) {
            if (! (bool) ($type['enabled'] ?? false)) {
                continue;
            }

            $range = $this->getSetupMarketCapRange($key);
            $min = $min === null ? $range['min'] : min($min, $range['min']);
            $max = $max === null ? $range['max'] : max($max, $range['max']);
        }

        return [
            'min' => $min ?? $this->getMinMarketCap(),
            'max' => $max ?? $this->getMaxMarketCap(),
        ];
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
     * @return array<int, array{threshold: float|int, penalty_pct: float|int}>
     */
    public function getPriorYearRevenuePenalties(?string $setupType = null): array
    {
        $type = $this->getSetupType($setupType);
        $defaultPenalties = self::DEFAULT_CONFIG['setup_types']['heartbeat_consolidation_spike']['prior_year_revenue_penalties'];

        $penalties = $type['prior_year_revenue_penalties'] ?? $defaultPenalties;

        if (! is_array($penalties)) {
            return [];
        }

        // Sort ascending by threshold
        usort($penalties, fn ($a, $b) => ((float) ($a['threshold'] ?? 0)) <=> ((float) ($b['threshold'] ?? 0)));

        return array_values($penalties);
    }

    /**
     * Operating Margin Expansion (TTM YoY) score interpolation thresholds,
     * in basis points, for the given setup type.
     *
     * @return array{threshold_25: int, threshold_50: int, threshold_75: int, threshold_100: int}
     */
    public function getOperatingMarginExpansionThresholds(?string $setupType = null): array
    {
        $type = $this->getSetupType($setupType);
        $default = self::DEFAULT_OPERATING_MARGIN_EXPANSION_THRESHOLDS;
        $thresholds = $type['operating_margin_expansion_thresholds'] ?? $default;

        if (! is_array($thresholds)) {
            return $default;
        }

        return [
            'threshold_25' => (int) ($thresholds['threshold_25'] ?? $default['threshold_25']),
            'threshold_50' => (int) ($thresholds['threshold_50'] ?? $default['threshold_50']),
            'threshold_75' => (int) ($thresholds['threshold_75'] ?? $default['threshold_75']),
            'threshold_100' => (int) ($thresholds['threshold_100'] ?? $default['threshold_100']),
        ];
    }

    /**
     * Free Cash Flow Margin Expansion (TTM YoY) score interpolation
     * thresholds, in basis points, for the given setup type.
     *
     * @return array{threshold_25: int, threshold_50: int, threshold_75: int, threshold_100: int}
     */
    public function getFcfMarginExpansionThresholds(?string $setupType = null): array
    {
        $type = $this->getSetupType($setupType);
        $default = self::DEFAULT_FCF_MARGIN_EXPANSION_THRESHOLDS;
        $thresholds = $type['fcf_margin_expansion_thresholds'] ?? $default;

        if (! is_array($thresholds)) {
            return $default;
        }

        return [
            'threshold_25' => (int) ($thresholds['threshold_25'] ?? $default['threshold_25']),
            'threshold_50' => (int) ($thresholds['threshold_50'] ?? $default['threshold_50']),
            'threshold_75' => (int) ($thresholds['threshold_75'] ?? $default['threshold_75']),
            'threshold_100' => (int) ($thresholds['threshold_100'] ?? $default['threshold_100']),
        ];
    }

    /**
     * Growth Synergy Bonus configuration for the given setup type.
     *
     * @return array{enabled: bool, max_points: int, min_sales_yoy: float, medium_threshold: float, strong_threshold: float, exceptional_threshold: float}
     */
    public function getGrowthSynergyBonusConfig(?string $setupType = null): array
    {
        $type = $this->getSetupType($setupType);
        $default = self::DEFAULT_GROWTH_SYNERGY_BONUS;
        $bonus = $type['growth_synergy_bonus'] ?? $default;

        if (! is_array($bonus)) {
            return $default;
        }

        return [
            'enabled' => (bool) ($bonus['enabled'] ?? $default['enabled']),
            'max_points' => (int) ($bonus['max_points'] ?? $default['max_points']),
            'min_sales_yoy' => (float) ($bonus['min_sales_yoy'] ?? $default['min_sales_yoy']),
            'medium_threshold' => (float) ($bonus['medium_threshold'] ?? $default['medium_threshold']),
            'strong_threshold' => (float) ($bonus['strong_threshold'] ?? $default['strong_threshold']),
            'exceptional_threshold' => (float) ($bonus['exceptional_threshold'] ?? $default['exceptional_threshold']),
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
            'prior_year_revenue_penalties' => $defaultType['prior_year_revenue_penalties'],
            'score_weights' => $defaultType['score_weights'],
            'operating_margin_expansion_thresholds' => $defaultType['operating_margin_expansion_thresholds'],
            'fcf_margin_expansion_thresholds' => $defaultType['fcf_margin_expansion_thresholds'],
            'growth_synergy_bonus' => $defaultType['growth_synergy_bonus'],
            'min_market_cap' => $defaultType['min_market_cap'],
            'max_market_cap' => $defaultType['max_market_cap'],
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
            'max_market_cap' => (int) ($saved['max_market_cap'] ?? $cfg('market_data.buy_setup_scanner.max_market_cap', $defaults['max_market_cap'])),
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

        $penalties = [];
        if (isset($saved['prior_year_revenue_penalties']) && is_array($saved['prior_year_revenue_penalties'])) {
            foreach (array_slice($saved['prior_year_revenue_penalties'], 0, 10) as $item) {
                if (is_array($item)) {
                    $threshold = isset($item['threshold']) && is_numeric($item['threshold'])
                        ? max(0, (float) $item['threshold'])
                        : null;
                    $penaltyPct = isset($item['penalty_pct']) && is_numeric($item['penalty_pct'])
                        ? min(100, max(0, (float) $item['penalty_pct']))
                        : null;

                    if ($threshold !== null && $penaltyPct !== null) {
                        $penalties[] = [
                            'threshold' => $threshold,
                            'penalty_pct' => $penaltyPct,
                        ];
                    }
                }
            }
            usort($penalties, fn ($a, $b) => ((float) $a['threshold']) <=> ((float) $b['threshold']));
        } elseif (! isset($saved['prior_year_revenue_penalties'])) {
            $penalties = (array) ($default['prior_year_revenue_penalties'] ?? []);
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
            'prior_year_revenue_penalties' => $penalties,
            'score_weights' => $weights,
            'operating_margin_expansion_thresholds' => $this->mergeOperatingMarginExpansionThresholds(
                $saved['operating_margin_expansion_thresholds'] ?? null,
                (array) ($default['operating_margin_expansion_thresholds'] ?? self::DEFAULT_OPERATING_MARGIN_EXPANSION_THRESHOLDS),
            ),
            'fcf_margin_expansion_thresholds' => $this->mergeOperatingMarginExpansionThresholds(
                $saved['fcf_margin_expansion_thresholds'] ?? null,
                (array) ($default['fcf_margin_expansion_thresholds'] ?? self::DEFAULT_FCF_MARGIN_EXPANSION_THRESHOLDS),
            ),
            'growth_synergy_bonus' => $this->mergeGrowthSynergyBonus(
                $saved['growth_synergy_bonus'] ?? null,
                (array) ($default['growth_synergy_bonus'] ?? self::DEFAULT_GROWTH_SYNERGY_BONUS),
            ),
            ...$this->mergeMarketCapRange($saved, $default),
        ];
    }

    /**
     * Merge/validate the per-setup-type market-cap eligibility range.
     *
     * Requires min_market_cap >= 0, max_market_cap > 0 and
     * min_market_cap <= max_market_cap. Missing or invalid input falls
     * back to the setup type's default range (which itself falls back to
     * the global scanner min/max_market_cap config/env values).
     *
     * @param  array<string, mixed>  $saved
     * @param  array<string, mixed>  $default
     * @return array{min_market_cap: int, max_market_cap: int}
     */
    private function mergeMarketCapRange(array $saved, array $default): array
    {
        $defaultRange = [
            'min_market_cap' => (int) ($default['min_market_cap'] ?? self::DEFAULT_MIN_MARKET_CAP),
            'max_market_cap' => (int) ($default['max_market_cap'] ?? self::DEFAULT_MAX_MARKET_CAP),
        ];

        if (! isset($saved['min_market_cap']) && ! isset($saved['max_market_cap'])) {
            return $defaultRange;
        }

        if (! is_numeric($saved['min_market_cap'] ?? null) || ! is_numeric($saved['max_market_cap'] ?? null)) {
            return $defaultRange;
        }

        $min = (int) $saved['min_market_cap'];
        $max = (int) $saved['max_market_cap'];

        if ($min < 0 || $max <= 0 || $min > $max) {
            return $defaultRange;
        }

        return ['min_market_cap' => $min, 'max_market_cap' => $max];
    }

    /**
     * Merge/validate Operating Margin Expansion thresholds (basis points).
     *
     * All four thresholds must be present, numeric, positive, and strictly
     * increasing (threshold_25 < threshold_50 < threshold_75 <
     * threshold_100). Invalid input falls back to the setup type's default
     * thresholds rather than persisting a broken configuration.
     *
     * @param  array<string, int>  $default
     * @return array<string, int>
     */
    private function mergeOperatingMarginExpansionThresholds(mixed $saved, array $default): array
    {
        if (! is_array($saved)) {
            return $default;
        }

        $keys = ['threshold_25', 'threshold_50', 'threshold_75', 'threshold_100'];
        $values = [];
        foreach ($keys as $key) {
            if (! isset($saved[$key]) || ! is_numeric($saved[$key])) {
                return $default;
            }
            $values[$key] = (int) $saved[$key];
        }

        if (! (
            $values['threshold_25'] > 0
            && $values['threshold_25'] < $values['threshold_50']
            && $values['threshold_50'] < $values['threshold_75']
            && $values['threshold_75'] < $values['threshold_100']
        )) {
            return $default;
        }

        return $values;
    }

    /**
     * Merge/validate the Growth Synergy Bonus configuration.
     *
     * Requires max_points >= 0, min_sales_yoy >= 0, each threshold within
     * 0..100, and medium_threshold < strong_threshold <
     * exceptional_threshold. Invalid input falls back to the setup type's
     * default rather than persisting a broken configuration.
     *
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    private function mergeGrowthSynergyBonus(mixed $saved, array $default): array
    {
        $default = array_merge(self::DEFAULT_GROWTH_SYNERGY_BONUS, $default);

        if (! is_array($saved)) {
            return $default;
        }

        $numericKeys = ['max_points', 'min_sales_yoy', 'medium_threshold', 'strong_threshold', 'exceptional_threshold'];
        foreach ($numericKeys as $key) {
            if (! isset($saved[$key]) || ! is_numeric($saved[$key])) {
                return $default;
            }
        }

        $maxPoints = (int) $saved['max_points'];
        $minSalesYoy = (float) $saved['min_sales_yoy'];
        $medium = (float) $saved['medium_threshold'];
        $strong = (float) $saved['strong_threshold'];
        $exceptional = (float) $saved['exceptional_threshold'];

        if ($maxPoints < 0 || $minSalesYoy < 0) {
            return $default;
        }

        foreach ([$medium, $strong, $exceptional] as $threshold) {
            if ($threshold < 0 || $threshold > 100) {
                return $default;
            }
        }

        if (! ($medium < $strong && $strong < $exceptional)) {
            return $default;
        }

        return [
            'enabled' => (bool) ($saved['enabled'] ?? $default['enabled'] ?? false),
            'max_points' => $maxPoints,
            'min_sales_yoy' => $minSalesYoy,
            'medium_threshold' => $medium,
            'strong_threshold' => $strong,
            'exceptional_threshold' => $exceptional,
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
            'market_data.buy_setup_scanner.max_market_cap' => (int) $config['max_market_cap'],
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
