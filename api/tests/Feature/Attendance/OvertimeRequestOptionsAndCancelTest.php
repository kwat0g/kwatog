<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\OvertimeStatus;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Overtime request options + cancellation (HR-side polish).
 *
 * - GET /attendance/overtime-requests/options must return the settings-driven
 *   min/max hours and date window so the SPA form validates like the backend.
 * - DELETE /attendance/overtime-requests/{id} lets the owner withdraw a
 *   pending request (parity with the self-service cancel) and lets admins /
 *   OT approvers cancel pending requests of their team.
 */
class OvertimeRequestOptionsAndCancelTest extends TestCase
{
    use RefreshDatabase;

    private function seedRoles(): void
    {
        $this->seed([
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
        ]);
    }

    private function makeOt(Employee $emp, string $status = OvertimeStatus::Pending->value): OvertimeRequest
    {
        return OvertimeRequest::create([
            'employee_id'     => $emp->id,
            'date'            => now()->subDay()->toDateString(),
            'hours_requested' => 2,
            'reason'          => 'Options & cancel test',
            'status'          => $status,
        ]);
    }

    public function test_options_endpoint_returns_hour_windows_and_multiplier(): void
    {
        $this->seedRoles();

        $admin = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/attendance/overtime-requests/options')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'minimum_hours', 'maximum_hours', 'request_min_hours',
                'request_future_days', 'request_past_days', 'premium_multiplier',
            ]])
            ->assertJsonPath('data.minimum_hours', 0.5)
            ->assertJsonPath('data.maximum_hours', 8)
            ->assertJsonPath('data.request_min_hours', 0.5)
            ->assertJsonPath('data.request_future_days', 30)
            ->assertJsonPath('data.request_past_days', 0)
            ->assertJsonPath('data.premium_multiplier', 1.25);
    }

    public function test_options_route_is_not_captured_by_model_binding(): void
    {
        $this->seedRoles();

        $admin = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);

        // 'options' must resolve as the literal endpoint, not a HashID lookup
        // that would 404 inside the {overtime} route binding.
        $this->actingAs($admin)
            ->getJson('/api/v1/attendance/overtime-requests/options')
            ->assertOk();
    }

    public function test_owner_can_cancel_pending_request(): void
    {
        $this->seedRoles();

        $emp  = Employee::factory()->create();
        $user = User::factory()->create([
            'employee_id' => $emp->id,
            'role_id'     => Role::where('slug', 'employee')->value('id'),
        ]);

        $ot = $this->makeOt($emp);

        $this->actingAs($user)
            ->deleteJson("/api/v1/attendance/overtime-requests/{$ot->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.status', OvertimeStatus::Rejected->value)
            ->assertJsonPath('data.rejection_reason', 'Cancelled.');

        $this->assertSame(OvertimeStatus::Rejected, $ot->fresh()->status);
    }

    public function test_admin_can_cancel_any_pending_request(): void
    {
        $this->seedRoles();

        $emp  = Employee::factory()->create();
        User::factory()->create([
            'employee_id' => $emp->id,
            'role_id'     => Role::where('slug', 'employee')->value('id'),
        ]);
        $admin = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);

        $ot = $this->makeOt($emp);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/attendance/overtime-requests/{$ot->hash_id}")
            ->assertOk();

        $this->assertSame(OvertimeStatus::Rejected, $ot->fresh()->status);
    }

    public function test_unrelated_user_cannot_cancel(): void
    {
        $this->seedRoles();

        $emp  = Employee::factory()->create();
        $other = User::factory()->create([
            'role_id' => Role::where('slug', 'employee')->value('id'),
        ]);

        $ot = $this->makeOt($emp);

        $this->actingAs($other)
            ->deleteJson("/api/v1/attendance/overtime-requests/{$ot->hash_id}")
            ->assertForbidden();

        $this->assertSame(OvertimeStatus::Pending, $ot->fresh()->status);
    }

    public function test_non_pending_request_cannot_be_cancelled(): void
    {
        $this->seedRoles();

        $emp  = Employee::factory()->create();
        $user = User::factory()->create([
            'employee_id' => $emp->id,
            'role_id'     => Role::where('slug', 'employee')->value('id'),
        ]);

        $ot = $this->makeOt($emp, OvertimeStatus::Approved->value);

        $this->actingAs($user)
            ->deleteJson("/api/v1/attendance/overtime-requests/{$ot->hash_id}")
            ->assertStatus(422);

        $this->assertSame(OvertimeStatus::Approved, $ot->fresh()->status);
    }

    public function test_list_search_filters_by_employee(): void
    {
        $this->seedRoles();

        $admin = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);

        $empA = Employee::factory()->create(['first_name' => 'Zenaida', 'last_name' => 'Reyes']);
        $empB = Employee::factory()->create(['first_name' => 'Pedro', 'last_name' => 'Garcia']);
        $otA  = $this->makeOt($empA);
        $otB  = $this->makeOt($empB);

        $this->actingAs($admin)
            ->getJson('/api/v1/attendance/overtime-requests?search=garcia')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $otB->hash_id)
            ->assertJsonPath('data.0.employee.full_name', fn ($name) => str_contains((string) $name, 'Garcia'));

        $this->actingAs($admin)
            ->getJson('/api/v1/attendance/overtime-requests?search=Reyes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $otA->hash_id);
    }
}
