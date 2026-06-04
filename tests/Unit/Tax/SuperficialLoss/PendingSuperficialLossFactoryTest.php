<?php

namespace Tests\Unit\Tax\SuperficialLoss;

use App\Tax\SuperficialLoss\Domain\PendingSuperficialLoss;
use App\Tax\SuperficialLoss\Domain\PendingSuperficialLossStatus;
use App\Tax\SuperficialLoss\Exceptions\InvalidSuperficialLossCreation;
use App\Types\AssetQuantity;
use App\Types\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PendingSuperficialLossFactoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CurrencySeeder::class);
    }

    #[Test]
    public function it_creates_a_pending_superficial_loss_from_a_disposition(): void
    {
        $loss = PendingSuperficialLoss::createFromDisposition(
            acbEventId: 1,
            assetCode: 'BTC',
            superficialLoss: Money::fromMinorUnits(10_000, 'CAD'),
            superficialUnits: AssetQuantity::fromDecimal('1.0', 'BTC'),
            dispositionDate: CarbonImmutable::parse('2024-01-01')
        );

        $this->assertInstanceOf(PendingSuperficialLoss::class, $loss);
        $this->assertSame(PendingSuperficialLossStatus::Pending, $loss->status());
        $this->assertTrue($loss->remainingLossAmount->equals(Money::fromMinorUnits(10_000, 'CAD')));
        $this->assertTrue($loss->remainingUnits->equals(AssetQuantity::fromDecimal('1.0', 'BTC')));
    }

    #[Test]
    public function it_sets_the_30_day_superficial_loss_window(): void
    {
        $loss = PendingSuperficialLoss::createFromDisposition(
            acbEventId: 1,
            assetCode: 'BTC',
            superficialLoss: Money::fromMinorUnits(1_000, 'CAD'),
            superficialUnits: AssetQuantity::fromDecimal('0.1', 'BTC'),
            dispositionDate: CarbonImmutable::parse('2024-06-15')
        );

        $this->assertTrue(
            $loss->windowStart->equalTo(CarbonImmutable::parse('2024-06-15'))
        );

        $this->assertTrue(
            $loss->windowEnd->equalTo(CarbonImmutable::parse('2024-07-15'))
        );
    }

    #[Test]
    public function it_rejects_negative_superficial_loss(): void
    {
        $this->expectException(InvalidSuperficialLossCreation::class);

        PendingSuperficialLoss::createFromDisposition(
            acbEventId: 1,
            assetCode: 'BTC',
            superficialLoss: Money::fromMinorUnits(-100, 'CAD'),
            superficialUnits: AssetQuantity::fromDecimal('0.1', 'BTC'),
            dispositionDate: CarbonImmutable::parse('2024-01-01')
        );
    }

    #[Test]
    public function it_does_not_allow_callers_to_force_status(): void
    {
        $loss = PendingSuperficialLoss::createFromDisposition(
            acbEventId: 1,
            assetCode: 'BTC',
            superficialLoss: Money::fromMinorUnits(5_000, 'CAD'),
            superficialUnits: AssetQuantity::fromDecimal('0.5', 'BTC'),
            dispositionDate: CarbonImmutable::parse('2024-01-01')
        );

        $this->assertSame(PendingSuperficialLossStatus::Pending, $loss->status());
    }
}
