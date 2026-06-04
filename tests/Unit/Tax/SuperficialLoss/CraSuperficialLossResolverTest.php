<?php

namespace Tests\Unit\Tax\SuperficialLoss;

use App\Tax\SuperficialLoss\Domain\CraSuperficialLossResolver;
use App\Tax\SuperficialLoss\Domain\PendingSuperficialLoss;
use App\Tax\SuperficialLoss\Domain\PendingSuperficialLossStatus;
use App\Tax\SuperficialLoss\Domain\SuperficialLossResolutionType;
use App\Tax\SuperficialLoss\Policies\CraSuperficialLossMatchingPolicy;
use App\Types\AssetQuantity;
use App\Types\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class CraSuperficialLossResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CurrencySeeder::class);
    }

    private function makeLoss(
        bool $fullyDenied = false,
        string $windowEnd = '2024-01-30'
    ): PendingSuperficialLoss {
        $loss = new PendingSuperficialLoss(
            id: Uuid::uuid4(),
            assetCode: 'BTC',
            acbEventId: 1,
            windowStart: CarbonImmutable::parse('2024-01-01'),
            windowEnd: CarbonImmutable::parse($windowEnd),
            originalLossAmount: Money::fromMinorUnits(10_000, 'CAD'),
            originalUnits: AssetQuantity::fromDecimal('1.0', 'BTC'),
            remainingLossAmount: Money::fromMinorUnits(10_000, 'CAD'),
            remainingUnits: AssetQuantity::fromDecimal('1.0', 'BTC')
        );

        if ($fullyDenied) {
            $loss->deny(
                Money::fromMinorUnits(10_000, 'CAD'),
                AssetQuantity::fromDecimal('1.0', 'BTC')
            );
        }

        return $loss;
    }

    private function makeResolver(): CraSuperficialLossResolver
    {
        return new CraSuperficialLossResolver(
            new CraSuperficialLossMatchingPolicy
        );
    }

    #[Test]
    public function it_expires_pending_losses_after_the_window(): void
    {
        $resolver = $this->makeResolver();

        $loss = $this->makeLoss(
            windowEnd: '2024-01-30'
        );

        $today = CarbonImmutable::parse('2024-01-31');

        $resolutions = $resolver->resolve([$loss], $today);

        $this->assertCount(1, $resolutions);
        $this->assertSame(
            SuperficialLossResolutionType::Expired,
            $resolutions[0]->type
        );
    }

    #[Test]
    public function it_does_not_expire_pending_losses_before_the_window(): void
    {
        $resolver = $this->makeResolver();

        $loss = $this->makeLoss();

        $today = CarbonImmutable::parse('2024-01-15');

        $resolutions = $resolver->resolve([$loss], $today);

        $this->assertSame(
            SuperficialLossResolutionType::StillPending,
            $resolutions[0]->type
        );
    }

    #[Test]
    public function it_ignores_fully_denied_losses(): void
    {
        $resolver = $this->makeResolver();

        $loss = $this->makeLoss(fullyDenied: true);

        $today = CarbonImmutable::parse('2024-02-01');

        $resolutions = $resolver->resolve([$loss], $today);

        $this->assertCount(0, $resolutions);
    }

    #[Test]
    public function it_does_not_mutate_loss_state(): void
    {
        $resolver = $this->makeResolver();

        $loss = $this->makeLoss();

        $resolver->resolve([$loss], CarbonImmutable::parse('2024-02-01'));

        $this->assertSame(
            PendingSuperficialLossStatus::Pending,
            $loss->status()
        );
    }

    #[Test]
    public function it_records_the_resolution_timestamp(): void
    {
        $resolver = $this->makeResolver();

        $loss = $this->makeLoss();

        $today = CarbonImmutable::parse('2024-02-01');

        $resolutions = $resolver->resolve([$loss], $today);

        $this->assertTrue(
            $resolutions[0]->resolvedAt->equalTo($today)
        );
    }
}
