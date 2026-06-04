<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\CopqService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CopqServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_copq_returns_structured_breakdown(): void
    {
        NonConformanceReport::factory()->create([
            'status'            => 'closed',
            'affected_quantity' => 10,
            'disposition'       => 'scrap',
            'closed_at'         => now()->startOfMonth()->addDay(),
        ]);

        $svc    = app(CopqService::class);
        $result = $svc->compute(now()->startOfMonth(), now()->endOfMonth());

        $this->assertArrayHasKey('internal_failure', $result);
        $this->assertArrayHasKey('external_failure', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertSame(10, $result['internal_failure']['scrap_units']);
    }

    public function test_copq_returns_zero_when_no_data(): void
    {
        $svc    = app(CopqService::class);
        $result = $svc->compute(now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame(0, $result['internal_failure']['scrap_units']);
        $this->assertSame(0, $result['internal_failure']['rework_units']);
        $this->assertSame(0.0, $result['total']);
    }
}
