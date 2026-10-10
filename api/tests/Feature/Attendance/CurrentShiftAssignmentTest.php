<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Attendance\Concerns\PunchFixtures;
use Tests\TestCase;

class CurrentShiftAssignmentTest extends TestCase
{
    use PunchFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    private function hr(): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('slug', 'hr_officer')->value('id')]);
    }

    public function test_current_shift_endpoint_also_returns_the_assignment_with_its_working_days(): void
    {
        [$emp] = $this->worker();
        $shift = $this->shift();
        $this->assignShift($emp, $shift, [1, 2, 3, 4, 5]);
        $hr = $this->hr();

        $this->actingAs($hr)->getJson("/api/v1/attendance/shifts/current/{$emp->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.id', $shift->hash_id)
            ->assertJsonPath('assignment.working_days', [1, 2, 3, 4, 5])
            ->assertJsonStructure(['assignment' => ['id', 'working_days', 'effective_date']]);
    }

    public function test_an_unassigned_employee_gets_null_for_both(): void
    {
        [$emp] = $this->worker();
        $hr = $this->hr();

        $this->actingAs($hr)->getJson("/api/v1/attendance/shifts/current/{$emp->hash_id}")
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('assignment', null);
    }

    public function test_a_null_working_days_assignment_reports_null_not_a_guess(): void
    {
        [$emp] = $this->worker();
        $this->assignShift($emp, $this->shift(), null);
        $hr = $this->hr();

        $this->actingAs($hr)->getJson("/api/v1/attendance/shifts/current/{$emp->hash_id}")
            ->assertOk()
            ->assertJsonPath('assignment.working_days', null);
    }
}
