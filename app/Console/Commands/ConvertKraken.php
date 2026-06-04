<?php

namespace App\Console\Commands;

use App\Data\Imports\PlatformTradeRowData;
use App\Data\Imports\PlatformTransferRowData;
use Carbon\Carbon;
use Illuminate\Console\Command;
use League\Csv\Reader;
use League\Csv\Writer;

class ConvertKraken extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:convert-kraken';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Convert Kraken export files into generic CSV import format';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $joinOrders = false;

        $ledgerFile = 'kraken_stocks_etfs_ledgers_2025-11-22-2026-03-29.csv';
        $tradesFile = 'kraken_spot_trades_2025-11-22-2026-03-29.csv';

        $rawTrades = $this->parseTrades($tradesFile);
        $this->attachLedgerFees($ledgerFile, $rawTrades);

        if ($joinOrders) {
            $aggregated = $this->aggregateTradesByOrder($rawTrades);
            $trades = $this->normalizeAggregatedTrades($aggregated);
        } else {
            $trades = $this->normalizeTrades($rawTrades);
        }

        $transfers = $this->parseTransfers($ledgerFile);

        $this->writeTrades($trades);
        $this->writeTransfers($transfers);
    }

    private function parseTrades(string $file): array
    {
        $csv = Reader::from("exports/kraken/$file");
        $csv->setHeaderOffset(0);

        $trades = [];

        foreach ($csv->getRecords() as $row) {
            [$base, $quote] = explode('/', $row['pair']);

            $txid = $row['txid'];

            $trades[$txid] = new RawTrade(
                txid: $txid,
                ordertxid: $row['ordertxid'],
                date: Carbon::parse($row['time']),
                type: $row['type'],
                base: $base,
                quote: $quote,
                price: $row['price'],
                volume: $row['vol'],
                cost: $row['cost'],
                fee: $row['fee'] ?: null,
                feeCurrency: null, // will be overridden
            );
        }

        return $trades;
    }

    private function attachLedgerFees(string $file, array &$trades): void
    {
        $csv = Reader::from("exports/kraken/$file");
        $csv->setHeaderOffset(0);

        foreach ($csv->getRecords() as $row) {
            if ($row['type'] !== 'trade') {
                continue;
            }

            if (! $row['fee']) {
                continue;
            }

            $txid = $row['refid'];

            if (! isset($trades[$txid])) {
                continue;
            }

            $trades[$txid]->fee = $row['fee'];
            $trades[$txid]->feeCurrency = $row['asset'];
        }
    }

    private function normalizeTrades(array $rawTrades): array
    {
        $results = [];

        foreach ($rawTrades as $trade) {

            $feeBase = null;
            $feeQuote = null;

            if ($trade->fee && $trade->feeCurrency) {
                if ($trade->feeCurrency === $trade->base) {
                    $feeBase = $trade->fee;
                    $feeQuote = bcmul($trade->fee, $trade->price, 8);
                } elseif ($trade->feeCurrency === $trade->quote) {
                    $feeQuote = $trade->fee;
                    $feeBase = bcdiv($trade->fee, $trade->price, 8);
                }
            }

            $results[] = new PlatformTradeRowData(
                date: $trade->date->toDateTimeString(),
                type: strtoupper($trade->type),
                pair: "{$trade->quote}-{$trade->base}",
                price: $trade->price,
                amount: $trade->volume, // IMPORTANT: raw volume only
                fee: $trade->fee,
                fee_currency: $trade->feeCurrency,

                // NEW FIELDS
                fee_amount_base: $feeBase,
                fee_amount_quote: $feeQuote,
                fee_cad_value: $trade->quote === 'CAD' ? $feeQuote : null,
                trade_cad_value: $trade->quote === 'CAD' ? $trade->cost : null,
                trade_id: $trade->txid,
            );
        }

        return $results;
    }

    private function parseTransfers(string $file): array
    {
        $csv = Reader::from("exports/kraken/$file");
        $csv->setHeaderOffset(0);

        $transfers = [];

        foreach ($csv->getRecords() as $row) {

            if ($row['type'] === 'deposit') {
                $direction = 'IN';
            } elseif ($row['type'] === 'withdrawal') {
                $direction = 'OUT';
            } else {
                continue; // ignore trades and anything else
            }

            $date = Carbon::parse($row['time']);

            $transfers[] = new PlatformTransferRowData(
                date: $date->toDateTimeString(),
                direction: $direction,
                asset: $row['asset'],
                amount: $row['amount'],
                fee: $row['fee'] ?: null,
                fee_currency: $row['fee'] ? $row['asset'] : null,
                address: null,
                description: null,
            );
        }

        return $transfers;
    }

    private function aggregateTradesByOrder(array $rawTrades): array
    {
        $grouped = [];

        foreach ($rawTrades as $trade) {
            $grouped[$trade->ordertxid][] = $trade;
        }

        $aggregated = [];

        foreach ($grouped as $ordertxid => $trades) {

            $first = $trades[0];

            $totalVolume = '0';
            $totalCost = '0';

            $feeByCurrency = [];

            $txids = [];

            foreach ($trades as $t) {
                $totalVolume = bcadd($totalVolume, $t->volume, 8);
                $totalCost = bcadd($totalCost, $t->cost, 8);

                if ($t->fee && $t->feeCurrency) {
                    $feeByCurrency[$t->feeCurrency] ??= '0';
                    $feeByCurrency[$t->feeCurrency] = bcadd(
                        $feeByCurrency[$t->feeCurrency],
                        $t->fee,
                        8
                    );
                }

                $txids[] = $t->txid;
            }

            $weightedPrice = bcdiv($totalCost, $totalVolume, 8);

            // Important: we still only allow ONE fee currency per row
            // so we defer normalization later
            $aggregated[] = [
                'ordertxid' => $ordertxid,
                'date' => $first->date,
                'type' => $first->type,
                'base' => $first->base,
                'quote' => $first->quote,
                'price' => $weightedPrice,
                'volume' => $totalVolume,
                'cost' => $totalCost,
                'fees' => $feeByCurrency,
                'txids' => $txids,
            ];
        }

        return $aggregated;
    }

    private function normalizeAggregatedTrades(array $aggregated): array
    {
        $results = [];

        foreach ($aggregated as $trade) {

            $feeCAD = '0';

            foreach ($trade['fees'] as $currency => $amount) {
                if ($currency === $trade['quote']) {
                    $feeCAD = bcadd($feeCAD, $amount, 8);
                } elseif ($currency === $trade['base']) {
                    $converted = bcmul($amount, $trade['price'], 8);
                    $feeCAD = bcadd($feeCAD, $converted, 8);
                }
            }

            $results[] = new PlatformTradeRowData(
                date: $trade['date']->toDateTimeString(),
                type: strtoupper($trade['type']),
                pair: "{$trade['quote']}-{$trade['base']}",
                price: $trade['price'],
                amount: $trade['volume'],

                fee: $feeCAD,
                fee_currency: 'CAD',

                fee_amount_base: null,
                fee_amount_quote: $feeCAD,
                fee_cad_value: $feeCAD,
                trade_cad_value: $trade['cost'],

                trade_id: $trade['ordertxid'],
            );
        }

        return $results;
    }

    private function writeTrades(array $trades): void
    {
        $csv = Writer::from('exports/kraken/kraken_trades.csv', 'w');

        $csv->insertOne(PlatformTradeRowData::headers());

        foreach ($trades as $trade) {
            $csv->insertOne($trade->toArray());
        }
    }

    private function writeTransfers(array $transfers): void
    {
        $csv = Writer::from('exports/kraken/kraken_transfers.csv', 'w');

        $csv->insertOne(PlatformTransferRowData::headers());

        foreach ($transfers as $transfer) {
            $csv->insertOne($transfer->toArray());
        }
    }
}
