<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\AuditLog;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ClearanceStatus;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\SeparationReason;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\FinalPayService;
use App\Modules\HR\Services\SeparationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * HR-04 audit — cancellation path for initiated separations.
 *
 * Before this fix an initiated clearance could only move forward through all
 * signatures and final pay; a rescinded resignation or a mistyped separation
 * date left the employee permanently stuck in separation.
 */
class SeparationCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->setChecklist([
            ['department' => 'HR', 'item_key' => 'exit_interview', 'label' => 'Exit interview'],
            ['department' => 'FIN', 'item_key' => 'accountability', 'label' => 'Accountability'],
        ]);
    }

    /**
     * Settings are cached in Redis, which RefreshDatabase does not reset.
     *
     * @param  array<int, array<string, string>>  $items
     */
    private function setChecklist(array $items): void
    {
        Cache::flush();
        app(SettingsService::class)->set('hr.separation.clearance_checklist', $items, 'hr');
        Cache::flush();
    }

    private function actor(string $role = 'system_admin'): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role)->value('id')]);
    }

    private function service(): SeparationService
    {
        return app(SeparationService::class);
    }

    private function initiate(Employee $employee, ?User $by = null): Clearance
    {
        return $this->service()->initiate($employee, [
            'separation_reason' => SeparationReason::Resigned->value,
            'separation_date' => '2026-12-31',
        ], $by ?? $this->actor());
    }

    // ── (a) cancel a Pending clearance ─────────────────────────────────────

    public function test_cancelling_a_pending_clearance_restores_the_employee(): void
    {
        $employee = Employee::factory()->create(['status' => EmployeeStatus::OnLeave->value]);
        $clearance = Clearance::factory()->create([
            'employee_id' => $employee->id,
            'status' => ClearanceStatus::Pending->value,
        ]);

        $cancelled = $this->service()->cancel($clearance, $this->actor(), 'Resignation rescinded.');

        $this->assertSame(ClearanceStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertSame(EmployeeStatus::Active, $employee->fresh()->status);
        $this->assertStringContainsString('Resignation rescinded.', $cancelled->fresh()->remarks);
    }

    // ── (b) cancel an InProgress clearance with items already signed ──────

    public function test_cancelling_an_in_progress_clearance_with_signed_items_restores_the_employee(): void
    {
        $employee = Employee::factory()->create();
        $actor = $this->actor();
        $clearance = $this->initiate($employee, $actor);
        $this->service()->signItem($clearance, 'exit_interview', $actor);

        $this->assertSame(EmployeeStatus::OnLeave, $employee->fresh()->status);

        $this->service()->cancel($clearance->fresh(), $actor, 'Typo in separation date.');

        $this->assertSame(ClearanceStatus::Cancelled, $clearance->fresh()->status);
        $this->assertSame(EmployeeStatus::Active, $employee->fresh()->status);
    }

    public function test_cancel_restores_the_exact_pre_initiation_status(): void
    {
        $employee = Employee::factory()->create(['status' => EmployeeStatus::Suspended->value]);
        $clearance = $this->initiate($employee);
        $this->assertSame(EmployeeStatus::OnLeave, $employee->fresh()->status);

        $this->service()->cancel($clearance->fresh(), $this->actor());

        $this->assertSame(EmployeeStatus::Suspended, $employee->fresh()->status);
    }

    public function test_reinitiation_is_allowed_after_cancellation(): void
    {
        $employee = Employee::factory()->create();
        $clearance = $this->initiate($employee);
        $this->service()->cancel($clearance->fresh(), $this->actor());

        $replacement = $this->initiate($employee);

        $this->assertSame(ClearanceStatus::InProgress, $replacement->fresh()->status);
        $this->assertNotSame($clearance->id, $replacement->id);
        $this->assertSame(EmployeeStatus::OnLeave, $employee->fresh()->status);
    }

    // ── (c) cancel refused once money is in play ───────────────────────────

    public function test_cancel_is_refused_after_final_pay_computed(): void
    {
        $employee = Employee::factory()->create();
        $clearance = $this->initiate($employee);
        app(FinalPayService::class)->compute($clearance->fresh(), $this->actor());

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('can no longer be cancelled');

        $this->service()->cancel($clearance->fresh(), $this->actor());
    }

    public function test_cancel_is_refused_for_completed_and_finalized_clearances(): void
    {
        $employee = Employee::factory()->create();

        foreach ([ClearanceStatus::Completed, ClearanceStatus::Finalized] as $status) {
            $clearance = Clearance::factory()->create([
                'employee_id' => $employee->id,
                'status' => $status->value,
            ]);

            try {
                $this->service()->cancel($clearance, $this->actor());
                $this->fail("Cancellation of a {$status->value} clearance must be refused.");
            } catch (BusinessRuleException $e) {
                $this->assertStringContainsString('pending or in-progress', $e->getMessage());
            }

            $this->assertSame($status, $clearance->fresh()->status);
        }

        $this->assertSame(EmployeeStatus::Active, $employee->fresh()->status);
    }

    public function test_cancel_is_refused_for_an_already_cancelled_clearance(): void
    {
        $clearance = Clearance::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'status' => ClearanceStatus::Cancelled->value,
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('already cancelled');

        $this->service()->cancel($clearance, $this->actor());
    }

    // ── (d) permission gate ────────────────────────────────────────────────

    public function test_cancel_endpoint_is_forbidden_without_the_permission(): void
    {
        $employee = Employee::factory()->create();
        $clearance = $this->initiate($employee);

        $this->actingAs($this->actor('warehouse_staff'))
            ->patchJson("/api/v1/hr/clearances/{$clearance->hash_id}/cancel", ['reason' => 'Nope.'])
            ->assertForbidden();

        $this->assertSame(ClearanceStatus::InProgress, $clearance->fresh()->status);
        $this->assertSame(EmployeeStatus::OnLeave, $employee->fresh()->status);
    }

    public function test_cancel_endpoint_succeeds_for_hr_officer(): void
    {
        $employee = Employee::factory()->create();
        $clearance = $this->initiate($employee, $this->actor('hr_officer'));

        $this->actingAs($this->actor('hr_officer'))
            ->patchJson("/api/v1/hr/clearances/{$clearance->hash_id}/cancel", ['reason' => 'Resignation rescinded.'])
            ->assertOk()
            ->assertJsonPath('data.status', ClearanceStatus::Cancelled->value);

        $this->assertSame(EmployeeStatus::Active, $employee->fresh()->status);
    }

    // ── (e) audit trail records the cancellation actor ─────────────────────

    public function test_cancellation_records_the_actor_in_the_audit_trail(): void
    {
        $officer = $this->actor('hr_officer');
        $employee = Employee::factory()->create();
        $clearance = $this->initiate($employee, $officer);

        $this->actingAs($officer)
            ->patchJson("/api/v1/hr/clearances/{$clearance->hash_id}/cancel", ['reason' => 'Resignation rescinded.'])
            ->assertOk();

        $audit = AuditLog::query()
            ->where('model_type', Clearance::class)
            ->where('model_id', $clearance->id)
            ->where('action', 'updated')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($officer->id, $audit->user_id);
        $this->assertSame(ClearanceStatus::Cancelled->value, $audit->new_values['status']);
        $this->assertStringContainsString('Resignation rescinded.', (string) $audit->reason);
    }
}
