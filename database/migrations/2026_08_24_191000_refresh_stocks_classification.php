<?php

use App\Models\Stock;
use App\Services\MarketData\MarketDataProvider;
use App\Services\Stocks\StockProvisioner;
use Database\Seeders\ClassificationSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Common stock symbol => [Sector, Industry, SubIndustry] dictionary
     * for instant, deterministic offline classification refresh.
     *
     * @var array<string, array{0: string, 1: string, 2?: string}>
     */
    private array $knownStocks = [
        // Technology
        'AAPL' => ['Technology', 'Consumer Electronics'],
        'MSFT' => ['Technology', 'Software', 'Enterprise Software'],
        'NVDA' => ['Technology', 'Semiconductors', 'GPU Manufacturers'],
        'AMD' => ['Technology', 'Semiconductors', 'AI Accelerators'],
        'INTC' => ['Technology', 'Semiconductors'],
        'AVGO' => ['Technology', 'Semiconductors'],
        'QCOM' => ['Technology', 'Semiconductors'],
        'TXN' => ['Technology', 'Semiconductors'],
        'ARM' => ['Technology', 'Semiconductors', 'AI Accelerators'],
        'TSM' => ['Technology', 'Semiconductors'],
        'ASML' => ['Technology', 'Semiconductors'],
        'MU' => ['Technology', 'Semiconductors'],
        'AMAT' => ['Technology', 'Semiconductors'],
        'LRCX' => ['Technology', 'Semiconductors'],
        'KLAC' => ['Technology', 'Semiconductors'],
        'ORCL' => ['Technology', 'Software', 'Database Software'],
        'CRM' => ['Technology', 'Software', 'Enterprise Software'],
        'ADBE' => ['Technology', 'Software', 'Enterprise Software'],
        'NOW' => ['Technology', 'Software', 'Enterprise Software'],
        'PANW' => ['Technology', 'Cybersecurity'],
        'CRWD' => ['Technology', 'Cybersecurity'],
        'FTNT' => ['Technology', 'Cybersecurity'],
        'SNOW' => ['Technology', 'Cloud Computing'],
        'CSCO' => ['Technology', 'Data Infrastructure', 'Networking'],
        'ANET' => ['Technology', 'Data Infrastructure', 'Networking'],
        'IBM' => ['Technology', 'Software', 'Enterprise Software'],
        'PLTR' => ['Technology', 'Software', 'AI Infrastructure'],

        // Financials
        'JPM' => ['Financials', 'Banks', 'Regional Banks'],
        'BAC' => ['Financials', 'Banks', 'Regional Banks'],
        'WFC' => ['Financials', 'Banks', 'Regional Banks'],
        'C' => ['Financials', 'Banks'],
        'GS' => ['Financials', 'Banks'],
        'MS' => ['Financials', 'Banks'],
        'RY' => ['Financials', 'Banks'],
        'TD' => ['Financials', 'Banks'],
        'BMO' => ['Financials', 'Banks'],
        'BNS' => ['Financials', 'Banks'],
        'CM' => ['Financials', 'Banks'],
        'BLK' => ['Financials', 'Asset Management', 'Wealth Management'],
        'SCHW' => ['Financials', 'Asset Management', 'Wealth Management'],
        'BX' => ['Financials', 'Asset Management'],
        'BRK.A' => ['Financials', 'Insurance'],
        'BRK.B' => ['Financials', 'Insurance'],
        'PGR' => ['Financials', 'Insurance'],
        'CB' => ['Financials', 'Insurance'],
        'MET' => ['Financials', 'Insurance', 'Life Insurance'],
        'PRU' => ['Financials', 'Insurance', 'Life Insurance'],
        'V' => ['Financials', 'Asset Management'],
        'MA' => ['Financials', 'Asset Management'],
        'AXP' => ['Financials', 'Banks'],

        // Energy
        'XOM' => ['Energy', 'Oil & Gas', 'Exploration & Production'],
        'CVX' => ['Energy', 'Oil & Gas', 'Exploration & Production'],
        'COP' => ['Energy', 'Oil & Gas', 'Exploration & Production'],
        'EOG' => ['Energy', 'Oil & Gas', 'Exploration & Production'],
        'OXY' => ['Energy', 'Oil & Gas', 'Exploration & Production'],
        'SLB' => ['Energy', 'Oilfield Services', 'Drilling Services'],
        'HAL' => ['Energy', 'Oilfield Services', 'Drilling Services'],
        'BKR' => ['Energy', 'Oilfield Services', 'Drilling Services'],
        'KMI' => ['Energy', 'Midstream', 'Pipelines'],
        'WMB' => ['Energy', 'Midstream', 'Pipelines'],
        'EPD' => ['Energy', 'Midstream', 'Pipelines'],
        'ET' => ['Energy', 'Midstream', 'Pipelines'],
        'TRP' => ['Energy', 'Midstream', 'Pipelines'],
        'ENB' => ['Energy', 'Midstream', 'Pipelines'],
        'CCJ' => ['Energy', 'Uranium', 'Uranium Producers'],
        'DNN' => ['Energy', 'Uranium', 'Uranium Producers'],
        'NXE' => ['Energy', 'Uranium', 'Uranium Producers'],
        'UEC' => ['Energy', 'Uranium', 'Uranium Producers'],

        // Healthcare
        'LLY' => ['Healthcare', 'Pharmaceuticals'],
        'JNJ' => ['Healthcare', 'Pharmaceuticals'],
        'UNH' => ['Healthcare', 'Medical Devices'],
        'PFE' => ['Healthcare', 'Pharmaceuticals'],
        'MRK' => ['Healthcare', 'Pharmaceuticals'],
        'ABBV' => ['Healthcare', 'Biotechnology', 'Antibody Therapeutics'],
        'AMGN' => ['Healthcare', 'Biotechnology', 'Antibody Therapeutics'],
        'GILD' => ['Healthcare', 'Biotechnology'],
        'VRTX' => ['Healthcare', 'Biotechnology', 'Gene Therapy'],
        'BIIB' => ['Healthcare', 'Biotechnology', 'Gene Therapy'],
        'CRSP' => ['Healthcare', 'Biotechnology', 'Gene Therapy'],
        'TMO' => ['Healthcare', 'Medical Devices', 'Diagnostics'],
        'ABT' => ['Healthcare', 'Medical Devices', 'Diagnostics'],
        'DHR' => ['Healthcare', 'Medical Devices', 'Diagnostics'],
        'MDT' => ['Healthcare', 'Medical Devices'],
        'ISRG' => ['Healthcare', 'Medical Devices'],

        // Consumer
        'AMZN' => ['Consumer', 'E-Commerce'],
        'WMT' => ['Consumer', 'Retail'],
        'COST' => ['Consumer', 'Retail'],
        'TGT' => ['Consumer', 'Retail'],
        'HD' => ['Consumer', 'Retail'],
        'LOW' => ['Consumer', 'Retail'],
        'MCD' => ['Consumer', 'Restaurants'],
        'SBUX' => ['Consumer', 'Restaurants'],
        'CMG' => ['Consumer', 'Restaurants'],
        'NKE' => ['Consumer', 'Retail'],
        'PG' => ['Consumer', 'Retail'],
        'KO' => ['Consumer', 'Retail'],
        'PEP' => ['Consumer', 'Retail'],
        'TSLA' => ['Consumer', 'Retail'],

        // Industrials
        'BA' => ['Industrials', 'Aerospace & Defense', 'Commercial Aerospace'],
        'LMT' => ['Industrials', 'Aerospace & Defense', 'Defense Contractors'],
        'RTX' => ['Industrials', 'Aerospace & Defense', 'Defense Contractors'],
        'NOC' => ['Industrials', 'Aerospace & Defense', 'Defense Contractors'],
        'GD' => ['Industrials', 'Aerospace & Defense', 'Defense Contractors'],
        'CAT' => ['Industrials', 'Construction'],
        'DE' => ['Industrials', 'Construction'],
        'UNP' => ['Industrials', 'Transportation', 'Rail Transportation'],
        'CSX' => ['Industrials', 'Transportation', 'Rail Transportation'],
        'NSC' => ['Industrials', 'Transportation', 'Rail Transportation'],
        'CP' => ['Industrials', 'Transportation', 'Rail Transportation'],
        'CNI' => ['Industrials', 'Transportation', 'Rail Transportation'],
        'HON' => ['Industrials', 'Aerospace & Defense'],
        'GE' => ['Industrials', 'Aerospace & Defense', 'Commercial Aerospace'],
        'UPS' => ['Industrials', 'Transportation'],
        'FDX' => ['Industrials', 'Transportation'],

        // Materials
        'FCX' => ['Materials', 'Copper', 'Copper Producers'],
        'SCCO' => ['Materials', 'Copper', 'Copper Producers'],
        'HBM' => ['Materials', 'Copper', 'Copper Producers'],
        'CS' => ['Materials', 'Copper', 'Copper Developers'],
        'NEM' => ['Materials', 'Gold'],
        'GOLD' => ['Materials', 'Gold'],
        'AEM' => ['Materials', 'Gold'],
        'KGC' => ['Materials', 'Gold'],
        'PAAS' => ['Materials', 'Silver'],
        'AG' => ['Materials', 'Silver'],
        'ALB' => ['Materials', 'Lithium', 'Lithium Producers'],
        'SQM' => ['Materials', 'Lithium', 'Lithium Producers'],
        'MP' => ['Materials', 'Rare Earths', 'Rare Earth Producers'],
        'NTR' => ['Materials', 'Fertilizers'],
        'MOS' => ['Materials', 'Fertilizers'],
        'CF' => ['Materials', 'Fertilizers'],
        'NUE' => ['Materials', 'Steel'],
        'STLD' => ['Materials', 'Steel'],
        'CLF' => ['Materials', 'Steel'],
        'X' => ['Materials', 'Steel'],

        // Telecommunications
        'VZ' => ['Telecommunications', 'Wireless'],
        'T' => ['Telecommunications', 'Wireless'],
        'TMUS' => ['Telecommunications', 'Wireless'],
        'GOOG' => ['Telecommunications', 'Data Centers'],
        'GOOGL' => ['Telecommunications', 'Data Centers'],
        'META' => ['Telecommunications', 'Data Centers'],
        'NFLX' => ['Telecommunications', 'Data Centers'],
        'DIS' => ['Telecommunications', 'Wireless'],
        'CMCSA' => ['Telecommunications', 'Fiber'],
        'CHTR' => ['Telecommunications', 'Fiber'],
        'EQIX' => ['Telecommunications', 'Data Centers'],
        'DLR' => ['Telecommunications', 'Data Centers'],

        // Utilities
        'NEE' => ['Utilities', 'Renewable Energy'],
        'DUK' => ['Utilities', 'Power Generation'],
        'SO' => ['Utilities', 'Power Generation'],
        'AEP' => ['Utilities', 'Power Generation'],
        'SRE' => ['Utilities', 'Power Generation'],
        'EXC' => ['Utilities', 'Power Generation'],
        'XEL' => ['Utilities', 'Renewable Energy'],
        'CEG' => ['Utilities', 'Power Generation'],

        // Real Estate
        'PLD' => ['Real Estate', 'REITs', 'Industrial REITs'],
        'AMT' => ['Real Estate', 'REITs'],
        'CCI' => ['Real Estate', 'REITs'],
        'SPG' => ['Real Estate', 'REITs'],
        'O' => ['Real Estate', 'REITs'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('stocks')) {
            return;
        }

        $stocks = Stock::all();
        if ($stocks->isEmpty()) {
            return;
        }

        // Ensure classification tree is populated
        if (\App\Models\Sector::query()->count() === 0) {
            (new ClassificationSeeder)->run();
        }

        $provisioner = App::make(StockProvisioner::class);
        $provider = App::bound(MarketDataProvider::class) ? App::make(MarketDataProvider::class) : null;

        // Iterate all existing stocks and re-classify them properly
        foreach ($stocks as $stock) {
            $symbol = strtoupper(trim($stock->symbol));
            $sector = null;
            $industry = null;
            $subIndustry = null;

            if (isset($this->knownStocks[$symbol])) {
                $sector = $this->knownStocks[$symbol][0];
                $industry = $this->knownStocks[$symbol][1];
                $subIndustry = $this->knownStocks[$symbol][2] ?? null;
            } elseif ($provider !== null) {
                try {
                    $profile = $provider->profile($symbol);
                    if (is_array($profile)) {
                        $sector = $profile['sector'] ?? null;
                        $industry = $profile['industry'] ?? null;
                        $subIndustry = $profile['sub_industry'] ?? null;
                    }
                } catch (\Throwable) {
                    // Ignore
                }
            }

            $classification = $provisioner->resolveClassification($sector, $industry, $subIndustry);

            $stock->update([
                'sector_id' => $classification['sector_id'],
                'industry_id' => $classification['industry_id'],
                'sub_industry_id' => $classification['sub_industry_id'],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No destructive reverse needed
    }
};
