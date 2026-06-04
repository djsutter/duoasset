<?php

namespace App\Data;

use App\Models\Invest\Account as InvestAccount;
use App\Types\Money;

class InvestAccountData
{
    public function __construct(
        public int $accountId,
        public string $name,
        public string $currency,
        public ?Money $balance = null,
        public ?Money $prevBalance = null,
    ) {
        if (is_null($balance)) {
            $this->balance = new Money('0.00', $currency);
            $this->prevBalance = $this->balance;
        }
    }

    public static function fromModel(InvestAccount $account): self
    {
        return new self(
            $account->id,
            $account->name,
            $account->currency,
        );
    }

    public function toArray(): array
    {
        return [
            'accountId' => $this->accountId,
            'name' => $this->name,
            'currency' => $this->currency,
            'balance' => $this->balance->format(),
        ];
    }
}
