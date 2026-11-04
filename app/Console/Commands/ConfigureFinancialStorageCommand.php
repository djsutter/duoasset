<?php

namespace App\Console\Commands;

use App\Models\CompanyFinancial;
use App\Models\MarketDataPayload;
use App\Services\MarketData\FinancialStorageConfigService;
use Illuminate\Console\Command;

class ConfigureFinancialStorageCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market-data:financial-storage
                            {action=status : Action to execute (status, enable, disable, toggle, reset)}';

    /**
     * Command aliases.
     *
     * @var array<int, string>
     */
    protected $aliases = [
        'market-data:storage',
    ];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'View or configure application-wide financial market data persistence and caching';

    /**
     * Execute the console command.
     */
    public function handle(FinancialStorageConfigService $service): int
    {
        $action = strtolower(trim((string) $this->argument('action')));

        match ($action) {
            'enable' => $service->enable(),
            'disable' => $service->disable(),
            'toggle' => $service->toggle(),
            'reset' => $service->resetToDefault(),
            'status' => null,
            default => null,
        };

        if (! in_array($action, ['status', 'enable', 'disable', 'toggle', 'reset'], true)) {
            $this->error("Invalid action [{$action}]. Allowed actions: status, enable, disable, toggle, reset.");

            return self::FAILURE;
        }

        $isEnabled = $service->isEnabled();
        $statusText = $isEnabled ? 'ENABLED (Database & Memory Active)' : 'DISABLED (Direct Upstream Bypass)';

        if ($action === 'enable') {
            $this->info('Financial storage and caching has been ENABLED application-wide.');
        } elseif ($action === 'disable') {
            $this->warn('Financial storage and caching has been DISABLED application-wide.');
        } elseif ($action === 'toggle') {
            $this->info("Financial storage and caching has been toggled to: {$statusText}");
        } elseif ($action === 'reset') {
            $this->info('Financial storage configuration has been reset to default.');
        } else {
            $this->info("Financial storage and caching is currently: {$statusText}");
        }

        $financialsCount = CompanyFinancial::count();
        $payloadsCount = MarketDataPayload::whereIn('data_type', [
            'income', 'balance_sheet', 'cash_flow',
            'refresh_income', 'refresh_balance_sheet', 'refresh_cash_flow',
        ])->count();

        $this->table(
            ['Configuration Item', 'Value'],
            [
                ['Financial Storage & Caching', $statusText],
                ['Storage Mode', $isEnabled ? 'Persistent Database + Cache' : 'Live Upstream Direct (No Storage)'],
                ['Stored Company Financials', (string) $financialsCount],
                ['Stored Financial Payloads', (string) $payloadsCount],
                ['Data Safety', 'Non-destructive (existing records are never deleted)'],
            ]
        );

        return self::SUCCESS;
    }
}
