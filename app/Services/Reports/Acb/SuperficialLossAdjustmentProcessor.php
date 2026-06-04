<?php

namespace App\Services\Reports\Acb;

use App\Contracts\Reports\LedgerPostProcessor;
use App\Data\Reports\AssetAcbAuditRowData;
use App\Types\Money;

final class SuperficialLossAdjustmentProcessor implements LedgerPostProcessor
{
    public function __construct(
        private SuperficialLossAdjustmentCalculator $calculator,
    ) {}

    public function process(AssetAcbAuditLedgerResult $result): SuperficialLossProcessingResult
    {
        $output = [];
        $annotations = [];
        $deniedBalance = Money::zero('CAD');
        $deniedLossByRow = [];

        foreach ($result->rows as $i => $row) {
            $output[] = $row;

            if ($row->isDisposition()) {
                foreach ($this->calculator->calculate($row, $result->rows, $i) as $adjustment) {
                    if (isset($adjustment->meta['superficial_loss'])) {
                        $annotations[$adjustment->annotates_base_key] = new LedgerAnnotationData(
                            message: 'Superficial loss: $'.
                            $row->capital_gain_loss->abs()->format().
                            ' denied and added to adjusted cost base of replacement property (ITA s.54).'
                        );
                        $deniedBalance = $deniedBalance->add($row->capital_gain_loss->abs());

                        continue;
                    }
                    $output[] = $adjustment;
                }
            }

            if (! $deniedBalance->isZero()) {
                $deniedLossByRow[$row->rowKey()] = $deniedBalance;
            }
        }

        $output = collect($output)
            ->sortBy(fn (AssetAcbAuditRowData $row) => [
                $row->event_at->timestamp,
                $row->event_type->value,
                $row->tx_id,
            ])
            ->values()
            ->all();

        return new SuperficialLossProcessingResult(
            ledger: new AssetAcbAuditLedgerResult($output, $annotations),
            deniedLossByRow: $deniedLossByRow,
        );
    }
}
