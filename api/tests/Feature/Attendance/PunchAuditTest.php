<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\PunchService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Attendance\Concerns\PunchFixtures;
use Tests\TestCase;

class PunchAuditTest extends TestCase
{
    use PunchFixtures, RefreshDatabase;

    private function userWithRole(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('slug', $slug)->value('id')]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->plant();
        $this->shift();
    }

    public function test_hr_sees_punch_location_but_a_department_head_does_not(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:02:00');
        app(PunchService::class)->punch($emp, 14.2860, 120.9345, 20.0, '10.0.0.1', 'ua');
        $row = Attendance::query()->where('employee_id', $emp->id)->firstOrFail();

        $this->actingAs($this->userWithRole('hr_officer'))
            ->getJson("/api/v1/attendance/attendances/{$row->hash_id}/punches")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.direction', 'in')
            ->assertJsonPath('data.0.distance_m', '0.00');

        $this->actingAs($this->userWithRole('department_head'))
            ->getJson("/api/v1/attendance/attendances/{$row->hash_id}/punches")
            ->assertForbidden();
    }

    public function test_missing_time_out_filter_lists_only_open_past_days(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-08 09:00:00');
        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-10-06', 'time_in' => '2026-10-06 06:00:00', 'status' => AttendanceStatus::Present->value]);
        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-10-07', 'time_in' => '2026-10-07 06:00:00', 'time_out' => '2026-10-07 14:00:00', 'status' => AttendanceStatus::Present->value]);
        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-10-08', 'time_in' => '2026-10-08 06:00:00', 'status' => AttendanceStatus::Present->value]);

        $this->actingAs($this->userWithRole('hr_officer'))
            ->getJson('/api/v1/attendance/attendances?missing_time_out=1&from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.date', '2026-10-06');
    }

    public function test_raw_gps_coordinates_are_masked_in_audit_logs(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:02:00');

        // Create a punch with real coordinates
        app(PunchService::class)->punch($emp, 14.2860, 120.9345, 20.0, '10.0.0.1', 'Mozilla/5.0');

        // Query audit_logs for the AttendancePunch model
        $auditLogs = \App\Common\Models\AuditLog::query()
            ->where('model_type', 'App\Modules\Attendance\Models\AttendancePunch')
            ->where('action', 'created')
            ->get();

        $this->assertCount(1, $auditLogs);
        $log = $auditLogs->first();

        // Verify that latitude and longitude are masked as '***' in the audit trail
        // (raw coordinates are sensitive and purged after 90 days; mask them immediately)
        $this->assertEquals('***', $log->new_values['latitude']);
        $this->assertEquals('***', $log->new_values['longitude']);

        // Verify that other fields are preserved (not masked)
        $this->assertNotNull($log->new_values['accuracy_m']);
        $this->assertNotNull($log->new_values['distance_m']);
        $this->assertNotNull($log->new_values['employee_id']);
    }
}
