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
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Tests\TestCase;

final class PendingSuperficialLossConstructorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // seed the common tables
        $this->seed(CurrencySeeder::class);
    }

    private function getSampleUUID(): UuidInterface
    {
        return Uuid::uuid4();
    }

    private function getSampleWindow(): array
    {
        return [
            CarbonImmutable::parse('2024-01-01'),
            CarbonImmutable::parse('2024-01-30'),
        ];
    }

    #[Test]
    public function it_creates_a_valid_pending_loss(): void
    {
        [$start, $end] = $this->getSampleWindow();

        $loss = PendingSuperficialLoss::createFromDisposition(
            acbEventId: 1,
            assetCode: 'BTC',
            superficialLoss: Money::fromMinorUnits(10000, 'CAD'),
            superficialUnits: AssetQuantity::fromDecimal('1.0', 'BTC'),
            dispositionDate: $start,
        );

        $this->assertSame(PendingSuperficialLossStatus::Pending, $loss->status());
        $this->assertSame('BTC', $loss->assetCode);
        $this->assertTrue($loss->originalLossAmount->equals(Money::fromMinorUnits(10000, 'CAD')));
        $this->assertTrue($loss->remainingLossAmount->equals(Money::fromMinorUnits(10000, 'CAD')));
        $this->assertTrue($loss->originalUnits->equals(AssetQuantity::fromDecimal('1.0', 'BTC')));
        $this->assertTrue($loss->remainingUnits->equals(AssetQuantity::fromDecimal('1.0', 'BTC')));
    }

    #[Test]
    public function it_rejects_window_start_after_end(): void
    {
        $this->expectException(InvalidSuperficialLossCreation::class);
        $this->expectExceptionMessage('Window start must be before window end.');

        new PendingSuperficialLoss(
            id: $this->getSampleUUID(),
            assetCode: 'BTC',
            acbEventId: 1,
            windowStart: CarbonImmutable::parse('2024-02-01'),
            windowEnd: CarbonImmutable::parse('2024-01-01'),
            originalLossAmount: Money::fromMinorUnits(10000, 'CAD'),
            originalUnits: AssetQuantity::fromDecimal('1.0', 'BTC'),
            remainingLossAmount: Money::fromMinorUnits(10000, 'CAD'),
            remainingUnits: AssetQuantity::fromDecimal('1.0', 'BTC'),
        );
    }

    #[Test]
    public function it_rejects_negative_original_loss(): void
    {
        $this->expectException(InvalidSuperficialLossCreation::class);
        $this->expectExceptionMessage('Original loss must be greater than or equal to zero.');

        [$start, $end] = $this->getSampleWindow();

        new PendingSuperficialLoss(
            id: $this->getSampleUUID(),
            assetCode: 'BTC',
            acbEventId: 1,
            windowStart: $start,
            windowEnd: $end,
            originalLossAmount: Money::fromMinorUnits(-1000, 'CAD'),
            originalUnits: AssetQuantity::fromDecimal('1.0', 'BTC'),
            remainingLossAmount: Money::fromMinorUnits(-1000, 'CAD'),
            remainingUnits: AssetQuantity::fromDecimal('1.0', 'BTC'),
        );
    }

    #[Test]
    public function it_rejects_negative_or_zero_units(): void
    {
        $this->expectException(InvalidSuperficialLossCreation::class);
        $this->expectExceptionMessage('Original units must be greater than or equal to zero.');

        [$start, $end] = $this->getSampleWindow();

        new PendingSuperficialLoss(
            id: $this->getSampleUUID(),
            assetCode: 'BTC',
            acbEventId: 1,
            windowStart: $start,
            windowEnd: $end,
            originalLossAmount: Money::fromMinorUnits(10000, 'CAD'),
            originalUnits: AssetQuantity::zero('BTC'),
            remainingLossAmount: Money::fromMinorUnits(10000, 'CAD'),
            remainingUnits: AssetQuantity::zero('BTC'),
        );
    }

    #[Test]
    public function it_rejects_remaining_exceeding_original(): void
    {
        $this->expectException(InvalidSuperficialLossCreation::class);
        $this->expectExceptionMessage('Remaining loss or units cannot exceed original amounts.');

        [$start, $end] = $this->getSampleWindow();

        new PendingSuperficialLoss(
            id: $this->getSampleUUID(),
            assetCode: 'BTC',
            acbEventId: 1,
            windowStart: $start,
            windowEnd: $end,
            originalLossAmount: Money::fromMinorUnits(10000, 'CAD'),
            originalUnits: AssetQuantity::fromDecimal('1.0', 'BTC'),
            remainingLossAmount: Money::fromMinorUnits(20000, 'CAD'), // > original
            remainingUnits: AssetQuantity::fromDecimal('2.0', 'BTC'),
        );
    }

    #[Test]
    public function it_sets_status_to_fully_denied_if_remaining_is_zero(): void
    {
        [$start, $end] = $this->getSampleWindow();

        $loss = new PendingSuperficialLoss(
            id: $this->getSampleUUID(),
            assetCode: 'BTC',
            acbEventId: 1,
            windowStart: $start,
            windowEnd: $end,
            originalLossAmount: Money::fromMinorUnits(10000, 'CAD'),
            originalUnits: AssetQuantity::fromDecimal('1.0', 'BTC'),
            remainingLossAmount: Money::fromMinorUnits(0, 'CAD'),
            remainingUnits: AssetQuantity::zero('BTC'),
        );

        $this->assertSame(PendingSuperficialLossStatus::FullyDenied, $loss->status());
    }

    #[Test]
    public function it_sets_status_to_partially_denied_if_remaining_less_than_original(): void
    {
        [$start, $end] = $this->getSampleWindow();

        $loss = new PendingSuperficialLoss(
            id: $this->getSampleUUID(),
            assetCode: 'BTC',
            acbEventId: 1,
            windowStart: $start,
            windowEnd: $end,
            originalLossAmount: Money::fromMinorUnits(10000, 'CAD'),
            originalUnits: AssetQuantity::fromDecimal('1.0', 'BTC'),
            remainingLossAmount: Money::fromMinorUnits(5000, 'CAD'),
            remainingUnits: AssetQuantity::fromDecimal('0.5', 'BTC'),
        );

        $this->assertSame(PendingSuperficialLossStatus::PartiallyDenied, $loss->status());
    }
}
