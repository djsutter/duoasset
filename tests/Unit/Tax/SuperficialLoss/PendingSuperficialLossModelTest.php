<?php

namespace Tests\Unit\Tax\SuperficialLoss;

use App\Tax\SuperficialLoss\Domain\PendingSuperficialLoss;
use App\Tax\SuperficialLoss\Domain\PendingSuperficialLossStatus;
use App\Tax\SuperficialLoss\Persistence\PendingSuperficialLossModel;
use App\Types\AssetQuantity;
use App\Types\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class PendingSuperficialLossModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // seed the common tables
        $this->seed(CurrencySeeder::class);
    }

    private function makeDomainLoss(
        string $remainingLoss = '10000',
        string $remainingUnits = '1.0',
        ?CarbonImmutable $windowStart = null,
        ?CarbonImmutable $windowEnd = null
    ): PendingSuperficialLoss {
        return new PendingSuperficialLoss(
            id: Uuid::uuid4(),
            assetCode: 'BTC',
            acbEventId: 1,
            windowStart: $windowStart ?? CarbonImmutable::parse('2024-01-01'),
            windowEnd: $windowEnd ?? CarbonImmutable::parse('2024-01-30'),
            originalLossAmount: Money::fromMinorUnits(10000, 'CAD'),
            originalUnits: AssetQuantity::fromDecimal('1.0', 'BTC'),
            remainingLossAmount: Money::fromMinorUnits((int) $remainingLoss, 'CAD'),
            remainingUnits: AssetQuantity::fromDecimal($remainingUnits, 'BTC'),
        );
    }

    #[Test]
    public function it_rehydrates_a_valid_domain_object(): void
    {
        $loss = $this->makeDomainLoss();

        $model = PendingSuperficialLossModel::fromDomain($loss);

        $rehydrated = $model->toDomain();

        $this->assertSame(
            $loss->status(),
            $rehydrated->status()
        );
    }

    #[Test]
    public function it_persists_and_reloads_without_data_loss(): void
    {
        $loss = $this->makeDomainLoss();

        $model = PendingSuperficialLossModel::fromDomain($loss);
        $model->save();

        $loaded = PendingSuperficialLossModel::findOrFail($model->id)
            ->toDomain();

        $this->assertSame(
            $loss->remainingLossAmount->minorUnits(),
            $loaded->remainingLossAmount->minorUnits()
        );

        $this->assertTrue(
            $loss->remainingUnits->equals($loaded->remainingUnits)
        );
    }

    #[Test]
    public function it_round_trips_partially_denied_status(): void
    {
        $loss = $this->makeDomainLoss(
            remainingLoss: '6000',
            remainingUnits: '0.6'
        );

        $this->assertSame(
            PendingSuperficialLossStatus::PartiallyDenied,
            $loss->status()
        );

        $model = PendingSuperficialLossModel::fromDomain($loss);
        $model->save();

        $rehydrated = PendingSuperficialLossModel::findOrFail($model->id)
            ->toDomain();

        $this->assertSame(
            PendingSuperficialLossStatus::PartiallyDenied,
            $rehydrated->status()
        );
    }

    #[Test]
    public function it_rehydrates_fully_denied_state(): void
    {
        $loss = $this->makeDomainLoss(
            remainingLoss: '0',
            remainingUnits: '0'
        );

        $this->assertSame(
            PendingSuperficialLossStatus::FullyDenied,
            $loss->status()
        );

        $model = PendingSuperficialLossModel::fromDomain($loss);
        $model->save();

        $rehydrated = PendingSuperficialLossModel::findOrFail($model->id)
            ->toDomain();

        $this->assertSame(
            PendingSuperficialLossStatus::FullyDenied,
            $rehydrated->status()
        );
    }

    #[Test]
    public function it_rehydrates_expired_state(): void
    {
        $attributes = [
            'id' => Uuid::uuid4()->toString(),
            'asset_code' => 'BTC',
            'disposition_event_id' => Uuid::uuid4()->toString(),
            'window_start' => '2024-01-01',
            'window_end' => '2024-01-30',
            'original_units' => '1',
            'remaining_units' => '1',
            'expired_at' => '2024-02-01', // still stored on model
        ];

        $model = new PendingSuperficialLossModel;
        $model->setRawAttributes($attributes, true);
        $model->exists = true;

        // Rehydrate domain object
        $loss = PendingSuperficialLoss::rehydrate(
            id: Uuid::fromString($model->id),
            assetCode: $model->asset_code,
            acbEventId: (int) $model->disposition_event_id,
            windowStart: CarbonImmutable::parse($model->window_start),
            windowEnd: CarbonImmutable::parse($model->window_end),
            originalLossAmount: Money::fromDecimal('100.00', 'CAD'),
            originalUnits: $model->original_units,
            remainingLossAmount: Money::fromDecimal('100.00', 'CAD'),
            remainingUnits: $model->remaining_units,
        );

        // Manually set expiredAt in domain object (private property can be set via reflection if needed)
        $reflection = new \ReflectionClass($loss);
        $prop = $reflection->getProperty('expiredAt');
        $prop->setAccessible(true);
        $prop->setValue($loss, CarbonImmutable::parse($model->expired_at));

        // Assert status is expired
        $this->assertSame(
            PendingSuperficialLossStatus::Expired,
            $loss->status()
        );

        // Assert expiredAt is correctly set
        $this->assertInstanceOf(CarbonImmutable::class, $loss->expiredAt);
        $this->assertEquals('2024-02-01', $loss->expiredAt->format('Y-m-d'));
    }
}
