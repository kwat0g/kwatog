<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Modules\DSS\Detectors\NcrSlaBreachDetector;
use App\Modules\DSS\Services\DetectorRegistry;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NcrSlaBreachDetectorTest extends TestCase
{
    use RefreshDatabase;

    private function detector(): NcrSlaBreachDetector
    {
        return app(NcrSlaBreachDetector::class);
    }

    private function ncr(string $severity, int $ageHours, array $overrides = []): NonConformanceReport
    {
        return NonConformanceReport::factory()->create(array_merge([
            'severity' => $severity,
            'status' => 'open',
            'created_at' => now()->subHours($ageHours),
        ], $overrides));
    }

    public function test_clean_slate_emits_nothing(): void
    {
        $this->assertSame([], $this->detector()->detect()->emissions);
    }

    public function test_flags_overdue_ncrs_aggregated_per_severity(): void
    {
        // Canonical SLAs (migration 0295): critical 8h, high 24h, medium 72h, low 168h.
        $this->ncr('critical', 10);
        $this->ncr('high', 30);
        $this->ncr('medium', 1); // fresh: not overdue

        $emissions = $this->detector()->detect()->emissions;
        $byKey = [];
        foreach ($emissions as $emission) {
            $byKey[$emission->rationale['severity']] = $emission;
        }

        $this->assertArrayHasKey('critical', $byKey);
        $this->assertArrayHasKey('high', $byKey);
        $this->assertArrayNotHasKey('medium', $byKey);

        $critical = $byKey['critical'];
        $this->assertSame('ncr_sla_breach', $critical->type);
        $this->assertSame('critical', $critical->severity);
        $this->assertSame(1, $critical->rationale['affected_count']);
        $this->assertSame(8, $critical->rationale['sla_hours']);
        $this->assertSame('/quality/ncrs', $critical->action['route']);
        $this->assertSame(1.0, $critical->confidence);

        $high = $byKey['high'];
        $this->assertSame('warning', $high->severity);
        $this->assertSame(24, $high->rationale['sla_hours']);
    }

    public function test_escalation_resets_the_overdue_clock(): void
    {
        // Created 200h ago but escalated 1h ago: the clock restarts at the
        // last escalation, mirroring NcrEscalationService::advanceOne.
        $this->ncr('low', 200, ['created_at' => now()->subHours(200), 'last_escalated_at' => now()->subHour()]);

        $this->assertSame([], $this->detector()->detect()->emissions);
    }

    public function test_closed_and_cancelled_ncrs_are_ignored(): void
    {
        $this->ncr('critical', 100, ['status' => 'closed']);
        $this->ncr('critical', 100, ['status' => 'cancelled']);

        $this->assertSame([], $this->detector()->detect()->emissions);
    }

    public function test_detector_contract_metadata(): void
    {
        $detector = $this->detector();

        $this->assertSame('dss.ncr_sla_breach', $detector->id());
        $this->assertSame('quality', $detector->domain());
        $this->assertSame(['quality.ncr.view'], $detector->ownerPermissions());
        $this->assertSame('v1', $detector->version());
    }

    public function test_registered_in_production_registry(): void
    {
        $this->assertTrue(app(DetectorRegistry::class)->has('dss.ncr_sla_breach'));
    }
}
