<?php

use App\Livewire\Stocks\Index as StocksIndex;
use App\Models\Industry;
use App\Models\Sector;
use App\Models\Stock;
use App\Models\SubIndustry;
use App\Models\User;
use App\Services\MarketData\MarketDataProvider;
use App\Services\Stocks\StockProvisioner;
use Database\Seeders\ClassificationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('stock provisioner resolves correct sector, industry, and sub-industry across different sectors', function () {
    $this->seed(ClassificationSeeder::class);

    $provisioner = new StockProvisioner;

    // 1. Energy
    $energyStock = $provisioner->findOrCreate(
        symbol: 'XOM',
        exchange: 'NYSE',
        companyName: 'Exxon Mobil Corporation',
        sector: 'Energy',
        industry: 'Oil & Gas',
        subIndustry: 'Exploration & Production'
    );

    expect($energyStock->sector->name)->toBe('Energy')
        ->and($energyStock->industry->name)->toBe('Oil & Gas')
        ->and($energyStock->subIndustry->name)->toBe('Exploration & Production');

    // 2. Financials (with alias "Financial Services")
    $financialStock = $provisioner->findOrCreate(
        symbol: 'JPM',
        exchange: 'NYSE',
        companyName: 'JPMorgan Chase & Co.',
        sector: 'Financial Services',
        industry: 'Banks',
        subIndustry: 'Regional Banks'
    );

    expect($financialStock->sector->name)->toBe('Financials')
        ->and($financialStock->industry->name)->toBe('Banks')
        ->and($financialStock->subIndustry->name)->toBe('Regional Banks');

    // 3. Healthcare
    $healthStock = $provisioner->findOrCreate(
        symbol: 'LLY',
        exchange: 'NYSE',
        companyName: 'Eli Lilly and Company',
        sector: 'Healthcare',
        industry: 'Pharmaceuticals'
    );

    expect($healthStock->sector->name)->toBe('Healthcare')
        ->and($healthStock->industry->name)->toBe('Pharmaceuticals');

    // 4. Technology
    $techStock = $provisioner->findOrCreate(
        symbol: 'NVDA',
        exchange: 'NASDAQ',
        companyName: 'NVIDIA Corporation',
        sector: 'Technology',
        industry: 'Semiconductors',
        subIndustry: 'GPU Manufacturers'
    );

    expect($techStock->sector->name)->toBe('Technology')
        ->and($techStock->industry->name)->toBe('Semiconductors')
        ->and($techStock->subIndustry->name)->toBe('GPU Manufacturers');

    // Ensure they are NOT all Technology / Software / AI Infrastructure
    expect($energyStock->sector_id)->not->toBe($techStock->sector_id)
        ->and($financialStock->sector_id)->not->toBe($techStock->sector_id)
        ->and($healthStock->sector_id)->not->toBe($techStock->sector_id);
});

test('stock provisioner maps sector aliases accurately', function () {
    $this->seed(ClassificationSeeder::class);

    $provisioner = new StockProvisioner;

    expect($provisioner->resolveSector('Basic Materials')->slug)->toBe('materials')
        ->and($provisioner->resolveSector('Financial Services')->slug)->toBe('financials')
        ->and($provisioner->resolveSector('Consumer Cyclical')->slug)->toBe('consumer')
        ->and($provisioner->resolveSector('Consumer Defensive')->slug)->toBe('consumer')
        ->and($provisioner->resolveSector('Communication Services')->slug)->toBe('telecommunications')
        ->and($provisioner->resolveSector('Information Technology')->slug)->toBe('technology')
        ->and($provisioner->resolveSector('Health Care')->slug)->toBe('healthcare');
});

test('stock provisioner uses unknown classification when no sector is provided and no provider exists', function () {
    $this->seed(ClassificationSeeder::class);

    $provisioner = new StockProvisioner;

    $unknownStock = $provisioner->findOrCreate(
        symbol: 'UNKNOWN_CO',
        exchange: 'NYSE',
        companyName: 'Unknown Mystery Corp'
    );

    expect($unknownStock->sector->name)->toBe('Unknown')
        ->and($unknownStock->sector->slug)->toBe('unknown')
        ->and($unknownStock->industry->name)->toBe('Unknown')
        ->and($unknownStock->sector->name)->not->toBe('Technology');
});

test('stock provisioner queries market data provider profile when metadata is missing', function () {
    $this->seed(ClassificationSeeder::class);

    $mockProvider = Mockery::mock(MarketDataProvider::class);
    $mockProvider->shouldReceive('profile')
        ->with('CAT')
        ->once()
        ->andReturn([
            'symbol' => 'CAT',
            'company_name' => 'Caterpillar Inc.',
            'exchange' => 'NYSE',
            'sector' => 'Industrials',
            'industry' => 'Construction',
            'sub_industry' => null,
            'currency' => 'USD',
        ]);

    $provisioner = new StockProvisioner($mockProvider);

    $stock = $provisioner->findOrCreate('CAT');

    expect($stock->symbol)->toBe('CAT')
        ->and($stock->company_name)->toBe('Caterpillar Inc.')
        ->and($stock->sector->name)->toBe('Industrials')
        ->and($stock->industry->name)->toBe('Construction');
});

test('stock provisioner can refresh classification of an existing stock', function () {
    $this->seed(ClassificationSeeder::class);

    $provisioner = new StockProvisioner;

    // Create a stock initially with Unknown or broken classification
    $stock = Stock::factory()->create([
        'symbol' => 'XOM',
        'company_name' => 'Exxon Mobil',
        'sector_id' => Sector::where('slug', 'technology')->first()->id,
        'industry_id' => Industry::where('slug', 'software')->first()->id,
        'sub_industry_id' => SubIndustry::where('slug', 'ai-infrastructure')->first()->id,
    ]);

    expect($stock->sector->slug)->toBe('technology');

    // Refresh classification to Energy / Oil & Gas
    $refreshed = $provisioner->refreshClassification(
        $stock,
        sector: 'Energy',
        industry: 'Oil & Gas',
        subIndustry: 'Exploration & Production'
    );

    expect($refreshed->sector->name)->toBe('Energy')
        ->and($refreshed->industry->name)->toBe('Oil & Gas')
        ->and($refreshed->subIndustry->name)->toBe('Exploration & Production');
});

test('stocks view displays distinct sectors and allows filtering by sector', function () {
    $this->seed(ClassificationSeeder::class);

    $user = User::factory()->create();

    $energySector = Sector::where('slug', 'energy')->first();
    $energyIndustry = Industry::where('sector_id', $energySector->id)->first();
    $energySub = SubIndustry::where('industry_id', $energyIndustry->id)->first();

    $healthSector = Sector::where('slug', 'healthcare')->first();
    $healthIndustry = Industry::where('sector_id', $healthSector->id)->first();
    $healthSub = SubIndustry::where('industry_id', $healthIndustry->id)->first();

    $stock1 = Stock::factory()->create([
        'symbol' => 'XOM',
        'company_name' => 'Exxon Mobil',
        'sector_id' => $energySector->id,
        'industry_id' => $energyIndustry->id,
        'sub_industry_id' => $energySub->id,
    ]);

    $stock2 = Stock::factory()->create([
        'symbol' => 'LLY',
        'company_name' => 'Eli Lilly',
        'sector_id' => $healthSector->id,
        'industry_id' => $healthIndustry->id,
        'sub_industry_id' => $healthSub->id,
    ]);

    $component = Livewire::actingAs($user)
        ->test(StocksIndex::class);

    // Initial view should contain both Energy and Healthcare
    $component->assertSee('Energy')
        ->assertSee('Healthcare')
        ->assertSee('XOM')
        ->assertSee('LLY');

    // Filter by Energy
    $component->set('filterSectorId', $energySector->id);
    $stocks = $component->viewData('stocks');

    expect($stocks->pluck('symbol'))->toContain('XOM')
        ->and($stocks->pluck('symbol'))->not->toContain('LLY');
});

test('classification refresh migration updates existing misclassified stocks', function () {
    $this->seed(ClassificationSeeder::class);

    $techSector = Sector::where('slug', 'technology')->first();
    $softwareIndustry = Industry::where('slug', 'software')->first();
    $aiSubIndustry = SubIndustry::where('slug', 'ai-infrastructure')->first();

    // Create misclassified stocks
    $jpm = Stock::factory()->create([
        'symbol' => 'JPM',
        'company_name' => 'JPMorgan Chase & Co.',
        'sector_id' => $techSector->id,
        'industry_id' => $softwareIndustry->id,
        'sub_industry_id' => $aiSubIndustry->id,
    ]);

    $xom = Stock::factory()->create([
        'symbol' => 'XOM',
        'company_name' => 'Exxon Mobil',
        'sector_id' => $techSector->id,
        'industry_id' => $softwareIndustry->id,
        'sub_industry_id' => $aiSubIndustry->id,
    ]);

    $migration = require database_path('migrations/2026_08_24_191000_refresh_stocks_classification.php');
    $migration->up();

    $jpmFresh = $jpm->fresh();
    $xomFresh = $xom->fresh();

    expect($jpmFresh->sector->name)->toBe('Financials')
        ->and($jpmFresh->industry->name)->toBe('Banks')
        ->and($xomFresh->sector->name)->toBe('Energy')
        ->and($xomFresh->industry->name)->toBe('Oil & Gas');
});
