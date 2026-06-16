<?php

namespace App\Enums;

enum MoatLevel: string
{
    case VeryLow = 'very_low';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case VeryHigh = 'very_high';

    public function label(): string
    {
        return __('moat.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
