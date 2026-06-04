<?php

namespace App\Casts;

use App\Types\AssetQuantity;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

final class AssetQuantityCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): AssetQuantity
    {
        return new AssetQuantity(
            amount: $value ?? '0',
            asset_code: $attributes['asset_code'],
        );
    }

    public function set($model, string $key, $value, array $attributes): array
    {
        if (! $value instanceof AssetQuantity) {
            throw new \InvalidArgumentException('Expected AssetQuantity');
        }

        return [
            'asset_code' => $value->asset_code,
            $key => (string) $value->amount,
        ];
    }
}
