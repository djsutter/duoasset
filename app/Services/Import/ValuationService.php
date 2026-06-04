<?php

namespace App\Services\Import;

use App\Models\Transaction;
use App\Services\PriceService;
use App\Types\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ValuationService
{
    public function __construct(
        protected PriceService $priceService,
    ) {}

    /**
     * Valuate a transaction in the reporting currency, ensuring that both sides of the transaction are
     * equally valued.
     * For a discussion on valuation, see https://chatgpt.com/c/6914a85d-1a8c-8332-ba3a-e348e9ba933c
     */
    public function valuate(Transaction $transaction): void
    {
        // Atomically mark as processing
        $updated = $transaction
            ->where('id', $transaction->id)
            ->where('valuation_status', 'pending')
            ->update(['valuation_status' => 'processing']);

        if ($updated === 0) {
            return; // Another worker already started it
        }

        try {
            DB::transaction(function () use ($transaction) {
                $entries = $transaction->entries; // Collection of Entry models

                // Define helper to identify fee entries (adjust to your actual flag)
                $isFee = fn ($e) => isset($e->entry_type) && $e->entry_type === 'fee';

                // Consider only non-fee entries for valuation decisions
                $valuedCandidates = $entries->filter(fn ($e) => ! $isFee($e));

                /**
                 * CASE A: CAD purchase detected by your described shape:
                 * - There is a non-fee "in" entry with amount != null and currency === 'CAD'
                 * - The other non-fee entry will have amount === null and foreign_amount != null (crypto)
                 */
                $cadIn = $valuedCandidates
                    ->first(fn ($e) => ($e->entry_type === 'in') && ! is_null($e->amount) && ($e->currency === 'CAD'));

                if ($cadIn) {
                    $cadValue = $cadIn->amount; // Money(CAD) instance already present on the CAD 'in' entry

                    // Apply the opposite CAD value to other non-fee unvalued entries (crypto side)
                    foreach ($valuedCandidates as $entry) {
                        if ($entry->id === $cadIn->id) {
                            continue;
                        }

                        if (is_null($entry->amount) && ! is_null($entry->foreign_amount)) {
                            // Use negated to keep ledger balanced
                            $entry->amount = $cadValue->negated();
                            $entry->save();
                        }
                    }

                    // --- Fee handling ---
                    foreach ($entries->filter($isFee) as $feeEntry) {
                        if ($feeEntry->currency === 'CAD') {
                            // Already negative CAD amount, nothing to do
                            continue;
                        }

                        if ($feeEntry->foreign_amount !== null) {
                            // Use crypto out entry to determine unit price
                            $cryptoOut = $valuedCandidates->first(fn ($e) => $e->id !== $cadIn->id);
                            if ($cryptoOut && ! $cryptoOut->foreign_amount->isZero()) {
                                $unitPrice = bcdiv(
                                    $cadValue->toDecimal(),
                                    $cryptoOut->foreign_amount->toDecimal(),
                                    18
                                );
                                $feeCad = bcmul(
                                    $unitPrice,
                                    $feeEntry->foreign_amount->toDecimal(),
                                    18
                                );
                                $feeEntry->amount = Money::fromDecimal($feeCad, 'CAD');
                                $feeEntry->save();
                            }
                        }
                    }

                    $transaction->update(['valuation_status' => 'done']);

                    return;
                }

                /**
                 * CASE B: Crypto-to-Crypto or other non-CAD-funded trades
                 * - Prefer the disposed side: entry_type==='out' (non-fee, has foreign_amount)
                 * - Fallback to an entry where foreign_amount->isNegative()
                 * - Fallback to any entry with foreign_amount
                 */
                $disposed = $valuedCandidates
                    ->first(fn ($e) => ($e->entry_type === 'out') && ! is_null($e->foreign_amount));

                if (! $disposed) {
                    $disposed = $valuedCandidates
                        ->first(fn ($e) => ! is_null($e->foreign_amount) && $e->foreign_amount->isNegative());
                }

                if (! $disposed) {
                    $disposed = $valuedCandidates->first(fn ($e) => ! is_null($e->foreign_amount));
                }

                // If we have a disposed side, convert it (if not already converted)
                if ($disposed) {
                    if (is_null($disposed->amount)) {
                        $disposed->amount = $this->convert($disposed->foreign_amount, $transaction->transaction_at);
                        $disposed->save();
                    }

                    // Apply the same CAD value (negated) to other non-fee entries that lack an amount
                    foreach ($valuedCandidates as $entry) {
                        if ($entry->id === $disposed->id) {
                            continue;
                        }

                        if (is_null($entry->amount) && ! is_null($entry->foreign_amount)) {
                            $entry->amount = $disposed->amount?->negated();
                            $entry->save();
                        }
                    }

                    // --- Fee handling ---
                    foreach ($entries->filter($isFee) as $feeEntry) {
                        if ($feeEntry->currency === 'CAD') {
                            continue;
                        }

                        if ($feeEntry->foreign_amount !== null) {
                            if ($feeEntry->currency === $disposed->currency) {
                                // Same-asset fee: reuse unit price
                                $unitPrice = bcdiv(
                                    $disposed->amount->toDecimal(),
                                    $disposed->foreign_amount->toDecimal(),
                                    18
                                );

                                $feeCad = bcmul(
                                    $unitPrice,
                                    ltrim($feeEntry->foreign_amount->toDecimal(), '-'),
                                    18
                                );

                                $feeEntry->amount = Money::fromDecimal(
                                    bcsub('0', $feeCad, 18),
                                    'CAD'
                                );
                            } else {
                                // Cross-asset fee: convert independently
                                $feeEntry->amount = $this->convert(
                                    $feeEntry->foreign_amount,
                                    $transaction->transaction_at
                                );
                            }

                            $feeEntry->save();
                        }
                    }

                    $transaction->update(['valuation_status' => 'done']);

                    return;
                }

                // If we get here: no suitable candidates found — mark failed and log
                throw new \RuntimeException("No valuation candidate found for transaction {$transaction->id}");
            });
        } catch (\Throwable $e) {
            $transaction->update(['valuation_status' => 'failed']);
            \Log::error("Valuation failed for transaction {$transaction->id}: {$e->getMessage()}");
        }
    }

    /**
     * Valuate a transaction in the reporting currency, but treat each side of the transaction independently.
     * This is not to be used as a final valuation. The purpose is to check the spread between the buy and sell
     * price in a common currency which can be helpful to find transactions which were not entered correctly.
     * For a discussion on valuation, see https://chatgpt.com/c/6914a85d-1a8c-8332-ba3a-e348e9ba933c
     */
    public function valuateSides(Transaction $transaction): void
    {
        // Atomically mark as processing, preventing duplicate worker execution
        $updated = $transaction
            ->where('id', $transaction->id)
            ->where('valuation_status', 'pending')
            ->update(['valuation_status' => 'processing']);

        if ($updated === 0) {
            return; // Another worker already started it
        }

        try {
            DB::transaction(function () use ($transaction) {
                $valuedMap = []; // key: "{$currency}:{$foreign_amount}", value: Money instance

                foreach ($transaction->entries as $entry) {
                    // Skip entries already valued
                    if (! is_null($entry->amount)) {
                        continue;
                    }

                    $currency = $entry->foreign_currency;
                    $amountStr = (string) $entry->foreign_amount->amount;
                    $negatedStr = (string) $entry->foreign_amount->negated()->amount;

                    $key = "{$currency}:{$amountStr}";
                    $negKey = "{$currency}:{$negatedStr}";

                    if (isset($valuedMap[$key])) {
                        $entry->amount = $valuedMap[$key];
                    } elseif (isset($valuedMap[$negKey])) {
                        $entry->amount = $valuedMap[$negKey]->negated();
                    } else {
                        // Perform new valuation
                        $entry->amount = $this->convert($entry->foreign_amount, $entry->transaction_at);

                        // Cache both directions for quick lookup
                        $valuedMap[$key] = $entry->amount;
                        $valuedMap[$negKey] = $entry->amount?->negated();
                    }

                    $entry->save();
                }

                $transaction->update(['valuation_status' => 'done']);
            });
        } catch (\Throwable $e) {
            $transaction->update(['valuation_status' => 'failed']);
            \Log::error("Valuation failed for transaction {$transaction->id}: {$e->getMessage()}");
        }
    }

    public function convert(Money $foreignAmount, Carbon $date): ?Money
    {
        $rate = $this->priceService->getCadPrice($foreignAmount->currency, $date);

        if ($rate === null) {
            return null;
        }

        return Money::fromDecimal(
            sprintf('%0.8f', $foreignAmount->toDecimal() * $rate),
            getReportingCurrency()
        );
    }
}
