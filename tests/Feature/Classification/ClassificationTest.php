<?php

use App\Enums\Currency;
use App\Enums\Exchange;
use App\Models\Industry;
use App\Models\Sector;
use App\Models\SubIndustry;
use Database\Seeders\ClassificationSeeder;

test('a sector can be created', function () {
    $sector = Sector::factory()->create([
        'name' => 'Technology',
        'slug' => 'technology',
        'sort_order' => 0,
    ]);

    expect($sector->name)->toBe('Technology')
        ->and($sector->slug)->toBe('technology')
        ->and($sector->sort_order)->toBe(0);

    $this->assertDatabaseHas('sectors', ['slug' => 'technology']);
});

test('an industry can be created and belongs to a sector', function () {
    $sector = Sector::factory()->create();
    $industry = Industry::factory()->create(['sector_id' => $sector->id]);

    expect($industry->sector)->not->toBeNull()
        ->and($industry->sector->id)->toBe($sector->id)
        ->and($sector->fresh()->industries)->toHaveCount(1);
});

test('a sub industry can be created and belongs to an industry', function () {
    $industry = Industry::factory()->create();
    $sub = SubIndustry::factory()->create(['industry_id' => $industry->id]);

    expect($sub->industry)->not->toBeNull()
        ->and($sub->industry->id)->toBe($industry->id)
        ->and($industry->fresh()->subIndustries)->toHaveCount(1);
});

test('relationship integrity: sub industry -> industry -> sector', function () {
    $sector = Sector::factory()->create();
    $industry = Industry::factory()->create(['sector_id' => $sector->id]);
    $sub = SubIndustry::factory()->create(['industry_id' => $industry->id]);

    expect($sub->industry->sector->id)->toBe($sector->id);
});

test('deleting a sector cascades to industries and sub industries', function () {
    $sector = Sector::factory()->create();
    $industry = Industry::factory()->create(['sector_id' => $sector->id]);
    $sub = SubIndustry::factory()->create(['industry_id' => $industry->id]);

    $sector->delete();

    $this->assertDatabaseMissing('industries', ['id' => $industry->id]);
    $this->assertDatabaseMissing('sub_industries', ['id' => $sub->id]);
});

test('classification seeder populates sectors, industries and sub industries', function () {
    $this->seed(ClassificationSeeder::class);

    expect(Sector::count())->toBe(10);

    $this->assertDatabaseHas('sectors', ['name' => 'Technology', 'sort_order' => 0]);
    $this->assertDatabaseHas('sectors', ['name' => 'Real Estate']);

    $tech = Sector::where('slug', 'technology')->firstOrFail();
    $this->assertDatabaseHas('industries', ['sector_id' => $tech->id, 'name' => 'Software']);

    $software = Industry::where('slug', 'software')->firstOrFail();
    $this->assertDatabaseHas('sub_industries', ['industry_id' => $software->id, 'name' => 'AI Infrastructure']);
});

test('classification seeder is idempotent', function () {
    $this->seed(ClassificationSeeder::class);
    $sectorsAfterFirst = Sector::count();
    $industriesAfterFirst = Industry::count();
    $subsAfterFirst = SubIndustry::count();

    $this->seed(ClassificationSeeder::class);

    expect(Sector::count())->toBe($sectorsAfterFirst)
        ->and(Industry::count())->toBe($industriesAfterFirst)
        ->and(SubIndustry::count())->toBe($subsAfterFirst);
});

test('exchange enum exposes required cases', function () {
    expect(Exchange::NYSE->value)->toBe('NYSE')
        ->and(Exchange::NASDAQ->value)->toBe('NASDAQ')
        ->and(Exchange::TSX->value)->toBe('TSX')
        ->and(Exchange::TSXV->value)->toBe('TSXV')
        ->and(Exchange::CBOE->value)->toBe('CBOE')
        ->and(Exchange::OTC->value)->toBe('OTC')
        ->and(Exchange::ASX->value)->toBe('ASX')
        ->and(Exchange::LSE->value)->toBe('LSE')
        ->and(Exchange::FRA->value)->toBe('FRA')
        ->and(Exchange::tryFrom('NYSE'))->toBe(Exchange::NYSE)
        ->and(Exchange::tryFrom('NOT_A_REAL_EXCHANGE'))->toBeNull()
        ->and(count(Exchange::cases()))->toBe(9);
});

test('currency enum exposes required cases', function () {
    expect(Currency::USD->value)->toBe('USD')
        ->and(Currency::CAD->value)->toBe('CAD')
        ->and(Currency::EUR->value)->toBe('EUR')
        ->and(Currency::GBP->value)->toBe('GBP')
        ->and(Currency::AUD->value)->toBe('AUD')
        ->and(Currency::tryFrom('USD'))->toBe(Currency::USD)
        ->and(Currency::tryFrom('XYZ'))->toBeNull()
        ->and(count(Currency::cases()))->toBe(5);
});
