<?php

namespace Database\Seeders;

use App\Models\Platform;
use Illuminate\Database\Seeder;

class PlatformSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $path = config('app.demo_mode')
            ? database_path('seeders/demo-seed-data.json')
            : database_path('seeders/private/seed-data.json');

        if (file_exists($path)) {
            $json = json_decode(file_get_contents($path), true);

            foreach ($json['exchanges'] as $appData) {
                $platform = Platform::factory()->create([
                    'name' => $appData['name'],
                    'type' => 'exchange',
                    'can_trade' => 1,
                ]);

                foreach ($appData['wallets'] ?? [] as $walletData) {
                    $wallet = $platform->wallets()->create([
                        'name' => $walletData['name'],
                        'currency' => $walletData['currency'],
                    ]);

                    foreach ($walletData['addresses'] ?? [] as $addr) {
                        $wallet->addresses()->create($addr);
                    }
                }
            }
            foreach ($json['softwareWallets'] as $appData) {
                $platform = Platform::factory()->create([
                    'name' => $appData['name'],
                    'type' => 'software',
                    'can_trade' => in_array($appData['name'], ['Atomic Wallet', 'Exodus']) ? 1 : 0,
                ]);

                foreach ($appData['wallets'] ?? [] as $walletData) {
                    $wallet = $platform->wallets()->create([
                        'name' => $walletData['name'],
                        'currency' => $walletData['currency'],
                    ]);

                    foreach ($walletData['addresses'] ?? [] as $addr) {
                        $wallet->addresses()->create($addr);
                    }
                }
            }
        } else {
            $platform = Platform::factory()->create([
                'name' => 'Shakepay',
                'type' => 'exchange',
            ]);
            foreach (['CAD', 'BTC', 'ETH'] as $currency) {
                $wallet = $platform->wallets()->create([
                    'name' => $currency,
                    'currency' => $currency,
                ]);
            }
        }
    }
}
