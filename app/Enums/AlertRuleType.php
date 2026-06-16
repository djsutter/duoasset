<?php

namespace App\Enums;

enum AlertRuleType: string
{
    case PriceAbove = 'price_above';
    case PriceBelow = 'price_below';
    case PercentChange = 'percent_change';
    case VolumeSpike = 'volume_spike';
    case Breakout52Week = 'breakout_52_week';
    case ManualReview = 'manual_review';

    public function label(): string
    {
        return __('alerts.rule.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $c) {
            $out[$c->value] = $c->label();
        }

        return $out;
    }
}
