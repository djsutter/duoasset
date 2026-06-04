<?php

namespace App\Data\Reports;

use App\Types\Money;

final class LedgerCapitalGainsSummaryData
{
    public function __construct(
        public readonly Money $total_proceeds,
        public readonly Money $total_acb,
        public readonly Money $total_gain_or_loss,
        public readonly Money $taxable_amount,
    ) {}

    /**
     * @param  CapitalGainRowData[]  $rows
     */
    public static function fromLedgerRows(array $rows): self
    {
        $currency = getReportingCurrency();

        $proceeds = Money::zero($currency);
        $acb = Money::zero($currency);
        $gain = Money::zero($currency);

        foreach ($rows as $row) {
            $proceeds = $proceeds->add($row->proceeds);
            $acb = $acb->add($row->acb_allocated);
            $gain = $gain->add($row->gain_or_loss);
        }

        return new self(
            total_proceeds: $proceeds,
            total_acb: $acb,
            total_gain_or_loss: $gain,
            taxable_amount: $gain->multiply('0.5'),
        );
    }
}
