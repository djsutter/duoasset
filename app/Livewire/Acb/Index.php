<?php

namespace App\Livewire\Acb;

use App\Models\Asset;
use App\Models\WalletEntry;
use App\Services\Acb\AcbEngine;
use App\Support\CurrencyRegistry;
use App\Traits\SendsNotifications;
use App\Types\AssetQuantity;
use App\Types\Money;
use Livewire\Component;

class Index extends Component
{
    use SendsNotifications;

    // optional: for pagination toggle later
    public int $perPage = 100;

    public function render()
    {
        // Load assets with the Money casts already defined on the model.
        // We'll eager-load nothing else for now.
        $assets = Asset::orderBy('asset_code')->get();

        return view('livewire.acb.index', [
            'assets' => $assets,
        ]);
    }

    /**
     * Rebuild ACB for a single asset
     */
    public function rebuildAsset(int $assetId): void
    {
        $asset = Asset::find($assetId);
        if (! $asset) {
            session()->flash('error', 'Asset not found');

            return;
        }

        $engine = app(AcbEngine::class);
        $engine->rebuildAsset($asset);

        $this->success("ACB rebuilt for {$asset->asset_code}");
    }

    public function rebuildAllAssets(): void
    {
        $currencies = WalletEntry::select('foreign_currency')->distinct('foreign_currency')->whereNotNull('foreign_currency')->orderBy('foreign_currency')->get()->pluck('foreign_currency');
        $reportingCurrency = getReportingCurrency();

        try {
            $engine = app(AcbEngine::class);
            foreach ($currencies as $currency) {
                $asset = Asset::firstOrCreate(
                    ['asset_code' => $currency],
                    [
                        'acb_currency' => $reportingCurrency,
                        'precision' => self::inferPrecisionFromCurrency($currency),
                        'acb' => Money::zero($reportingCurrency),
                        'quantity' => AssetQuantity::zero($currency),
                        'total_proceeds' => Money::zero($reportingCurrency),
                        'total_cost' => Money::zero($reportingCurrency),
                    ]
                );
                $engine->rebuildAsset($asset);
            }

            $this->success('ACB rebuilt for all assets ('.count($currencies).')');
        } catch (\Throwable $e) {
            $this->error('Failed to rebuild all assets: '.$e->getMessage());
        }
    }

    /**
     * Helper to refresh component (optional)
     */
    public function refreshComponent()
    {
        $this->reset();
    }

    private static function inferPrecisionFromCurrency(string $currencyCode): int
    {
        if (! $currency = CurrencyRegistry::get($currencyCode)) {
            throw new \LogicException('Cannot infer asset precision');
        }

        return $currency->display_scale;
    }
}
