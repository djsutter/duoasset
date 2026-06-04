<?php

namespace App\Data\Imports;

class KuCoinRowData
{
    public function __construct(
        public readonly string $uid,
        public readonly string $account_type,
        public readonly string $currency,
        public string $side,
        public string $amount,
        public ?string $fee,
        public readonly string $time,
        public readonly ?string $remark,
        public readonly string $type,
    ) {}

    public static function fromRow($row): self
    {
        return new self(
            uid: $row['UID'],
            account_type: $row['Account Type'],
            currency: $row['Currency'],
            side: $row['Side'],
            amount: $row['Amount'],
            fee: $row['Fee'] ?: null,
            time: $row['Time(UTC-04:00)'],
            remark: $row['Remark'] ?: null,
            type: $row['Type'],
        );
    }
}
