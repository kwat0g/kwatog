<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Common\Services\NotificationService;
use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Attendance\Concerns\PunchFixtures;
use Tests\TestCase;

class PunchCommandsTest extends TestCase
{
    use PunchFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
    }

    public function test_purge_nulls_only_coordinates_older_than_the_window(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 12:00:00');
        $old = AttendancePunch::create(['employee_id' => $emp->id, 'direction' => 'in', 'punched_at' => '2026-06-01 06:00:00', 'latitude' => 14.286, 'longitude' => 120.9345, 'accuracy_m' => 20, 'distance_m' => 5]);
        $new = AttendancePunch::create(['employee_id' => $emp->id, 'direction' => 'in', 'punched_at' => '2026-10-01 06:00:00', 'latitude' => 14.286, 'longitude' => 120.9345, 'accuracy_m' => 20, 'distance_m' => 5]);

        $this->artisan('attendance:purge-punch-coordinates')->assertSuccessful();

        $this->assertNull($old->fresh()->latitude);
        $this->assertNull($old->fresh()->longitude);
        $this->assertSame('5.00', (string) $old->fresh()->distance_m);
        $this->assertNotNull($new->fresh()->latitude);
    }

    public function test_notifies_hr_of_yesterdays_open_rows_and_stays_quiet_when_none(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        User::factory()->create(['role_id' => Role::query()->where('slug', 'hr_officer')->value('id'), 'is_active' => true]);
        [$emp] = $this->worker();
        $this->at('2026-10-08 06:00:00');

        $this->artisan('attendance:notify-missing-timeout')->assertSuccessful(); // nothing open yet
        $this->assertDatabaseCount('notifications', 0);

        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-10-07', 'time_in' => '2026-10-07 06:00:00', 'status' => AttendanceStatus::Present->value]);
        $this->artisan('attendance:notify-missing-timeout')->assertSuccessful();
        $this->assertDatabaseHas('notifications', ['type' => 'attendance.missing_time_out']);
    }

    public function test_exits_failure_when_every_send_throws(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        User::factory()->create(['role_id' => Role::query()->where('slug', 'hr_officer')->value('id'), 'is_active' => true]);
        [$emp] = $this->worker();
        $this->at('2026-10-08 06:00:00');
        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-10-07', 'time_in' => '2026-10-07 06:00:00', 'status' => AttendanceStatus::Present->value]);

        $this->mock(NotificationService::class, fn ($m) => $m->shouldReceive('sendForSubject')->andThrow(new \RuntimeException('boom')));

        $this->artisan('attendance:notify-missing-timeout')->assertFailed();
    }

    public function test_exits_failure_when_no_active_hr_officer_to_notify(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        [$emp] = $this->worker();
        $this->at('2026-10-08 06:00:00');
        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-10-07', 'time_in' => '2026-10-07 06:00:00', 'status' => AttendanceStatus::Present->value]);

        // Seed HR officer role exists, but no active user with that role
        $this->artisan('attendance:notify-missing-timeout')->assertFailed();
    }

    public function test_exits_failure_when_hr_officer_cannot_open_attendance_page(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $hr = User::factory()->create(['role_id' => Role::query()->where('slug', 'hr_officer')->value('id')]);
        $actor = User::factory()->withRole('system_admin')->create();
        foreach (['attendance.edit', 'attendance.import'] as $permission) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $hr->id,
                'permission_id' => DB::table('permissions')->where('slug', $permission)->value('id'),
                'type' => 'revoke',
                'granted_by' => $actor->id,
                'reason' => 'Attendance notice visibility regression test',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        [$emp] = $this->worker();
        $this->at('2026-10-08 06:00:00');
        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-10-07', 'time_in' => '2026-10-07 06:00:00', 'status' => AttendanceStatus::Present->value]);

        $this->artisan('attendance:notify-missing-timeout')->assertFailed();
        $this->assertSame(0, $this->missingTimeoutCount());
    }

    private function departmentHead(Department $dept): User
    {
        $emp = \App\Modules\HR\Models\Employee::factory()->create(['department_id' => $dept->id]);

        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
            'employee_id' => $emp->id,
            'is_active' => true,
        ]);
    }

    private function missingTimeoutCount(): int
    {
        return DB::table('notifications')->where('type', 'attendance.missing_time_out')->count();
    }

    public function test_each_department_head_is_told_about_their_own_departments_open_rows(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $hr = User::factory()->create(['role_id' => Role::query()->where('slug', 'hr_officer')->value('id'), 'is_active' => true]);
        $dept = Department::factory()->create();
        $eligibleHead = $this->departmentHead($dept);
        $revokedHead = $this->departmentHead($dept);
        $actor = User::factory()->withRole('system_admin')->create();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $revokedHead->id,
            'permission_id' => DB::table('permissions')->where('slug', 'attendance.ot.approve')->value('id'),
            'type' => 'revoke',
            'granted_by' => $actor->id,
            'reason' => 'Attendance notice visibility regression test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $emp = \App\Modules\HR\Models\Employee::factory()->create(['department_id' => $dept->id]);
        $secondEmp = \App\Modules\HR\Models\Employee::factory()->create(['department_id' => $dept->id]);
        $otherDepartment = Department::factory()->create();
        $otherDepartmentEmp = \App\Modules\HR\Models\Employee::factory()->create(['department_id' => $otherDepartment->id]);
        $this->at('2026-10-08 06:00:00');
        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-10-07', 'time_in' => '2026-10-07 06:00:00', 'status' => AttendanceStatus::Present->value]);
        Attendance::create(['employee_id' => $secondEmp->id, 'date' => '2026-10-07', 'time_in' => '2026-10-07 06:00:00', 'status' => AttendanceStatus::Present->value]);
        Attendance::create(['employee_id' => $otherDepartmentEmp->id, 'date' => '2026-10-07', 'time_in' => '2026-10-07 06:00:00', 'status' => AttendanceStatus::Present->value]);

        $this->artisan('attendance:notify-missing-timeout')->assertSuccessful();

        $this->assertSame(2, $this->missingTimeoutCount()); // 1 HR + 1 eligible head
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $eligibleHead->id, 'type' => 'attendance.missing_time_out']);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $revokedHead->id, 'type' => 'attendance.missing_time_out']);

        $expectedLink = '/hr/attendance?missing_time_out=1&from=2026-10-07&to=2026-10-07';
        $hrNotice = $hr->notifications()->where('type', 'attendance.missing_time_out')->firstOrFail();
        $headNotice = $eligibleHead->notifications()->where('type', 'attendance.missing_time_out')->firstOrFail();
        $this->assertSame(Attendance::class, $headNotice->data['subject_type']);
        $this->assertSame((string) Attendance::query()->where('employee_id', $emp->id)->value('id'), $headNotice->data['subject_id']);
        $this->assertSame($expectedLink, $hrNotice->data['link_to']);
        $this->assertSame('3 employee(s) have no time out for Oct 7, 2026.', $hrNotice->data['message']);
        $this->assertSame($expectedLink, $headNotice->data['link_to']);
        $this->assertSame('2 employee(s) in '.$dept->name.' have no time out for Oct 7, 2026.', $headNotice->data['message']);
        $filter = 'missing_time_out=1&from=2026-10-07&to=2026-10-07';
        $this->assertSame(3, $this->actingAs($hr)->getJson('/api/v1/attendance/attendances?'.$filter)->assertOk()->json('meta.total'));
        $this->assertSame(2, $this->actingAs($eligibleHead)->getJson('/api/v1/attendance/attendances?'.$filter)->assertOk()->json('meta.total'));
        $this->assertSame(0, $this->actingAs($revokedHead)->getJson('/api/v1/attendance/attendances?'.$filter)->assertOk()->json('meta.total'));
        app(NotificationService::class)->sendForSubject(Attendance::query()->where('employee_id', $emp->id)->firstOrFail(), $revokedHead, 'test.attendance_scope', [
            'title' => 'Missing time-out',
            'link_to' => $expectedLink,
        ], emailEnabled: false);
        $this->assertSame(0, $revokedHead->notifications()->where('type', 'test.attendance_scope')->count());

        $this->artisan('attendance:notify-missing-timeout')->assertSuccessful(); // rerun: deduped
        $this->assertSame(2, $this->missingTimeoutCount());
    }

    public function test_a_department_without_a_head_is_reported_but_is_not_a_failure(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        User::factory()->create(['role_id' => Role::query()->where('slug', 'hr_officer')->value('id'), 'is_active' => true]);
        $emp = \App\Modules\HR\Models\Employee::factory()->create(['department_id' => Department::factory()->create()->id]);
        $this->at('2026-10-08 06:00:00');
        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-10-07', 'time_in' => '2026-10-07 06:00:00', 'status' => AttendanceStatus::Present->value]);

        $this->artisan('attendance:notify-missing-timeout')
            ->expectsOutputToContain('no active department head')
            ->assertSuccessful();
        $this->assertSame(1, $this->missingTimeoutCount()); // HR only
    }
}
