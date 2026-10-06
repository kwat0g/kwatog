<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Common\Services\NotificationService;
use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Attendance\Concerns\PunchFixtures;
use Tests\TestCase;

class PunchCommandsTest extends TestCase
{
    use PunchFixtures, RefreshDatabase;

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

        $this->mock(NotificationService::class, fn ($m) => $m->shouldReceive('send')->andThrow(new \RuntimeException('boom')));

        $this->artisan('attendance:notify-missing-timeout')->assertFailed();
    }
}
