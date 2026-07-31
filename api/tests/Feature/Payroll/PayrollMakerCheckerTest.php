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
 * REC-04 — Payroll maker-checker Segregation of Duties.
 *
 * A ₱-material 200-employee run must not be created, computed, approved AND
 * finalized by one person with no attributable record. This covers:
 *   - the person who COMPUTED a run (computed_by) may not also APPROVE it,
 *   - a holder of payroll.periods.self_approve_override (system_admin) may,
 *   - approve()/finalize() stamp the approver/finalizer + write audit rows,
 *   - hr_officer (the maker) no longer holds payroll.periods.approve → 403.
 *
 * Harness mirrors PayrollPeriodVoidTest / PayrollPeriodForceUnlockTest.
 */
class PayrollMakerCheckerTest extends TestCase
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

    /**
     * A period ready for the approve step: Computed, with one payroll row.
     *
     * approve() now requires Computed rather than Draft — Draft means "never
     * computed", and approving that used to lock in an empty ₱0 payroll. A row
     * is needed too, since approve() refuses an empty batch.
     */
    private function draftPeriod(?User $computedBy = null): PayrollPeriod
    {
        $period = PayrollPeriod::factory()->create([
            'status' => PayrollPeriodStatus::Computed->value,
        ]);
        \App\Modules\Payroll\Models\Payroll::factory()->create([
            'payroll_period_id' => $period->id,
        ]);
        if ($computedBy !== null) {
            $period->forceFill(['computed_by' => $computedBy->id])->save();
        }
        return $period->fresh();
    }

    public function test_checker_who_did_not_compute_can_approve(): void
    {
        $maker   = $this->userWithRole('hr_officer');       // computed the run
        $checker = $this->userWithRole('finance_officer');  // approves it
        $period  = $this->draftPeriod($maker);

        $this->actingAs($checker)
            ->patchJson("/api/v1/payroll-periods/{$period->hash_id}/approve")
            ->assertStatus(200)
            ->assertJsonPath('data.status', PayrollPeriodStatus::Approved->value);

        $fresh = $period->fresh();
        $this->assertSame(PayrollPeriodStatus::Approved, $fresh->status);
        $this->assertSame($checker->id, $fresh->approved_by);
        $this->assertNotNull($fresh->approved_at);

        $this->assertDatabaseHas('audit_logs', [
            'action'     => 'payroll.period.approve',
            'model_type' => PayrollPeriod::class,
            'model_id'   => $period->id,
            'user_id'    => $checker->id,
        ]);
    }

    public function test_maker_who_computed_cannot_also_approve(): void
    {
        // finance_officer holds payroll.periods.approve but NOT
        // self_approve_override, so if they also computed the run the
        // maker-checker guard blocks approval.
        $actor  = $this->userWithRole('finance_officer');
        $period = $this->draftPeriod($actor);

        $this->actingAs($actor)
            ->patchJson("/api/v1/payroll-periods/{$period->hash_id}/approve")
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Maker-checker: the person who computed this payroll cannot also approve it. A different approver is required.',
            );

        // Untouched — still awaiting approval, no approver stamped.
        $fresh = $period->fresh();
        $this->assertSame(PayrollPeriodStatus::Computed, $fresh->status);
        $this->assertNull($fresh->approved_by);
        $this->assertNull($fresh->approved_at);
    }

    public function test_holder_of_self_approve_override_can_self_approve(): void
    {
        // system_admin has the wildcard → holds self_approve_override.
        $admin  = $this->userWithRole('system_admin');
        $period = $this->draftPeriod($admin); // computed_by == actor

        $this->actingAs($admin)
            ->patchJson("/api/v1/payroll-periods/{$period->hash_id}/approve")
            ->assertStatus(200)
            ->assertJsonPath('data.status', PayrollPeriodStatus::Approved->value);

        $this->assertSame($admin->id, $period->fresh()->approved_by);
    }

    public function test_finalize_stamps_finalizer_and_timestamp(): void
    {
        $checker = $this->userWithRole('finance_officer');
        $period  = PayrollPeriod::factory()->create([
            'status' => PayrollPeriodStatus::Approved->value,
        ]);

        $this->actingAs($checker)
            ->patchJson("/api/v1/payroll-periods/{$period->hash_id}/finalize")
            ->assertStatus(200)
            ->assertJsonPath('data.status', PayrollPeriodStatus::Finalized->value);

        $fresh = $period->fresh();
        $this->assertSame(PayrollPeriodStatus::Finalized, $fresh->status);
        $this->assertSame($checker->id, $fresh->finalized_by);
        $this->assertNotNull($fresh->finalized_at);

        $this->assertDatabaseHas('audit_logs', [
            'action'     => 'payroll.period.finalize',
            'model_type' => PayrollPeriod::class,
            'model_id'   => $period->id,
            'user_id'    => $checker->id,
        ]);
    }

    /**
     * REC-04 role split: hr_officer is the MAKER (create + compute) and no
     * longer holds payroll.periods.approve, so the route permission gate
     * returns 403 before the controller runs.
     */
    public function test_hr_officer_maker_cannot_approve(): void
    {
        $hr     = $this->userWithRole('hr_officer');
        $period = $this->draftPeriod();

        $this->actingAs($hr)
            ->patchJson("/api/v1/payroll-periods/{$period->hash_id}/approve")
            ->assertStatus(403);

        $this->assertSame(PayrollPeriodStatus::Computed, $period->fresh()->status);
    }
}
