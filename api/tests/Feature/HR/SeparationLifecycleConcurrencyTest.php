<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ClearanceStatus;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Events\ClearanceFullySigned;
use App\Modules\HR\Listeners\DeactivateAccountOnClearanceComplete;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmploymentHistory;
use App\Modules\HR\Services\FinalPayService;
use App\Modules\HR\Services\SeparationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The H2R aggregate crosses HR, identity, and accounting boundaries. These
 * tests keep stale route-bound models and replayed queue events from mutating
 * a newer authoritative clearance state.
 */
class SeparationLifecycleConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_replayed_initiation_does_not_create_a_second_clearance(): void
    {
        Queue::fake();
        app(SettingsService::class)->set('hr.separation.clearance_checklist', [
            ['department' => 'HR', 'item_key' => 'exit_interview', 'label' => 'Exit interview'],
            ['department' => 'FIN', 'item_key' => 'accountability', 'label' => 'Accountability'],
        ], 'hr');

        $employee = Employee::factory()->create();
        $actor = User::factory()->create();
        $service = app(SeparationService::class);

        $service->initiate($employee, [
            'separation_reason' => 'resigned',
            'separation_date' => '2026-09-15',
        ], $actor);

        try {
            $service->initiate($employee->fresh(), [
                'separation_reason' => 'resigned',
                'separation_date' => '2026-09-15',
            ], $actor);
            $this->fail('A replayed initiation must not create a parallel clearance.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('Employee already has an active separation clearance.', $e->getMessage());
        }

        $this->assertDatabaseCount('clearances', 1);
        $this->assertDatabaseCount('event_outbox', 1);
        $this->assertSame(EmployeeStatus::OnLeave, $employee->fresh()->status);
    }

    public function test_stale_checklist_requests_preserve_both_department_signatures(): void
    {
        Queue::fake();
        $employee = Employee::factory()->create();
        $clearance = Clearance::factory()->create([
            'employee_id' => $employee->id,
            'status' => ClearanceStatus::InProgress->value,
            'clearance_items' => [
                ['department' => 'HR', 'item_key' => 'hr_signoff', 'label' => 'HR', 'status' => 'pending', 'signed_by' => null, 'signed_at' => null, 'remarks' => null],
                ['department' => 'FIN', 'item_key' => 'finance_signoff', 'label' => 'Finance', 'status' => 'pending', 'signed_by' => null, 'signed_at' => null, 'remarks' => null],
            ],
        ]);
        $staleHr = $clearance->fresh();
        $staleFinance = $clearance->fresh();
        $service = app(SeparationService::class);

        $service->signItem($staleHr, 'hr_signoff', User::factory()->create());
        $service->signItem($staleFinance, 'finance_signoff', User::factory()->create());

        $fresh = $clearance->fresh();
        $items = collect($fresh->clearance_items)->keyBy('item_key');

        $this->assertSame('cleared', $items['hr_signoff']['status']);
        $this->assertSame('cleared', $items['finance_signoff']['status']);
        $this->assertSame(ClearanceStatus::Completed, $fresh->status);
        $this->assertDatabaseCount('event_outbox', 1);
        $this->assertDatabaseHas('chain_step_runs', [
            'chain' => 'h2r',
            'entity_type' => 'clearance',
            'entity_id' => $clearance->id,
            'step' => ClearanceStatus::Completed->value,
        ]);
    }

    public function test_stale_checklist_request_cannot_reopen_a_finalized_clearance(): void
    {
        $employee = Employee::factory()->create();
        $clearance = Clearance::factory()->create([
            'employee_id' => $employee->id,
            'status' => ClearanceStatus::InProgress->value,
            'clearance_items' => [
                ['department' => 'HR', 'item_key' => 'hr_signoff', 'label' => 'HR', 'status' => 'pending', 'signed_by' => null, 'signed_at' => null, 'remarks' => null],
            ],
        ]);
        $stale = $clearance->fresh();
        $clearance->forceFill(['status' => ClearanceStatus::Finalized->value])->save();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Clearance is closed.');

        app(SeparationService::class)->signItem($stale, 'hr_signoff', User::factory()->create());
    }

    public function test_replayed_finalization_cannot_post_a_second_final_pay_or_history_row(): void
    {
        $employee = Employee::factory()->create();
        $clearance = Clearance::factory()->create([
            'employee_id' => $employee->id,
            'status' => ClearanceStatus::Completed->value,
            'final_pay_computed' => true,
            'final_pay_amount' => '100.00',
        ]);
        $stale = $clearance->fresh();
        $actor = User::factory()->create();

        $this->mock(FinalPayService::class)
            ->shouldReceive('postJournalEntry')
            ->once()
            ->andReturn(new JournalEntry());

        $service = app(SeparationService::class);
        $service->finalize($clearance, $actor, app(FinalPayService::class));

        try {
            $service->finalize($stale, $actor, app(FinalPayService::class));
            $this->fail('A replayed finalization must not post another final-pay entry.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('Clearance is already finalized.', $e->getMessage());
        }

        $this->assertSame(ClearanceStatus::Finalized, $clearance->fresh()->status);
        $this->assertSame(EmployeeStatus::Resigned, $employee->fresh()->status);
        $this->assertSame(1, EmploymentHistory::query()->where('employee_id', $employee->id)->count());
    }

    public function test_delayed_completion_event_does_not_deactivate_a_cancelled_clearance_account(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->create([
            'employee_id' => $employee->id,
            'is_active' => true,
        ]);
        $clearance = Clearance::factory()->create([
            'employee_id' => $employee->id,
            'status' => ClearanceStatus::Cancelled->value,
        ]);

        app(DeactivateAccountOnClearanceComplete::class)
            ->handle(new ClearanceFullySigned($clearance->fresh()));

        $this->assertTrue((bool) $user->fresh()->is_active);

        $clearance->forceFill(['status' => ClearanceStatus::Completed->value])->save();
        app(DeactivateAccountOnClearanceComplete::class)
            ->handle(new ClearanceFullySigned($clearance->fresh()));

        $this->assertFalse((bool) $user->fresh()->is_active);
    }
}
