<?php

namespace Database\Seeders;

use App\Models\Wallet;
use Illuminate\Database\Seeder;

class WalletSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Wallet::factory()->create([
            'name' => 'CAD',
            'currency' => 'CAD',
        ]);

        Wallet::factory()->create([
            'name' => 'BTC',
            'currency' => 'BTC',
        ]);

        Wallet::factory()->create([
            'name' => 'ETH',
            'currency' => 'ETH',
        ]);
    }
}
