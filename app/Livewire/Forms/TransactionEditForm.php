<?php

namespace App\Livewire\Forms;

use App\Data\Transactions\TransactionEditDataFactory;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Rules\NumericWithComma;
use App\Services\TransactionService;
use App\Traits\NormalizesNumericFields;
use Livewire\Form;

class TransactionEditForm extends Form
{
    use NormalizesNumericFields;

    public ?int $transaction_id = null;

    public ?TransactionType $tx_type = null;

    public ?string $transaction_at = null;

    public ?int $platform_id = null;

    public ?string $src_amount = null;

    public ?string $src_currency = null;

    public ?int $src_wallet_id = null;

    public ?string $dst_amount = null;

    public ?string $dst_currency = null;

    public ?int $dst_wallet_id = null;

    public ?string $fee_amount = null;

    public ?string $fee_currency = null;

    public ?string $description = null;

    public ?string $address = null;

    public function init(?Transaction $transaction = null): void
    {
        $this->reset();
        $this->resetErrorBag();

        if (! $transaction) {
            return;
        }

        $this->transaction_id = $transaction->id;
        $this->tx_type = $transaction->tx_type;
        $this->transaction_at = $transaction->transaction_at->format('Y-m-d\TH:i');
        $this->description = $transaction->description;

        match ($transaction->tx_type) {
            TransactionType::Receive => $this->initReceive($transaction),
            TransactionType::Send => $this->initSend($transaction),
            TransactionType::Trade => $this->initTrade($transaction),
            TransactionType::Transfer => $this->initTransfer($transaction)
        };
    }

    private function initReceive(Transaction $transaction): void
    {
        $reportingCurrency = getReportingCurrency();
        foreach ($transaction->entries as $entry) {
            switch ($entry->entry_type) {
                case 'in':
                    $this->dst_wallet_id = $entry->wallet?->id;
                    if ($entry->wallet->currency == $reportingCurrency) {
                        $this->dst_amount = $entry->amount?->format();
                        $this->dst_currency = $entry->amount?->currency;
                    } else {
                        $this->dst_amount = $entry->foreign_amount?->format();
                        $this->dst_currency = $entry->foreign_amount?->currency;
                    }
                    break;
            }
        }
    }

    private function initSend(Transaction $transaction): void
    {
        $reportingCurrency = getReportingCurrency();
        foreach ($transaction->entries as $entry) {
            switch ($entry->entry_type) {
                case 'out':
                    $this->src_wallet_id = $entry->wallet?->id;
                    $this->address = $entry->address;
                    if ($entry->wallet->currency == $reportingCurrency) {
                        $this->src_amount = $entry->amount?->negated()->format();
                        $this->src_currency = $entry->amount?->currency;
                    } else {
                        $this->src_amount = $entry->foreign_amount?->negated()->format();
                        $this->src_currency = $entry->foreign_amount?->currency;
                    }
                    break;
                case 'fee':
                    if ($entry->foreign_amount) {
                        $this->fee_amount = $entry->foreign_amount->negated()->format();
                        $this->fee_currency = $entry->foreign_amount->currency;
                    } else {
                        $this->fee_amount = $entry->amount->negated()->format();
                        $this->fee_currency = $entry->amount->currency;
                    }
                    break;
            }
        }
    }

    private function initTrade(Transaction $transaction): void
    {
        $reportingCurrency = getReportingCurrency();
        foreach ($transaction->entries as $entry) {
            switch ($entry->entry_type) {
                case 'in':
                    if ($entry->wallet->currency == $reportingCurrency) {
                        $this->dst_amount = $entry->amount?->format();
                        $this->dst_currency = $entry->amount?->currency;
                    } else {
                        $this->dst_amount = $entry->foreign_amount?->format();
                        $this->dst_currency = $entry->foreign_amount?->currency;
                    }
                    break;
                case 'out':
                    $this->platform_id = $entry->wallet->platform->id;
                    if ($entry->wallet->currency == $reportingCurrency) {
                        $this->src_amount = $entry->amount?->negated()->format();
                        $this->src_currency = $entry->amount?->currency;
                    } else {
                        $this->src_amount = $entry->foreign_amount?->negated()->format();
                        $this->src_currency = $entry->foreign_amount?->currency;
                    }
                    break;
                case 'fee':
                    if ($entry->foreign_amount) {
                        $this->fee_amount = $entry->foreign_amount->negated()->format();
                        $this->fee_currency = $entry->foreign_amount->currency;
                    } else {
                        $this->fee_amount = $entry->amount->negated()->format();
                        $this->fee_currency = $entry->amount->currency;
                    }
                    break;
            }
        }
    }

    private function initTransfer(Transaction $transaction): void
    {
        $reportingCurrency = getReportingCurrency();
        foreach ($transaction->entries as $entry) {
            switch ($entry->entry_type) {
                case 'in':
                    $this->dst_wallet_id = $entry->wallet?->id;
                    break;
                case 'out':
                    $this->src_wallet_id = $entry->wallet->id;
                    if ($entry->wallet->currency == $reportingCurrency) {
                        $this->src_amount = $entry->amount?->negated()->format();
                        $this->src_currency = $entry->amount?->currency;
                    } else {
                        $this->src_amount = $entry->foreign_amount?->negated()->format();
                        $this->src_currency = $entry->foreign_amount?->currency;
                    }
                    break;
                case 'fee':
                    if ($entry->foreign_amount) {
                        $this->fee_amount = $entry->foreign_amount->negated()->format();
                        $this->fee_currency = $entry->foreign_amount->currency;
                    } else {
                        $this->fee_amount = $entry->amount->negated()->format();
                        $this->fee_currency = $entry->amount->currency;
                    }
                    break;
            }
        }
    }

    public function rules(): array
    {
        $rules = [
            'receive' => [
                'transaction_at' => ['required', 'date'],
                'dst_wallet_id' => ['required', 'exists:wallets,id'],
                'dst_amount' => ['required', new NumericWithComma],
                'dst_currency' => ['required', 'string'],
                'fee_amount' => ['nullable', new NumericWithComma],
                'fee_currency' => ['nullable', 'string'],
                'description' => ['required', 'string', 'max:255'],
            ],
            'send' => [
                'transaction_at' => ['required', 'date'],
                'src_wallet_id' => ['required', 'exists:wallets,id'],
                'src_amount' => ['required', new NumericWithComma],
                'fee_amount' => ['nullable', new NumericWithComma],
                'fee_currency' => ['nullable', 'string'],
                'description' => ['required', 'string', 'max:255'],
                'address' => ['nullable', 'string', 'max:255'],
            ],
            'trade' => [
                'transaction_at' => ['required', 'date'],
                'platform_id' => ['nullable', 'exists:platforms,id'],
                'src_amount' => ['required', new NumericWithComma],
                'src_currency' => ['required', 'string'],
                'dst_amount' => ['required', new NumericWithComma],
                'dst_currency' => ['required', 'string', 'different:src_currency'],
                'fee_amount' => ['nullable', new NumericWithComma],
                'fee_currency' => ['nullable', 'string'],
                'description' => ['required', 'string', 'max:255'],
            ],
            'transfer' => [
                'transaction_at' => ['required', 'date'],
                'src_wallet_id' => ['required', 'exists:wallets,id', 'different:dst_wallet_id'],
                'dst_wallet_id' => ['required', 'exists:wallets,id'],
                'src_amount' => ['required', new NumericWithComma],
                'src_currency' => ['required', 'string'],
                'fee_amount' => ['nullable', new NumericWithComma],
                'fee_currency' => ['nullable', 'string'],
                'description' => ['required', 'string', 'max:255'],
            ],
        ];

        return $rules[$this->tx_type->value];
    }

    public function save(): void
    {
        $data = $this->validate();
        $data = $this->normalizeNumericFields($data, ['src_amount', 'dst_amount', 'fee_amount']);

        $dto = TransactionEditDataFactory::fromArray(array_merge($data, [
            'tx_type' => $this->tx_type,
            'id' => $this->transaction_id,
        ]));

        $transaction = app(TransactionService::class)->save($dto);

        $this->transaction_id = $transaction->id;
    }
}
