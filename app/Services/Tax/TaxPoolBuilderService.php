<?php

namespace App\Services\Tax;

use App\Domain\Tax\Disposition\DispositionPolicyApplier;
use App\Domain\Tax\Pool\PoolManager;
use App\Enums\TaxPoolLedgerEntryType;
use App\Models\AcbEvent;

final class TaxPoolBuilderService
{
    public function __construct(
        private PoolManager $poolManager,
        private DispositionPolicyApplier $policyApplier,
    ) {}

    public function rebuild(): void
    {
        $this->poolManager->reset();

        AcbEvent::query()
            ->orderBy('asset_code')
            ->orderBy('event_at')
            ->orderBy('id')
            ->cursor()
            ->each(function ($event) {
                $taxType = $event->getTaxEventType();

                if ($taxType === TaxPoolLedgerEntryType::Acquisition) {
                    $this->poolManager->processAcquisition($event);

                    return;
                }

                if ($taxType === TaxPoolLedgerEntryType::Disposition) {
                    $dispositionResult = $this->poolManager->processDisposition($event);
                    $finalDispositionResult = $this->policyApplier->apply($dispositionResult, $event);
                    $this->poolManager->commitDisposition($event, $finalDispositionResult);
                }
            });

        $this->poolManager->finalize();
    }
}
