<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Services\ArDunningService;
use Mockery;
use PHPUnit\Framework\TestCase;

class ArDunningTierTest extends TestCase
{
    private ArDunningService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ArDunningService(
            Mockery::mock(SettingsService::class),
            Mockery::mock(NotificationService::class),
        );
    }

    public function test_no_tier_when_not_overdue_enough(): void
    {
        $this->assertNull($this->svc->selectTier(5, 0, [30, 15, 7]));
    }

    public function test_tier_7_at_seven_days(): void
    {
        $this->assertSame(7, $this->svc->selectTier(7, 0, [30, 15, 7]));
    }

    public function test_tier_15_at_fifteen_days_when_already_sent_7(): void
    {
        $this->assertSame(15, $this->svc->selectTier(15, 7, [30, 15, 7]));
    }

    public function test_tier_30_wins_when_late_and_lower_already_sent(): void
    {
        $this->assertSame(30, $this->svc->selectTier(45, 15, [30, 15, 7]));
    }

    public function test_tier_already_at_max_returns_null(): void
    {
        $this->assertNull($this->svc->selectTier(60, 30, [30, 15, 7]));
    }

    public function test_tier_jumps_from_zero_to_30_when_invoice_very_overdue(): void
    {
        $this->assertSame(30, $this->svc->selectTier(45, 0, [30, 15, 7]));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
