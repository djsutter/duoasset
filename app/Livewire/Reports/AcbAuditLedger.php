<?php

namespace App\Livewire\Reports;

use App\Models\Asset;
use App\Services\Reports\Acb\AssetAcbAuditLedgerResult;
use App\Services\Reports\Acb\AssetAcbAuditLedgerService;
use App\Services\Reports\Acb\CapitalGainsOptions;
use App\Traits\SendsNotifications;
use Livewire\Component;

final class AcbAuditLedger extends Component
{
    use SendsNotifications;

    public ?string $assetCode = null;

    protected ?AssetAcbAuditLedgerResult $auditLedgerCache;

    public function getAssetOptionsProperty(): array
    {
        return Asset::all()->map(function ($asset) {
            return (object) [
                'asset_code' => $asset->asset_code,
                'asset_name' => $asset->currency->name,
            ];
        })->sortBy('asset_code')->toArray();
    }

    public function getAuditLedgerProperty(): ?AssetAcbAuditLedgerResult
    {
        if ($this->assetCode === null) {
            return null;
        }

        if ($this->auditLedgerCache) {
            return $this->auditLedgerCache;
        }

        try {
            $asset = Asset::where('asset_code', $this->assetCode)->firstOrFail();

            $this->auditLedgerCache = app(AssetAcbAuditLedgerService::class)
                ->forAsset($asset)
                ->withOptions(new CapitalGainsOptions(
                    applySuperficialLoss: true,
                    explainSuperficialLoss: true,
                ))
                ->build();

            return $this->auditLedgerCache;
        } catch (\Throwable $e) {
            report($e);
            $this->error($e->getMessage());

            return null;
        }
    }

    protected function invalidateReport(): void
    {
        $this->auditLedgerCache = null;
    }

    public function render()
    {
        return view('livewire.reports.acb-audit-ledger');
    }

    public function updatedAssetCode(): void
    {
        $this->invalidateReport();
    }
}
