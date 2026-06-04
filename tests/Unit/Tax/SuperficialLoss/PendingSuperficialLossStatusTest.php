<?php

namespace Tests\Unit\Tax\SuperficialLoss;

use App\Tax\SuperficialLoss\Domain\PendingSuperficialLossStatus;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PendingSuperficialLossStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // seed the common tables
        $this->seed(CurrencySeeder::class);
    }

    #[Test]
    public function pending_can_be_denied_and_expired(): void
    {
        $status = PendingSuperficialLossStatus::Pending;

        $this->assertTrue($status->canDeny());
        $this->assertTrue($status->canExpire());
    }

    #[Test]
    public function partially_denied_can_be_denied_and_expired(): void
    {
        $status = PendingSuperficialLossStatus::PartiallyDenied;

        $this->assertTrue($status->canDeny());
        $this->assertTrue($status->canExpire());
    }

    #[Test]
    public function fully_denied_cannot_be_denied_or_expired(): void
    {
        $status = PendingSuperficialLossStatus::FullyDenied;

        $this->assertFalse($status->canDeny());
        $this->assertFalse($status->canExpire());
    }

    #[Test]
    public function expired_cannot_be_denied_or_expired(): void
    {
        $status = PendingSuperficialLossStatus::Expired;

        $this->assertFalse($status->canDeny());
        $this->assertFalse($status->canExpire());
    }
}
