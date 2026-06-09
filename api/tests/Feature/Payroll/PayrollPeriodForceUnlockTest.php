<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H-8 — POST /payroll-periods/{period}/force-unlock.
 *
 * Covers the admin escape hatch for periods stuck at Processing because the
 * payroll job worker crashed (OOM, SIGKILL, host reboot) before its finally
 * block could reset status.
 */
class PayrollPeriodForceUnlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->value('id'),
        ]);
    }

    public function test_system_admin_can_force_unlock_a_stuck_processing_period(): void
    {
        $admin = $this->userWithRole('system_admin');
        $period = PayrollPeriod::factory()->create([
            'status' => PayrollPeriodStatus::Processing->value,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/force-unlock", [
                'reason' => 'worker OOM-killed at 02:47',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', PayrollPeriodStatus::Draft->value)
            ->assertJsonPath('message', 'Period unlocked. You can re-run compute.');

        $this->assertSame(
            PayrollPeriodStatus::Draft,
            $period->fresh()->status,
        );

        $this->assertDatabaseHas('audit_logs', [
            'action'     => 'payroll.period.force_unlock',
            'model_type' => PayrollPeriod::class,
            'model_id'   => $period->id,
            'user_id'    => $admin->id,
        ]);

        $log = AuditLog::query()
            ->where('action', 'payroll.period.force_unlock')
            ->where('model_id', $period->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('processing', $log->old_values['status'] ?? null);
        $this->assertSame('draft',      $log->new_values['status'] ?? null);
        $this->assertSame('worker OOM-killed at 02:47', $log->new_values['reason'] ?? null);
    }

    public function test_rejects_force_unlock_when_status_is_draft(): void
    {
        $admin = $this->userWithRole('system_admin');
        $period = PayrollPeriod::factory()->create([
            'status' => PayrollPeriodStatus::Draft->value,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/force-unlock")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only periods stuck at Processing can be force-unlocked.');
    }

    public function test_rejects_force_unlock_when_status_is_finalized(): void
    {
        $admin = $this->userWithRole('system_admin');
        $period = PayrollPeriod::factory()->create([
            'status' => PayrollPeriodStatus::Finalized->value,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/force-unlock")
            ->assertStatus(422);

        $this->assertSame(
            PayrollPeriodStatus::Finalized,
            $period->fresh()->status,
        );
    }

    public function test_rejects_force_unlock_when_status_is_disbursed(): void
    {
        $admin = $this->userWithRole('system_admin');
        $period = PayrollPeriod::factory()->create([
            'status' => PayrollPeriodStatus::Disbursed->value,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/force-unlock")
            ->assertStatus(422);

        $this->assertSame(
            PayrollPeriodStatus::Disbursed,
            $period->fresh()->status,
        );
    }

    public function test_rejects_unauthorized_user_without_permission(): void
    {
        $employee = $this->userWithRole('employee');
        $period = PayrollPeriod::factory()->create([
            'status' => PayrollPeriodStatus::Processing->value,
        ]);

        $this->actingAs($employee)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/force-unlock")
            ->assertStatus(403);

        $this->assertSame(
            PayrollPeriodStatus::Processing,
            $period->fresh()->status,
        );
    }

    /**
     * The task spec called this "payroll_officer" but the seeder's two roles
     * holding payroll.periods.finalize today are system_admin (wildcard) and
     * finance_officer (full payroll module). finance_officer therefore picks
     * up payroll.periods.force_unlock automatically because it inherits every
     * slug under module('payroll'). Lock that in here.
     */
    public function test_finance_officer_can_also_force_unlock(): void
    {
        $finance = $this->userWithRole('finance_officer');
        $period = PayrollPeriod::factory()->create([
            'status' => PayrollPeriodStatus::Processing->value,
        ]);

        $this->actingAs($finance)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/force-unlock", [
                'reason' => 'host rebooted mid-batch',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', PayrollPeriodStatus::Draft->value);

        $this->assertSame(
            PayrollPeriodStatus::Draft,
            $period->fresh()->status,
        );
    }
}
