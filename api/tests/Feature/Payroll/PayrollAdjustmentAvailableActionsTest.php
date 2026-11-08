<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Models\ApprovalDelegation;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\PayrollAdjustmentStatus;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollAdjustmentAvailableActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
        ]);
    }

    private function pendingAdjustment(User $creator): PayrollAdjustment
    {
        $period = PayrollPeriod::factory()->create();
        $employee = Employee::factory()->create();
        $payroll = Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
        ]);

        $adjustment = PayrollAdjustment::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'original_payroll_id' => $payroll->id,
            'type' => 'underpayment',
            'amount' => '500.00',
            'reason' => 'Available action resource test',
            'created_by' => $creator->id,
        ]);
        $adjustment->forceFill(['status' => PayrollAdjustmentStatus::Pending->value])->save();

        return $adjustment;
    }

    public function test_detail_resource_returns_current_user_actions_and_suppresses_self_or_stale_decisions(): void
    {
        $creator = $this->userWithRole('system_admin');
        $approver = $this->userWithRole('system_admin');
        $adjustment = $this->pendingAdjustment($creator);

        $this->actingAs($approver)
            ->getJson("/api/v1/payroll-adjustments/{$adjustment->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.permissions.view', true)
            ->assertJsonPath('data.permissions.approve', true)
            ->assertJsonPath('data.permissions.reject', true)
            ->assertJsonPath('data.available_actions.approve', true)
            ->assertJsonPath('data.available_actions.reject', true);

        $this->actingAs($approver)
            ->getJson('/api/v1/payroll-adjustments')
            ->assertOk()
            ->assertJsonPath('data.0.available_actions.approve', true)
            ->assertJsonPath('data.0.available_actions.reject', true);

        $this->actingAs($creator)
            ->getJson("/api/v1/payroll-adjustments/{$adjustment->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.permissions.view', true)
            ->assertJsonPath('data.available_actions.approve', false)
            ->assertJsonPath('data.available_actions.reject', false);

        $adjustment->forceFill(['status' => PayrollAdjustmentStatus::Approved->value])->save();

        $this->actingAs($approver)
            ->getJson("/api/v1/payroll-adjustments/{$adjustment->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.available_actions.approve', false)
            ->assertJsonPath('data.available_actions.reject', false);
    }

    public function test_action_context_honours_record_specific_delegation_without_granting_other_actions(): void
    {
        $creator = $this->userWithRole('hr_officer');
        $delegate = $this->userWithRole('employee');
        $delegator = $this->userWithRole('finance_officer');
        $admin = $this->userWithRole('system_admin');
        foreach ([
            [$delegate, 'payroll.adjustments.view'],
            [$delegator, 'payroll.adjustments.approve'],
        ] as [$user, $permissionSlug]) {
            UserPermissionOverride::query()->create([
                'user_id' => $user->id,
                'permission_id' => Permission::query()->where('slug', $permissionSlug)->value('id'),
                'type' => 'grant',
                'granted_by' => $admin->id,
                'reason' => 'Payroll adjustment available action coverage.',
            ]);
            $user->flushPermissionsCache();
        }
        ApprovalDelegation::query()->create([
            'delegator_user_id' => $delegator->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);
        $adjustment = $this->pendingAdjustment($creator);

        $this->actingAs($delegate)
            ->getJson("/api/v1/payroll-adjustments/{$adjustment->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.permissions.view', true)
            ->assertJsonPath('data.permissions.approve', true)
            ->assertJsonPath('data.permissions.reject', false)
            ->assertJsonPath('data.available_actions.approve', true)
            ->assertJsonPath('data.available_actions.reject', false);
    }
}
