<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Common\Models\AuditLog;
use App\Modules\DSS\Models\DssParameter;
use App\Modules\DSS\Services\DssParameterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DssParameterServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_code_default_returned_when_nothing_stored(): void
    {
        $service = app(DssParameterService::class);

        $this->assertSame('fallback', $service->get('test.detector', 'missing_key', 'fallback'));
    }

    public function test_set_then_get_round_trip(): void
    {
        $service = app(DssParameterService::class);
        $service->set('test.detector', 'threshold', 0.8);

        $this->assertSame(0.8, $service->get('test.detector', 'threshold'));
    }

    public function test_detector_row_beats_global_row(): void
    {
        $service = app(DssParameterService::class);
        $service->set(DssParameterService::GLOBAL, 'horizon_days', 30);
        $service->set('test.detector', 'horizon_days', 7);

        $this->assertSame(7, $service->get('test.detector', 'horizon_days'));
        $this->assertSame(30, $service->get('other.detector', 'horizon_days'));
    }

    public function test_set_writes_exactly_one_audit_row(): void
    {
        $service = app(DssParameterService::class);

        $before = AuditLog::query()->where('model_type', DssParameter::class)->count();
        $service->set('test.detector', 'threshold', 0.9, updatedBy: null);

        // Single save → one audit row (mass-assignment hygiene convention).
        $this->assertSame($before + 1, AuditLog::query()->where('model_type', DssParameter::class)->count());
    }

    public function test_engine_defaults_are_safe(): void
    {
        $service = app(DssParameterService::class);

        // Unknown mode falls back to shadow: compute and log, never display.
        $service->set(DssParameterService::GLOBAL, 'dss.engine.default_mode', 'nonsense');

        $this->assertSame('shadow', $service->defaultMode());
        $this->assertTrue($service->detectorEnabled('anything.unconfigured'));
    }
}
