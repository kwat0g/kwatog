<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Models\AuditLog;
use App\Common\Models\ApprovalDelegation;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollPeriodService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
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

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

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
        // The row's employee must be inside the period window: the employee
        // factory's random date_hired can land after the period's random end
        // date, which makes the row ineligible and turns approve() into a
        // spurious 'rows do not match the eligible employee set' 422.
        $employee = Employee::factory()->create([
            'date_hired' => \Illuminate\Support\Carbon::parse($period->period_start)
                ->subDay()->toDateString(),
        ]);
        \App\Modules\Payroll\Models\Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id'       => $employee->id,
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

    public function test_pending_approval_notice_includes_authorized_delegate(): void
    {
        $finance = $this->userWithRole('finance_officer');
        $delegate = $this->userWithRole('hr_officer');
        $period = PayrollPeriod::factory()->create(['created_by' => null]);
        $period->forceFill(['status' => PayrollPeriodStatus::Computed->value])->save();
        ApprovalDelegation::create([
            'delegator_user_id' => $finance->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        app(PayrollPeriodService::class)->notifyPendingApproval($period);

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $delegate->id,
            'type' => 'approval.action_required',
        ]);
    }

    public function test_maker_who_computed_cannot_also_approve(): void
    {
        // Finance cannot ordinarily compute, but this protects historical or
        // exceptional data where a checker is recorded as the run's maker.
        // It holds payroll.periods.approve but not self_approve_override.
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

    public function test_creator_who_becomes_a_finance_checker_cannot_approve_their_period(): void
    {
        $creator = $this->userWithRole('hr_officer');
        $computer = $this->userWithRole('hr_officer');
        $period = $this->draftPeriod($computer);
        $period->forceFill(['created_by' => $creator->id])->save();

        // A role change during an in-flight period must not turn its requester
        // into an eligible checker.
        $creator->role_id = Role::where('slug', 'finance_officer')->value('id');
        $creator->save();

        $this->actingAs($creator)
            ->patchJson("/api/v1/payroll-periods/{$period->hash_id}/approve")
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Maker-checker: the person who created this payroll period cannot approve it. A different approver is required.',
            );

        $period->refresh();
        $this->assertSame(PayrollPeriodStatus::Computed, $period->status);
        $this->assertNull($period->approved_by);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'payroll.period.approve',
            'model_type' => PayrollPeriod::class,
            'model_id' => $period->id,
        ]);
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
        $audit = AuditLog::query()
            ->where('action', 'payroll.period.approve')
            ->where('model_id', $period->id)
            ->firstOrFail();
        $this->assertSame((int) $admin->id, (int) $audit->user_id);
        $this->assertTrue($audit->new_values['maker_checker_override']);
    }

    public function test_finalize_stamps_finalizer_and_timestamp(): void
    {
        $maker = $this->userWithRole('hr_officer');
        $checker = $this->userWithRole('finance_officer');
        $finalizer = $this->userWithRole('finance_officer');
        $period = $this->draftPeriod($maker);

        $this->actingAs($checker)
            ->patchJson("/api/v1/payroll-periods/{$period->hash_id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', PayrollPeriodStatus::Approved->value);

        $this->actingAs($finalizer)
            ->patchJson("/api/v1/payroll-periods/{$period->hash_id}/finalize")
            ->assertStatus(200)
            ->assertJsonPath('data.status', PayrollPeriodStatus::Finalized->value);

        $fresh = $period->fresh();
        $this->assertSame(PayrollPeriodStatus::Finalized, $fresh->status);
        $this->assertSame($finalizer->id, $fresh->finalized_by);
        $this->assertNotNull($fresh->finalized_at);
        $this->assertSame($checker->id, $fresh->approved_by);

        $this->assertDatabaseHas('audit_logs', [
            'action'     => 'payroll.period.finalize',
            'model_type' => PayrollPeriod::class,
            'model_id'   => $period->id,
            'user_id'    => $finalizer->id,
        ]);
    }

    public function test_approver_cannot_finalize_the_same_period(): void
    {
        $maker = $this->userWithRole('hr_officer');
        $approver = $this->userWithRole('finance_officer');
        $period = $this->draftPeriod($maker);

        $this->actingAs($approver)
            ->patchJson("/api/v1/payroll-periods/{$period->hash_id}/approve")
            ->assertOk();

        $this->actingAs($approver)
            ->patchJson("/api/v1/payroll-periods/{$period->hash_id}/finalize")
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'The approver cannot finalize the same payroll period. A different finance user must finalize it.',
            );

        $period->refresh();
        $this->assertSame(PayrollPeriodStatus::Approved, $period->status);
        $this->assertNull($period->finalized_by);
        $this->assertNull($period->finalized_at);
        $this->assertSame(0, $period->bankFileRecords()->count());
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'payroll.period.finalize',
            'model_type' => PayrollPeriod::class,
            'model_id' => $period->id,
        ]);

        $finalizer = $this->userWithRole('finance_officer');
        $this->actingAs($finalizer)
            ->patchJson("/api/v1/payroll-periods/{$period->hash_id}/finalize")
            ->assertOk()
            ->assertJsonPath('data.status', PayrollPeriodStatus::Finalized->value);

        $this->assertSame($finalizer->id, $period->fresh()->finalized_by);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payroll.period.finalize',
            'model_type' => PayrollPeriod::class,
            'model_id' => $period->id,
            'user_id' => $finalizer->id,
        ]);
    }

    public function test_finalize_requires_a_recorded_approval_actor(): void
    {
        $period = PayrollPeriod::factory()->create([
            'status' => PayrollPeriodStatus::Approved->value,
        ]);
        $finance = $this->userWithRole('finance_officer');

        $this->actingAs($finance)
            ->patchJson("/api/v1/payroll-periods/{$period->hash_id}/finalize")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot finalize: this period has no recorded approver. Return it for correction before continuing.');

        $period->refresh();
        $this->assertSame(PayrollPeriodStatus::Approved, $period->status);
        $this->assertNull($period->finalized_by);
        $this->assertSame(0, $period->bankFileRecords()->count());
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'payroll.period.finalize',
            'model_type' => PayrollPeriod::class,
            'model_id' => $period->id,
        ]);

        $this->actingAs($finance)
            ->patchJson("/api/v1/payroll-periods/{$period->hash_id}/request-correction", [
                'reason' => 'The approval actor is missing from this historical record.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', PayrollPeriodStatus::Draft->value);

        $this->assertSame(PayrollPeriodStatus::Draft, $period->fresh()->status);
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

    public function test_finance_can_return_a_computed_period_to_hr_for_correction(): void
    {
        $finance = $this->userWithRole('finance_officer');
        $period = $this->draftPeriod($this->userWithRole('hr_officer'));

        $this->actingAs($finance)
            ->patchJson("/api/v1/payroll-periods/{$period->hash_id}/request-correction", [
                'reason' => 'Production overtime does not match the approved DTR total.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', PayrollPeriodStatus::Draft->value)
            ->assertJsonPath('data.correction_reason', 'Production overtime does not match the approved DTR total.')
            ->assertJsonPath('data.correction_requester.id', $finance->hash_id);

        $this->assertSame(PayrollPeriodStatus::Draft, $period->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payroll.period.request_correction',
            'model_type' => PayrollPeriod::class,
            'model_id' => $period->id,
            'user_id' => $finance->id,
        ]);
    }

    public function test_hr_cannot_request_a_correction_and_finance_must_provide_a_reason(): void
    {
        $period = $this->draftPeriod($this->userWithRole('hr_officer'));

        $this->actingAs($this->userWithRole('hr_officer'))
            ->patchJson("/api/v1/payroll-periods/{$period->hash_id}/request-correction", [
                'reason' => 'Incorrect amount',
            ])
            ->assertForbidden();

        $this->actingAs($this->userWithRole('finance_officer'))
            ->patchJson("/api/v1/payroll-periods/{$period->hash_id}/request-correction", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->assertSame(PayrollPeriodStatus::Computed, $period->fresh()->status);
    }
}
