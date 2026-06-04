<?php

namespace App\Types;

use Illuminate\Support\Str;

final class WalletSlug
{
    public function __construct(
        public readonly string $platform,
        public readonly string $asset,
        public readonly ?string $context = null,
    ) {}

    public static function fromParts(string $platform, string $asset, ?string $context = null): self
    {
        return new self(
            platform: self::normalize($platform),
            asset: strtoupper($asset),
            context: $context ? self::normalize($context) : null,
        );
    }

    public static function parse(string $slug): self
    {
        $parts = explode('_', $slug);

        if (count($parts) < 2) {
            throw new \InvalidArgumentException("Invalid wallet_slug [{$slug}]");
        }

        return new self(
            platform: $parts[0],
            asset: strtoupper($parts[1]),
            context: $parts[2] ?? null,
        );
    }

    public function toString(): string
    {
        return implode('_', array_filter([
            $this->platform,
            strtolower($this->asset),
            $this->context,
        ]));
    }

    private static function normalize(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();
    }
}
