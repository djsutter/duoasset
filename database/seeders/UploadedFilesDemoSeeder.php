<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UploadedFilesDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $files = [
            [
                'filename' => 'monero-txs.csv',
                'directory' => 'demo-exports/monero',
                'mapper' => 'Monero',
                'platform' => 'Monero',
                'wallet' => 'XMR',
                'wallet_prefix' => null,
                'status' => 'pending',
            ],
            [
                'filename' => 'BitcoinCore Hodlings.csv',
                'directory' => 'demo-exports/bitcoin',
                'mapper' => 'BitcoinCore',
                'platform' => 'Bitcoin Core',
                'wallet' => 'BTC',
                'wallet_prefix' => null,
                'status' => 'pending',
            ],
            [
                'filename' => 'exodus-bitcoin-final.csv',
                'directory' => 'demo-exports/exodus',
                'mapper' => 'Exodus',
                'platform' => 'Exodus',
                'wallet' => 'BTC',
                'wallet_prefix' => null,
                'status' => 'pending',
            ],
            [
                'filename' => 'exodus-ethereum-final.csv',
                'directory' => 'demo-exports/exodus',
                'mapper' => 'Exodus',
                'platform' => 'Exodus',
                'wallet' => 'ETH',
                'wallet_prefix' => null,
                'status' => 'pending',
            ],
            [
                'filename' => 'exodus_0-cardano-txs-2022-05-15_19-56-08.csv',
                'directory' => 'demo-exports/exodus',
                'mapper' => 'Exodus',
                'platform' => 'Exodus',
                'wallet' => 'ADA',
                'wallet_prefix' => null,
                'status' => 'pending',
            ],
            [
                'filename' => 'bitcoin-atomicwallet-final.csv',
                'directory' => 'demo-exports/atomic',
                'mapper' => 'Atomic',
                'platform' => 'Atomic Wallet',
                'wallet' => 'BTC',
                'wallet_prefix' => null,
                'status' => 'pending',
            ],
            [
                'filename' => 'ripple-atomicwallet-12.03.2022.csv',
                'directory' => 'demo-exports/atomic',
                'mapper' => 'Atomic',
                'platform' => 'Atomic Wallet',
                'wallet' => 'XRP',
                'wallet_prefix' => null,
                'status' => 'pending',
            ],
            [
                'filename' => 'cardano-atomicwallet-12.03.2022.csv',
                'directory' => 'demo-exports/atomic',
                'mapper' => 'Atomic',
                'platform' => 'Atomic Wallet',
                'wallet' => 'ADA',
                'wallet_prefix' => null,
                'status' => 'pending',
            ],
            [
                'filename' => 'ethereum-atomicwallet-final.csv',
                'directory' => 'demo-exports/atomic',
                'mapper' => 'Atomic',
                'platform' => 'Atomic Wallet',
                'wallet' => 'ETH',
                'wallet_prefix' => null,
                'status' => 'pending',
            ],
            [
                'filename' => 'crypto_transactions_summary.csv',
                'directory' => 'demo-exports/shakepay',
                'mapper' => 'ShakepayCrypto',
                'platform' => 'Shakepay',
                'wallet' => null,
                'wallet_prefix' => null,
                'status' => 'pending',
            ],
            [
                'filename' => 'cash_transactions_summary.csv',
                'directory' => 'demo-exports/shakepay',
                'mapper' => 'ShakepayCash',
                'platform' => 'Shakepay',
                'wallet' => 'CAD',
                'wallet_prefix' => null,
                'status' => 'pending',
            ],
            [
                'filename' => 'export_withdrawals.csv',
                'directory' => 'demo-exports/tradeogre',
                'mapper' => 'TradeOgreWithdrawal',
                'platform' => 'TradeOgre',
                'wallet' => null,
                'wallet_prefix' => null,
                'status' => 'pending',
            ],
            [
                'filename' => 'export_deposits.csv',
                'directory' => 'demo-exports/tradeogre',
                'mapper' => 'TradeOgreDeposit',
                'platform' => 'TradeOgre',
                'wallet' => null,
                'wallet_prefix' => null,
                'status' => 'pending',
            ],
            [
                'filename' => 'export_trades.csv',
                'directory' => 'demo-exports/tradeogre',
                'mapper' => 'TradeOgreTrade',
                'platform' => 'TradeOgre',
                'wallet' => null,
                'wallet_prefix' => null,
                'status' => 'pending',
            ],
        ];
        DB::table('uploaded_files')->insert($files);
    }
}
