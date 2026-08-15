<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Quality\Models\CalibrationRecord;
use App\Modules\Quality\Services\CalibrationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * P01-01 shape on P75 (calibration register): recordCalibration() read-modify-
 * wrote the passed model with no lock and no transaction. Two concurrent
 * calibration entries — one dated later, one dated earlier — land last-write-
 * wins, so the earlier (stale) entry can regress next_calibration_date and roll
 * the register back. The fix locks the record and never regresses.
 */
class CalibrationBackdatedRecordRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_backdated_calibration_cannot_regress_next_due_date(): void
    {
        $svc = app(CalibrationService::class);
        $record = $svc->create([
            'equipment_code' => 'GAUGE-RACE-'.substr(uniqid(), -5),
            'name'           => 'Torque Wrench',
            'frequency_days' => 365,
        ]);

        // Two "concurrent" technicians each fetched the record before either
        // calibration was recorded.
        $entryA = CalibrationRecord::find($record->id);
        $entryB = CalibrationRecord::find($record->id);

        // Newer calibration commits first.
        $svc->recordCalibration($entryA, '2026-08-20');
        $this->assertSame('2026-08-20', $record->refresh()->last_calibration_date->toDateString());

        // Backdated stale entry lands afterwards — must not roll the register back.
        $svc->recordCalibration($entryB, '2026-08-10');

        $fresh = $record->refresh();
        $this->assertSame(
            '2026-08-20',
            $fresh->last_calibration_date->toDateString(),
            'last_calibration_date must reflect the newer calibration, not regress.'
        );
        $this->assertSame(
            '2027-08-20',
            CarbonImmutable::parse($fresh->next_calibration_date)->toDateString()
        );
    }
}
