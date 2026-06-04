<?php

namespace App\Livewire\Reports;

use App\Data\Reports\CraCapitalGainsReportData;
use App\Data\Reports\LedgerCapitalGainsReportData;
use App\Enums\AcbEventType;
use App\Models\AcbEvent;
use App\Models\Asset;
use App\Services\Reports\CraCapitalGainsReportService;
use App\Services\Reports\LedgerCapitalGainsReportService;
use App\Traits\SendsNotifications;
use Livewire\Component;

class CapitalGains extends Component
{
    use SendsNotifications;

    /** @var string[] */
    public array $assetCodes = [];

    /** @var int[] */
    public array $taxYears = [];

    public bool $allAssets = false;

    public bool $allTaxYears = false;

    public string $reportType = self::REPORT_LEDGER;

    public bool $isLoading = false;

    protected ?LedgerCapitalGainsReportData $ledgerReport = null;

    protected ?CraCapitalGainsReportData $craReport = null;

    public const REPORT_LEDGER = 'ledger';

    public const REPORT_CRA = 'cra';

    public function getAssetOptionsProperty(): array
    {
        return Asset::all()->map(function ($asset) {
            return (object) [
                'asset_code' => $asset->asset_code,
                'asset_name' => $asset->currency->name,
            ];
        })->sortBy('asset_code')->toArray();
    }

    protected function getCraReport(): ?CraCapitalGainsReportData
    {
        if ($this->craReport) {
            return $this->craReport;
        }

        try {
            $this->isLoading = true;

            return $this->craReport = app(CraCapitalGainsReportService::class)
                ->forAssets($this->assetCodes)
                ->forTaxYears($this->taxYears)
                ->build();

        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return null;
        } finally {
            $this->isLoading = false;
        }
    }

    protected function getLedgerReport(): ?LedgerCapitalGainsReportData
    {
        if ($this->ledgerReport) {
            return $this->ledgerReport;
        }

        try {
            $this->isLoading = true;

            return $this->ledgerReport = app(LedgerCapitalGainsReportService::class)
                ->forAssets($this->assetCodes)
                ->forTaxYears($this->taxYears)
                ->build();

        } catch (\Throwable $e) {
            report($e);
            $this->error($e->getMessage());

            return null;
        } finally {
            $this->isLoading = false;
        }
    }

    public function getReportProperty()
    {
        if ($this->assetCodes === [] || $this->taxYears === []) {
            return null;
        }

        return match ($this->reportType) {
            self::REPORT_LEDGER => $this->getLedgerReport(),
            self::REPORT_CRA => $this->getCraReport(),
            default => null,
        };
    }

    public function getReportTypesProperty(): array
    {
        return [
            self::REPORT_LEDGER => 'Ledger',
            self::REPORT_CRA => 'CRA',
        ];
    }

    public function getTaxYearOptionsProperty(): array
    {
        $event = AcbEvent::where('event_type', AcbEventType::Disposal)->orderBy('event_at')->first();
        $startYear = $event ? $event->event_at->year : now()->year;
        $event = AcbEvent::where('event_type', AcbEventType::Disposal)->orderBy('event_at', 'desc')->first();
        $endYear = $event ? $event->event_at->year : now()->year;

        return range($endYear, $startYear);
    }

    protected function invalidateReport(): void
    {
        $this->ledgerReport = null;
        $this->craReport = null;
    }

    public function render()
    {
        return view('livewire.reports.capital-gains');
    }

    public function updatedAllAssets(bool $value): void
    {
        if ($value) {
            $this->assetCodes = collect($this->assetOptions)
                ->pluck('asset_code')
                ->all();
        } else {
            $this->assetCodes = [];
        }

        $this->invalidateReport();
    }

    public function updatedAllTaxYears(bool $value): void
    {
        if ($value) {
            $this->taxYears = $this->taxYearOptions;
        } else {
            $this->taxYears = [];
        }

        $this->invalidateReport();
    }

    public function updatedAssetCodes(): void
    {
        if (! empty($this->assetCodes)) {
            $this->allAssets = false;
        }

        $this->invalidateReport();
    }

    public function updatedTaxYears(): void
    {
        if (! empty($this->taxYears)) {
            $this->allTaxYears = false;
        }

        $this->invalidateReport();
    }

    public function updatedReportType(): void
    {
        $this->invalidateReport();
    }
}
