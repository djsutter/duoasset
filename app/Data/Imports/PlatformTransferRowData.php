<?php

namespace App\Data\Imports;

class PlatformTransferRowData
{
    public function __construct(
        public readonly string $date,
        public readonly string $direction,
        public readonly string $asset,
        public readonly string $amount,
        public readonly ?string $fee,
        public ?string $fee_currency,
        public readonly ?string $address,
        public readonly ?string $description,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            date: $row['Date'],
            direction: $row['Direction'],
            asset: $row['Asset'],
            amount: $row['Amount'],
            fee: $row['Fee'] ?? null,
            fee_currency: $row['Asset'],
            address: $row['Address'] ?? null,
            description: $row['Description'] ?? null,
        );
    }

    public static function headers(): array
    {
        return [
            'Date',
            'Direction',
            'Asset',
            'Amount',
            'Fee',
            'Fee Currency',
            'Address',
            'Description',
        ];
    }

    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'direction' => $this->direction,
            'asset' => $this->asset,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'fee_currency' => $this->fee_currency,
            'address' => $this->address,
            'description' => $this->description,
        ];
    }
}
