<?php

namespace App\Livewire\Transactions;

use App\Enums\TransactionType;
use App\Livewire\Forms\TransactionEditForm;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Traits\SendsNotifications;
use Livewire\Component;

class EditModal extends Component
{
    use SendsNotifications;

    public TransactionEditForm $form;

    public bool $showModal = false;

    public ?int $transaction_id = null;

    public ?TransactionType $tx_type = null;

    protected $listeners = [
        'edit-transaction' => 'onEditTransaction',
        'refresh' => '$refresh',
    ];

    public function delete(int $transaction_id): void
    {
        if ($transaction = Transaction::find($transaction_id)) {
            $transaction->delete();
            $this->showModal = false;
            $this->dispatch('refresh');
        }
    }

    public function onEditTransaction(?int $transactionId = null, ?array $init = null): void
    {
        $transaction = null;
        $transactionId && $transaction = Transaction::findOrFail($transactionId);
        if ($transaction) {
            $this->tx_type = $transaction->tx_type;
        } elseif (isset($init['type'])) {
            $this->tx_type = TransactionType::tryFrom($init['type']) ?? TransactionType::Trade;
        } else {
            $this->tx_type = TransactionType::Trade;
        }
        $this->showModal = true;
        $this->transaction_id = $transactionId;
        $this->form->init($transaction);

        if (isset($init['date'])) {
            $this->form->transaction_at = $init['date'].' 00:00:00';
        }
        if (isset($init['walletId'])) {
            $this->form->dst_wallet_id = $init['walletId'];
        }
        if (isset($init['amount'])) {
            if ($this->tx_type == TransactionType::Send) {
                $this->form->src_amount = $init['amount'];
                $this->form->src_currency = $init['currency'];
            } elseif ($this->tx_type == TransactionType::Receive) {
                $this->form->dst_amount = $init['amount'];
                $this->form->dst_currency = $init['currency'];
            }
        }
        if (isset($init['description'])) {
            $this->form->description = $init['description'];
        }
    }

    public function render()
    {
        return view('livewire.transactions.edit-modal');
    }

    public function setTransactionType(string $type): void
    {
        if ($txType = TransactionType::tryFrom($type)) {
            $this->tx_type = $txType;
            $this->form->tx_type = $txType;
        }
    }

    public function save(): void
    {
        $this->form->save();
        $this->showModal = false;
        $this->success('Transaction updated.');
        $this->dispatch('refresh');
    }

    public function updatedDstWalletId($value): void
    {
        if ($dst_wallet = Wallet::find($value)) {
            $this->form->dst_currency = $dst_wallet->currency ?? null;
        }
    }

    public function updatedSrcWalletId($value): void
    {
        if ($src_wallet = Wallet::find($value)) {
            $this->form->src_currency = $src_wallet->currency;
        }
        $this->form->fee_currency = $this->src_currency;
        // $this->form->dst_wallet_id = null;
    }
}
