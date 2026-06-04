<?php

namespace Tests\Unit\Tax\SuperficialLoss;

use App\Tax\Events\AcquisitionEvent;
use App\Tax\SuperficialLoss\Domain\PendingSuperficialLoss;
use App\Tax\SuperficialLoss\Domain\PendingSuperficialLossStatus;
use App\Tax\SuperficialLoss\Policies\CraSuperficialLossMatchingPolicy;
use App\Types\AssetQuantity;
use App\Types\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CraSuperficialLossMatchingPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // seed the common tables
        $this->seed(CurrencySeeder::class);
    }

    #[Test]
    public function it_does_not_match_acquisitions_outside_the_window(): void
    {
        $policy = new CraSuperficialLossMatchingPolicy;

        $loss = PendingSuperficialLoss::createFromDisposition(
            acbEventId: 1,
            assetCode: 'BTC',
            superficialLoss: Money::fromMinorUnits(10000, 'CAD'),
            superficialUnits: AssetQuantity::fromDecimal('1.0', 'BTC'),
            dispositionDate: CarbonImmutable::parse('2024-01-01'),
        );

        $acquisition = new AcquisitionEvent(
            id: 1,
            assetCode: 'BTC',
            date: CarbonImmutable::parse('2024-02-01'),
            quantity: new AssetQuantity('1.0', 'BTC'),
            costAmount: Money::fromMinorUnits(20000, 'CAD')
        );

        $result = $policy->match($loss, $acquisition);

        $this->assertTrue($result->isEmpty());
    }

    #[Test]
    public function it_does_not_match_different_assets(): void
    {
        $policy = new CraSuperficialLossMatchingPolicy;

        $loss = $this->makePendingLoss();

        $acquisition = new AcquisitionEvent(
            id: 1,
            assetCode: 'ETH',
            date: CarbonImmutable::parse('2024-01-10'),
            quantity: AssetQuantity::fromDecimal('1.0', 'ETH'),
            costAmount: Money::fromMinorUnits(20000, 'CAD')
        );

        $result = $policy->match($loss, $acquisition);

        $this->assertTrue($result->isEmpty());
    }

    #[Test]
    public function it_partially_matches_units_and_denies_loss_proportionally(): void
    {
        $policy = new CraSuperficialLossMatchingPolicy;

        $loss = $this->makePendingLoss(
            loss: Money::fromMinorUnits(10_000, 'CAD'),
            units: AssetQuantity::fromDecimal('1.0', 'BTC')
        );

        $acquisition = new AcquisitionEvent(
            id: 1,
            assetCode: 'BTC',
            date: CarbonImmutable::parse('2024-01-10'),
            quantity: new AssetQuantity('0.4', 'BTC'),
            costAmount: Money::fromMinorUnits(20000, 'CAD')
        );

        $result = $policy->match($loss, $acquisition);

        $this->assertTrue($result->matchedUnits->equals(AssetQuantity::fromDecimal('0.4', 'BTC')));
        $this->assertTrue($result->deniedLoss->equals(Money::fromMinorUnits(4_000, 'CAD')));
    }

    #[Test]
    public function it_fully_matches_and_denies_the_entire_loss(): void
    {
        $policy = new CraSuperficialLossMatchingPolicy;

        $loss = $this->makePendingLoss();

        $acquisition = new AcquisitionEvent(
            id: 1,
            assetCode: 'BTC',
            date: CarbonImmutable::parse('2024-01-15'),
            quantity: new AssetQuantity('5.0', 'BTC'),
            costAmount: Money::fromMinorUnits(20000, 'CAD')
        );

        $result = $policy->match($loss, $acquisition);

        $this->assertTrue($result->matchedUnits->equals(AssetQuantity::fromDecimal('1.0', 'BTC')));
        $this->assertTrue($result->deniedLoss->equals(Money::fromMinorUnits(10_000, 'CAD')));
    }

    #[Test]
    public function it_does_not_mutate_the_pending_loss(): void
    {
        $policy = new CraSuperficialLossMatchingPolicy;

        $loss = $this->makePendingLoss();

        $acquisition = new AcquisitionEvent(
            id: 1,
            assetCode: 'BTC',
            date: CarbonImmutable::parse('2024-01-10'),
            quantity: new AssetQuantity('0.5', 'BTC'),
            costAmount: Money::fromMinorUnits(20000, 'CAD')
        );

        $policy->match($loss, $acquisition);

        $this->assertSame(
            PendingSuperficialLossStatus::Pending,
            $loss->status()
        );

        $this->assertTrue(
            $loss->remainingUnits->equals(AssetQuantity::fromDecimal('1.0', 'BTC'))
        );
    }

    private function makePendingLoss(
        ?Money $loss = null,
        ?AssetQuantity $units = null
    ): PendingSuperficialLoss {
        $loss ??= Money::fromMinorUnits(10_000, 'CAD');
        $units ??= AssetQuantity::fromDecimal('1.0', 'BTC');

        return PendingSuperficialLoss::createFromDisposition(
            acbEventId: 1,
            assetCode: 'BTC',
            superficialLoss: $loss,
            superficialUnits: $units,
            dispositionDate: CarbonImmutable::parse('2024-01-01'),
        );
    }
}
