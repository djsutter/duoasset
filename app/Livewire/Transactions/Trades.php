<?php

namespace App\Livewire\Transactions;

use App\Enums\TransactionType;
use App\Services\WalletService;
use App\Types\Money;
use Livewire\Component;

class Trades extends Component
{
    public function render(WalletService $walletService)
    {
        $transactions = $walletService->getExternalTransactions();

        // Find potential trades using CAD + time proximity
        $cadTolerance = Money::fromDecimal('10.00', 'CAD');
        $timeWindow = 3600 * 24; // 24 hours

        $sends = $transactions->filter(fn ($tx) => $tx->tx_type === TransactionType::Send)->values();
        $receives = $transactions->filter(fn ($tx) => $tx->tx_type === TransactionType::Receive)->values();

        $trades = [];

        foreach ($sends as $sendIndex => $send) {
            $bestMatch = null;
            $bestScore = null;

            foreach ($receives as $recvIndex => $recv) {
                // CAD difference
                if (is_null($send->amount) || is_null($recv->amount)) {
                    continue;
                }
                $cadDiff = $send->amount->add($recv->amount)->abs();

                if ($cadDiff->greaterThan($cadTolerance)) {
                    continue;
                }

                // Time difference
                $timeDiff = abs($send->transaction_at->getTimestamp() - $recv->transaction_at->getTimestamp());
                if ($timeDiff > $timeWindow) {
                    continue;
                }

                // Pick the closest match
                if (is_null($bestScore) || $cadDiff->lessThan($bestScore)) {
                    $bestScore = $cadDiff;
                    $bestMatch = $recvIndex;
                }
            }

            if (! is_null($bestMatch)) {
                $trades[] = [
                    'send' => $send,
                    'receive' => $receives[$bestMatch],
                    'cad_diff' => $bestScore,
                ];

                // Remove matched receive
                $receives->forget($bestMatch);
            }
        }

        return view('livewire.transactions.trades', [
            'trades' => $trades,
        ]);
    }
}
