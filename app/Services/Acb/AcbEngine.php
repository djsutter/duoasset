<?php

namespace App\Services\Acb;

use App\ACB\AcbEventExpander;
use App\ACB\AcbEventProcessor;
use App\Models\AcbDaily;
use App\Models\AcbEvent;
use App\Models\Asset;
use App\Models\Transaction;
use App\Types\AssetQuantity;
use App\Types\Money;
use Carbon\Carbon;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Output\ConsoleOutput;

class AcbEngine
{
    protected OutputStyle|ConsoleOutput|null $output;

    public function __construct($output = null)
    {
        $this->output = $output;
    }

    public function generateForTransaction(Transaction $tx, ?string $forAsset = null): void
    {
        if (! $tx->isValued()) {
            return;
        }

        $events = AcbEventExpander::fromTransaction($tx);

        foreach ($events as $event) {
            AcbEventProcessor::process($event, $forAsset);
        }
    }

    public function rebuildAsset(Asset $asset): void
    {
        AcbEvent::where('asset_code', $asset->asset_code)->delete();
        AcbDaily::where('asset_code', $asset->asset_code)->delete();

        // Reset asset totals
        $asset->quantity = AssetQuantity::zero($asset);
        $asset->acb = Money::zero($asset->acb_currency);
        $asset->total_cost = Money::zero($asset->acb_currency);
        $asset->total_proceeds = Money::zero($asset->acb_currency);

        $asset->save();

        // Load all relevant transactions
        $transactions = Transaction::orderBy('transaction_at')->get();

        foreach ($transactions as $tx) {
            $events = AcbEventExpander::fromTransaction($tx);

            foreach ($events as $event) {
                // Skip if asset does not match
                $assetCurrency = $event->foreign_amount?->currency;
                if ($assetCurrency !== $asset->asset_code) {
                    continue;
                }

                AcbEventProcessor::process($event);
                $this->saveAcbDaily($asset, $event->tx_at);
            }
        }
    }

    public function saveAcbDaily(Asset $asset, Carbon $date): void
    {
        // Compute totals from asset or previous events
        $dailyQty = $asset->quantity;
        $dailyAcb = $asset->acb->toDecimal();
        $acbUnit = $dailyQty->amount != 0 ? bcmul($dailyAcb, '1', 18) / $dailyQty->amount : 0;

        AcbDaily::create([
            'asset_code' => $asset->asset_code,
            'date' => $date,
            'quantity_total' => $dailyQty,
            'acb_total' => $dailyAcb,
            'avg_cost_basis' => $acbUnit,
        ]);
    }
}
