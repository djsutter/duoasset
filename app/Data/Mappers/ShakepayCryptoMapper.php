<?php

namespace App\Data\Mappers;

use App\Data\Imports\ShakepayCryptoRowData;
use App\Data\Imports\StageEntryImportData;
use App\Data\Imports\StageTransactionImportData;
use App\Enums\TransactionType;
use App\Models\StageTransaction;
use App\Services\WalletService;

class ShakepayCryptoMapper extends BaseMapper
{
    protected string $platformName = 'Shakepay';

    public function __construct(protected WalletService $walletService, ?string $walletName = null)
    {
        parent::__construct($walletService, $walletName);
    }

    public function map(ShakepayCryptoRowData $dto): ?StageTransactionImportData
    {
        return match ($dto->type) {
            'Buy' => $this->mapBuyTransaction($dto),
            'Sell' => $this->mapSellTransaction($dto),
            'Receive', 'Reward' => $this->mapReceiveTransaction($dto),
            'Send' => $this->mapSendTransaction($dto),
            default => null,
        };
    }

    private function mapBuyTransaction(ShakepayCryptoRowData $dto): ?StageTransactionImportData
    {
        $bookCost = $this->toMoney($dto->book_cost, $dto->book_cost_currency);
        $credit = $this->toMoney($dto->amount_credited, $dto->asset_credited);
        $toWallet = $this->walletService->getWallet($this->platform, $credit->currency);
        $fromWallet = $this->walletService->getWallet($this->platform, $bookCost->currency);

        $txDto = new StageTransactionImportData(
            tx_type: TransactionType::Trade,
            tx_at: $this->parseDate($dto->date),
            status: StageTransaction::STATUS_MATCHED,
            description: $dto->description,
            source: $this->platformName,
        );

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'out',
            wallet_id: $fromWallet->id,
            amount: $bookCost->negated(),
        ));

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'in',
            wallet_id: $toWallet->id,
            amount: $bookCost,
            foreign_amount: $credit,
        ));

        return $txDto;
    }

    private function mapSellTransaction(ShakepayCryptoRowData $dto): ?StageTransactionImportData
    {
        $bookCost = $this->toMoney($dto->book_cost, $dto->book_cost_currency);
        $debit = $this->toMoney($dto->amount_debited, $dto->asset_debited);
        $fromWallet = $this->walletService->getWallet($this->platform, $debit->currency);
        $toWallet = $this->walletService->getWallet($this->platform, $bookCost->currency);

        $txDto = new StageTransactionImportData(
            tx_type: TransactionType::Trade,
            tx_at: $this->parseDate($dto->date),
            status: StageTransaction::STATUS_MATCHED,
            description: $dto->description,
            source: $this->platformName,
        );

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'out',
            wallet_id: $fromWallet->id,
            amount: $bookCost->negated(),
            foreign_amount: $debit->negated(),
        ));

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'in',
            wallet_id: $toWallet->id,
            amount: $bookCost,
        ));

        return $txDto;
    }

    private function mapReceiveTransaction(ShakepayCryptoRowData $dto): ?StageTransactionImportData
    {
        $bookCost = $this->toMoney($dto->book_cost, $dto->book_cost_currency);
        $credit = $this->toMoney($dto->amount_credited, $dto->asset_credited);
        $toWallet = $this->walletService->getWallet($this->platform, $credit->currency);
        $description = $dto->type == 'Reward'
            ? "Reward: $dto->description"
            : "Receive {$credit->currency} into {$this->platformName}";

        $txDto = new StageTransactionImportData(
            tx_type: TransactionType::Receive,
            tx_at: $this->parseDate($dto->date),
            status: StageTransaction::STATUS_UNMATCHED,
            description: $description,
            source: $this->platformName,
            // is_income: $dto->type === 'Reward',
        );

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'in',
            wallet_id: $toWallet->id,
            amount: $bookCost,
            foreign_amount: $credit,
        ));

        return $txDto;
    }

    private function mapSendTransaction(ShakepayCryptoRowData $dto): ?StageTransactionImportData
    {
        $bookCost = $this->toMoney($dto->book_cost, $dto->book_cost_currency);
        $debit = $this->toMoney($dto->amount_debited, $dto->asset_debited);
        $fromWallet = $this->walletService->getWallet($this->platform, $debit->currency);
        // Extract the recipient address
        preg_match('/^.+ address ([^,]+)/i', $dto->description, $matches);
        $address = $matches[1] ?? null;

        $txDto = new StageTransactionImportData(
            tx_type: TransactionType::Send,
            tx_at: $this->parseDate($dto->date),
            status: StageTransaction::STATUS_UNMATCHED,
            description: "Send {$debit->currency} from {$this->platformName}",
            address: $address,
            source: $this->platformName,
        );

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'out',
            wallet_id: $fromWallet->id,
            amount: $bookCost->negated(),
            foreign_amount: $debit->negated(),
        ));

        return $txDto;
    }
}
