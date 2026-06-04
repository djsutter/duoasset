<?php

namespace App\Livewire\Tax;

use App\Enums\AcbEventType;
use App\Models\AcbEvent;
use App\Models\TaxPool;
use App\Services\Tax\TaxPoolLedgerBuilder;
use App\Types\Money;
use Carbon\CarbonImmutable;
use Livewire\Component;

class PoolLedger extends Component
{
    public string $assetCode = '';

    public $assets = [];

    public $year;

    // cache the ledger for the current asset
    private ?array $ledgerCache = null;

    private ?string $cachedAssetCode = null;

    public function mount()
    {
        $this->assets = TaxPool::orderBy('asset_code')->pluck('asset_code')->toArray();
        $this->year = $this->yearOptions[0] ?? now()->year;
    }

    public function updatedAssetCode(): void
    {
        // reset cache when user changes asset
        $this->ledgerCache = null;
        $this->cachedAssetCode = $this->assetCode;
    }

    public function getLedgerEntriesProperty(): array
    {
        if (empty($this->assetCode)) {
            return [];
        }

        // return cached version if available
        if ($this->ledgerCache !== null && $this->cachedAssetCode === $this->assetCode) {
            return $this->ledgerCache;
        }

        $from = CarbonImmutable::create($this->year, 1, 1)->startOfDay();
        $to = CarbonImmutable::create($this->year, 12, 31)->endOfDay();

        $builder = app(TaxPoolLedgerBuilder::class);

        $fullLedger = iterator_to_array(
            $builder->buildForAssetUpToDate($this->assetCode, $to),
            false
        );

        // Filter entries for display only
        $this->ledgerCache = array_values(
            array_filter(
                $fullLedger,
                fn ($entry) => $entry->event_date >= $from
            )
        );

        $this->cachedAssetCode = $this->assetCode;

        return $this->ledgerCache;
    }

    public function getTotalsProperty(): array
    {
        $currency = getReportingCurrency();
        $zero = Money::zero($currency);

        $proceeds = $zero;
        $acbAllocated = $zero;
        $deniedLoss = $zero;
        $gainBeforeDenial = $zero;

        foreach ($this->ledgerEntries as $entry) {
            $proceeds = $proceeds->add($entry->proceeds ?? $zero);
            $acbAllocated = $acbAllocated->add($entry->acb_allocated ?? $zero);
            $gainBeforeDenial = $gainBeforeDenial->add($entry->capital_gain_loss_before_denial ?? $zero);
            $deniedLoss = $deniedLoss->add($entry->denied_loss ?? $zero);
        }

        // Derive instead of summing
        $acbReportable = $acbAllocated->subtract($deniedLoss);
        $capitalGain = $proceeds->subtract($acbReportable);

        return [
            'proceeds' => $proceeds,
            'acb_allocated' => $acbAllocated,
            'gain_before_denial' => $gainBeforeDenial,
            'denied_loss' => $deniedLoss,
            'capital_gain' => $capitalGain,
            'acb_reportable' => $acbReportable,
        ];
    }

    public function getYearOptionsProperty(): array
    {
        $years = AcbEvent::where('event_type', AcbEventType::Disposal)
            ->selectRaw('MIN(YEAR(event_at)) as min_year, MAX(YEAR(event_at)) as max_year')
            ->first();

        if (! $years || ! $years->min_year) {
            return [now()->year];
        }

        return range($years->max_year, $years->min_year);
    }

    public function render()
    {
        return view('livewire.tax.pool-ledger');
    }
}
