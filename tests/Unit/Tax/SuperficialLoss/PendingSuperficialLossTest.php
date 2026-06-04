<?php

namespace Tests\Unit\Tax\SuperficialLoss;

use App\Tax\SuperficialLoss\Domain\PendingSuperficialLoss;
use App\Tax\SuperficialLoss\Domain\PendingSuperficialLossStatus;
use App\Tax\SuperficialLoss\Exceptions\ExcessiveLossDenial;
use App\Tax\SuperficialLoss\Exceptions\InvalidSuperficialLossTransition;
use App\Types\AssetQuantity;
use App\Types\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class PendingSuperficialLossTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed required tables (currencies, etc.)
        $this->seed(CurrencySeeder::class);
    }

    /**
     * Helper to create a PendingSuperficialLoss with specific remaining loss/units
     */
    private function makeLoss(
        string $assetCode = 'BTC',
        string|int $remainingLoss = '10000', // minor units
        string $remainingUnits = '1.0',
        ?Money $originalLoss = null,
        ?AssetQuantity $originalUnits = null
    ): PendingSuperficialLoss {
        $remainingLossInt = (int) $remainingLoss;
        $originalLoss ??= Money::fromMinorUnits($remainingLossInt, 'CAD');
        $originalUnits ??= AssetQuantity::fromDecimal($remainingUnits, $assetCode);

        return new PendingSuperficialLoss(
            id: Uuid::uuid4(),
            assetCode: $assetCode,
            acbEventId: 1,
            windowStart: CarbonImmutable::parse('2024-01-01'),
            windowEnd: CarbonImmutable::parse('2024-01-30'),
            originalLossAmount: $originalLoss,
            originalUnits: $originalUnits,
            remainingLossAmount: Money::fromMinorUnits($remainingLossInt, 'CAD'),
            remainingUnits: AssetQuantity::fromDecimal($remainingUnits, $assetCode)
        );
    }

    private function makeDomainLoss(
        ?Money $loss = null,
        ?AssetQuantity $units = null,
        ?CarbonImmutable $windowStart = null,
        ?CarbonImmutable $windowEnd = null
    ): PendingSuperficialLoss {
        return PendingSuperficialLoss::createFromDisposition(
            1,
            'BTC',
            $loss ?? Money::fromDecimal('100.00', 'CAD'),
            $units ?? AssetQuantity::fromDecimal('1', 'BTC'),
            $windowStart ?? CarbonImmutable::parse('2024-01-01'),
            $windowEnd ?? CarbonImmutable::parse('2024-01-30')
        );
    }

    #[Test]
    public function it_allows_partial_denial_from_pending(): void
    {
        $loss = $this->makeLoss(
            remainingLoss: 10000,
            remainingUnits: '1.0'
        );

        $loss->deny(
            Money::fromMinorUnits('4000', 'CAD'),
            AssetQuantity::fromDecimal('0.4', 'BTC')
        );

        $this->assertSame(
            PendingSuperficialLossStatus::PartiallyDenied,
            $loss->status()
        );
    }

    #[Test]
    public function it_transitions_to_fully_denied_when_remaining_loss_is_zero(): void
    {
        $loss = $this->makeLoss(
            remainingLoss: 10000,
            remainingUnits: '1.0'
        );

        $loss->deny(
            Money::fromMinorUnits('10000', 'CAD'),
            AssetQuantity::fromDecimal('1.0', 'BTC')
        );

        $this->assertSame(
            PendingSuperficialLossStatus::FullyDenied,
            $loss->status()
        );
    }

    #[Test]
    public function it_prevents_denial_after_expiry(): void
    {
        $loss = $this->makeLoss(
            remainingLoss: 10000,
            remainingUnits: '1.0'
        );
        $loss->expireIfNeeded($loss->windowEnd->addDay());

        $this->expectException(InvalidSuperficialLossTransition::class);

        $loss->deny(
            Money::fromMinorUnits('1000', 'CAD'),
            AssetQuantity::fromDecimal('0.1', 'BTC')
        );
    }

    #[Test]
    public function it_prevents_denial_after_full_denial(): void
    {
        $loss = $this->makeLoss(
            remainingLoss: 10000,
            remainingUnits: '1.0'
        );

        // fully deny it
        $loss->deny(
            Money::fromMinorUnits(10000, 'CAD'),
            AssetQuantity::fromDecimal('1.0', 'BTC')
        );

        $this->expectException(InvalidSuperficialLossTransition::class);

        $loss->deny(
            Money::fromMinorUnits(100, 'CAD'),
            AssetQuantity::fromDecimal('0.1', 'BTC')
        );
    }

    #[Test]
    public function it_prevents_excessive_loss_denial(): void
    {
        $loss = $this->makeLoss(
            remainingLoss: 10000,
            remainingUnits: '1.0'
        );

        $this->expectException(ExcessiveLossDenial::class);

        $loss->deny(
            Money::fromMinorUnits('20000', 'CAD'),
            AssetQuantity::fromDecimal('0.5', 'BTC')
        );
    }

    #[Test]
    public function it_prevents_excessive_unit_denial(): void
    {
        $loss = $this->makeLoss(
            remainingLoss: 10000,
            remainingUnits: '1.0'
        );

        $this->expectException(ExcessiveLossDenial::class);

        $loss->deny(
            Money::fromMinorUnits('5000', 'CAD'),
            AssetQuantity::fromDecimal('2.0', 'BTC')
        );
    }

    #[Test]
    public function it_allows_expiry_from_pending(): void
    {
        $loss = $this->makeLoss(
            remainingLoss: 10000,
            remainingUnits: '1.0'
        );

        $loss->expireIfNeeded($loss->windowEnd->addDay());

        $this->assertSame(
            PendingSuperficialLossStatus::Expired,
            $loss->status()
        );
    }

    #[Test]
    public function it_prevents_expiry_after_full_denial(): void
    {
        $loss = $this->makeLoss(
            remainingLoss: 10000,
            remainingUnits: '1.0'
        );

        // Fully deny the loss
        $loss->deny(
            Money::fromMinorUnits(10000, 'CAD'),
            AssetQuantity::fromDecimal('1.0', 'BTC')
        );

        $this->expectException(InvalidSuperficialLossTransition::class);

        // Attempt expiry after full denial
        $loss->expireIfNeeded($loss->windowEnd->addDay());
    }

    public function it_cannot_deny_more_than_remaining(): void
    {
        $this->expectException(ExcessiveLossDenial::class);

        $loss = $this->makeDomainLoss();

        // Attempt to deny more than remaining loss
        $loss->deny(Money::fromDecimal('200.00', 'CAD'), AssetQuantity::fromDecimal('1', 'BTC'));
    }

    public function it_updates_status_on_partial_deny(): void
    {
        $loss = $this->makeDomainLoss();

        $loss->deny(Money::fromDecimal('50.00', 'CAD'), AssetQuantity::fromDecimal('0.5', 'BTC'));

        $this->assertSame(PendingSuperficialLossStatus::PartiallyDenied, $loss->status());
        $this->assertEquals('50.00', $loss->remainingLossAmount->toDecimal());
        $this->assertEquals('0.5', $loss->remainingUnits->toDecimal());
    }

    public function it_updates_status_on_full_deny(): void
    {
        $loss = $this->makeDomainLoss();

        $loss->deny(Money::fromDecimal('100.00', 'CAD'), AssetQuantity::fromDecimal('1', 'BTC'));

        $this->assertSame(PendingSuperficialLossStatus::FullyDenied, $loss->status());
        $this->assertEquals('0.00', $loss->remainingLossAmount->toDecimal());
        $this->assertEquals('0', $loss->remainingUnits->toDecimal());
    }

    public function it_cannot_deny_after_fully_denied(): void
    {
        $this->expectException(InvalidSuperficialLossTransition::class);

        $loss = $this->makeDomainLoss();
        $loss->deny(Money::fromDecimal('100.00', 'CAD'), AssetQuantity::fromDecimal('1', 'BTC'));

        // Attempt another denial
        $loss->deny(Money::fromDecimal('1.00', 'CAD'), AssetQuantity::fromDecimal('1', 'BTC'));
    }
}
