<?php

namespace Database\Factories;

use App\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Currency::factory()->create();   // random fiat or crypto
 * Currency::factory()->fiat()->create();   // always fiat
 * Currency::factory()->crypto()->create(); // always crypto
 */
class CurrencyFactory extends Factory
{
    protected $model = Currency::class;

    public function definition(): array
    {
        $isFiat = $this->faker->boolean();

        return [
            'currency_code' => strtoupper($this->faker->lexify('???')), // e.g. "ABC"
            'numeric_code' => $isFiat ? (string) $this->faker->numberBetween(100, 999) : null,
            'name' => $isFiat
                ? $this->faker->country.' Dollar'
                : ucfirst($this->faker->word).'Coin',
            'symbol' => $isFiat
                ? $this->faker->randomElement(['$', '€', '¥', '£'])
                : $this->faker->randomElement(['₿', 'Ξ', '◎', 'Ð']),
            'type' => $isFiat ? 'fiat' : 'crypto',
            'scale' => $isFiat
                ? $this->faker->randomElement([0, 2]) // fiat usually 0 or 2 decimals
                : $this->faker->randomElement([6, 8]), // crypto often 6–8 decimals
            'is_active' => true,
        ];
    }

    public function fiat(): static
    {
        $currencies = [
            ['currency_code' => 'USD', 'numeric_code' => '840', 'name' => 'US Dollar', 'symbol' => '$', 'scale' => 2],
            ['currency_code' => 'EUR', 'numeric_code' => '978', 'name' => 'Euro', 'symbol' => '€', 'scale' => 2],
            ['currency_code' => 'JPY', 'numeric_code' => '392', 'name' => 'Japanese Yen', 'symbol' => '¥', 'scale' => 0],
            ['currency_code' => 'GBP', 'numeric_code' => '826', 'name' => 'British Pound', 'symbol' => '£', 'scale' => 2],
            ['currency_code' => 'AUD', 'numeric_code' => '036', 'name' => 'Australian Dollar', 'symbol' => 'A$', 'scale' => 2],
            ['currency_code' => 'CAD', 'numeric_code' => '124', 'name' => 'Canadian Dollar', 'symbol' => 'C$', 'scale' => 2],
        ];

        $currency = $this->faker->randomElement($currencies);

        return $this->state(fn () => [
            'currency_code' => $currency['currency_code'],
            'numeric_code' => $currency['numeric_code'],
            'name' => $currency['name'],
            'symbol' => $currency['symbol'],
            'type' => 'fiat',
            'scale' => $currency['scale'],
            'is_active' => true,
        ]);
    }

    public function crypto(): static
    {
        $currencies = [
            ['currency_code' => 'BTC', 'name' => 'Bitcoin', 'symbol' => '₿', 'scale' => 8],
            ['currency_code' => 'ETH', 'name' => 'Ethereum', 'symbol' => 'Ξ', 'scale' => 8],
            ['currency_code' => 'USDT', 'name' => 'Tether', 'symbol' => '₮', 'scale' => 6],
            ['currency_code' => 'BNB', 'name' => 'BNB', 'symbol' => 'BNB', 'scale' => 8],
            ['currency_code' => 'XRP', 'name' => 'XRP', 'symbol' => 'XRP', 'scale' => 6],
            ['currency_code' => 'USDC', 'name' => 'USD Coin', 'symbol' => '$', 'scale' => 6],
            ['currency_code' => 'SOL', 'name' => 'Solana', 'symbol' => '◎', 'scale' => 8],
            ['currency_code' => 'DOGE', 'name' => 'Dogecoin', 'symbol' => 'Ð', 'scale' => 8],
            ['currency_code' => 'TRX', 'name' => 'TRON', 'symbol' => 'TRX', 'scale' => 6],
            ['currency_code' => 'ADA', 'name' => 'Cardano', 'symbol' => '₳', 'scale' => 6],
        ];

        $currency = $this->faker->randomElement($currencies);

        return $this->state(fn () => [
            'currency_code' => $currency['currency_code'],
            'numeric_code' => null,
            'name' => $currency['name'],
            'symbol' => $currency['symbol'],
            'type' => 'crypto',
            'scale' => $currency['scale'],
            'is_active' => true,
        ]);
    }
}
