<?php

namespace App\Data\Reports;

use App\Types\Money;

final class CraCapitalGainsReportSummaryData
{
    public function __construct(
        public readonly Money $proceeds_of_disposition,
        public readonly Money $adjusted_cost_base,
        public readonly Money $outlays_and_expenses,
        public readonly Money $capital_gain_loss,
        public readonly Money $net_capital_gain_loss,
        public readonly Money $taxable_capital_gain,
    ) {}

    public static function fromCraRows(array $rows): self
    {
        $currency = getReportingCurrency();

        $proceeds = Money::zero($currency);
        $acb = Money::zero($currency);
        $expenses = Money::zero($currency);
        $gain = Money::zero($currency);
        $netGain = Money::zero($currency);
        $taxable = Money::zero($currency);

        foreach ($rows as $row) {
            $proceeds = $proceeds->add($row['proceeds']);
            $acb = $acb->add($row['acb']);
            $expenses = $expenses->add($row['expenses']);
            $gain = $gain->add($row['gain_or_loss']);
            // Until superficial loss rules are applied, net gain == gain
            $netGain = $netGain->add(
                $row['net_gain_or_loss'] ?? $row['gain_or_loss']
            );
            $taxable = $taxable->add($row['taxable_amount']);
        }

        return new self(
            proceeds_of_disposition: $proceeds,
            adjusted_cost_base: $acb,
            outlays_and_expenses: $expenses,
            capital_gain_loss: $gain,
            net_capital_gain_loss: $netGain,
            taxable_capital_gain: $taxable,
        );
    }
}
