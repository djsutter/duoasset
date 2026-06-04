<?php

namespace App\Data\Imports;

class PlatformTradeRowData
{
    public function __construct(
        public readonly string $date,
        public readonly string $type,
        public readonly string $pair,
        public readonly string $price,
        public readonly string $amount,
        public readonly ?string $fee,
        public ?string $fee_currency,
        public readonly ?string $fee_amount_base,
        public readonly ?string $fee_amount_quote,
        public readonly ?string $fee_cad_value,
        public readonly ?string $trade_cad_value,
        public readonly string $trade_id,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            date: $row['Date'],
            type: $row['Type'],
            pair: $row['Pair'],
            price: $row['Price'],
            amount: $row['Amount'],
            fee: $row['Fee'] ?? null,
            fee_currency: $row['Fee Currency'] ?? null,
            fee_amount_base: $row['Fee Amount (Base)'] ?? null,
            fee_amount_quote: $row['Fee Amount (Quote)'] ?? null,
            fee_cad_value: $row['Fee CAD Value'] ?? null,
            trade_cad_value: $row['Trade CAD Value'] ?? null,
            trade_id: $row['Trade ID'],
        );
    }

    public static function headers(): array
    {
        return [
            'Date',
            'Type',
            'Pair',
            'Price',
            'Amount',
            'Fee',
            'Fee Currency',
            'Fee Amount (Base)',
            'Fee Amount (Quote)',
            'Fee CAD Value',
            'Trade CAD Value',
            'Trade ID',
        ];
    }

    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'type' => $this->type,
            'pair' => $this->pair,
            'price' => $this->price,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'fee_currency' => $this->fee_currency,
            'fee_amount_base' => $this->fee_amount_base,
            'fee_amount_quote' => $this->fee_amount_quote,
            'fee_cad_value' => $this->fee_cad_value,
            'trade_cad_value' => $this->trade_cad_value,
            'trade_id' => $this->trade_id,
        ];
    }
}
