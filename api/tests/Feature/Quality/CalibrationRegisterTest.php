<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Quality\Enums\CalibrationStatus;
use App\Modules\Quality\Models\CalibrationRecord;
use App\Modules\Quality\Services\CalibrationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OGAMI-016 — IATF calibration register: due/overdue evaluation.
 */
class CalibrationRegisterTest extends TestCase
{
    use RefreshDatabase;

    private CalibrationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(CalibrationService::class);
    }

    public function test_overdue_when_next_date_is_past(): void
    {
        $rec = $this->svc->create([
            'equipment_code'        => 'GAUGE-001',
            'name'                  => 'Digital Caliper',
            'next_calibration_date' => CarbonImmutable::now()->subDays(5)->toDateString(),
            'frequency_days'        => 365,
        ]);

        $this->assertSame(CalibrationStatus::Overdue, $rec->status);
    }

    public function test_due_when_within_window(): void
    {
        $rec = $this->svc->create([
            'equipment_code'        => 'GAUGE-002',
            'name'                  => 'Micrometer',
            'next_calibration_date' => CarbonImmutable::now()->addDays(10)->toDateString(),
            'frequency_days'        => 365,
        ]);

        $this->assertSame(CalibrationStatus::Due, $rec->status);
    }

    public function test_active_when_far_from_due(): void
    {
        $rec = $this->svc->create([
            'equipment_code'        => 'GAUGE-003',
            'name'                  => 'CMM',
            'next_calibration_date' => CarbonImmutable::now()->addDays(200)->toDateString(),
            'frequency_days'        => 365,
        ]);

        $this->assertSame(CalibrationStatus::Active, $rec->status);
    }

    public function test_record_calibration_advances_next_date(): void
    {
        $rec = $this->svc->create([
            'equipment_code'        => 'GAUGE-004',
            'name'                  => 'Height Gauge',
            'frequency_days'        => 90,
        ]);

        $rec = $this->svc->recordCalibration($rec, CarbonImmutable::now()->toDateString());

        $expectedNext = CarbonImmutable::now()->addDays(90)->toDateString();
        $this->assertSame($expectedNext, $rec->next_calibration_date->toDateString());
        $this->assertSame(CalibrationStatus::Active, $rec->status);
    }

    public function test_recompute_statuses_flips_active_to_overdue(): void
    {
        // Seed an item that is overdue but stored as active (simulating drift).
        $rec = CalibrationRecord::create([
            'equipment_code'        => 'GAUGE-005',
            'name'                  => 'Pin Gauge',
            'next_calibration_date' => CarbonImmutable::now()->subDay()->toDateString(),
            'frequency_days'        => 365,
            'status'                => CalibrationStatus::Active->value,
        ]);

        $counts = $this->svc->recomputeStatuses();

        $this->assertSame(CalibrationStatus::Overdue, $rec->fresh()->status);
        $this->assertGreaterThanOrEqual(1, $counts['overdue']);
    }

    public function test_retired_status_is_preserved(): void
    {
        $rec = $this->svc->create([
            'equipment_code'        => 'GAUGE-006',
            'name'                  => 'Old Caliper',
            'next_calibration_date' => CarbonImmutable::now()->subYear()->toDateString(),
            'frequency_days'        => 365,
            'status'                => CalibrationStatus::Retired->value,
        ]);

        $this->assertSame(CalibrationStatus::Retired, $rec->status);
        $this->svc->recomputeStatuses();
        $this->assertSame(CalibrationStatus::Retired, $rec->fresh()->status);
    }
}
