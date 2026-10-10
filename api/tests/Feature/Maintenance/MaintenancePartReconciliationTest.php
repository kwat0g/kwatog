<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Models\SparePartUsage;
use App\Modules\Maintenance\Services\SparePartUsageService;
use App\Modules\MRP\Models\Machine;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MaintenancePartReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_zero_outstanding_parts_need_no_confirmation_and_replay_does_not_duplicate_the_log(): void
    {
        [$technician, $order] = $this->fixture();
        $before = StockMovement::query()->count();
        $url = $this->url($order);

        $this->actingAs($technician)->postJson($url.'/reconcile-parts')->assertOk();
        $this->postJson($url.'/reconcile-parts')->assertOk();

        $this->assertNotNull($order->fresh()->parts_reconciled_at);
        $this->assertSame($technician->id, $order->fresh()->parts_reconciled_by);
        $this->assertSame($before, StockMovement::query()->count());
        $this->assertSame(1, $order->logs()->where('description', 'like', 'Parts reconciled:%')->count());
    }

    public function test_positive_outstanding_parts_require_confirmation_before_complete_and_reconciliation_adds_no_stock_move(): void
    {
        [$technician, $order, $item, $location, $workLog] = $this->fixture();
        $usage = $this->issue($order, $technician, $item, $location, $workLog, '2');
        $this->recordPass($order, $technician);
        $url = $this->url($order);
        $movementCount = StockMovement::query()->count();

        $this->actingAs($technician)->patchJson($url.'/complete')->assertUnprocessable()
            ->assertJsonValidationErrors('parts_reconciliation');
        $this->postJson($url.'/reconcile-parts')->assertUnprocessable()
            ->assertJsonValidationErrors('confirm_consumed');
        $this->postJson($url.'/reconcile-parts', ['confirm_consumed' => true])->assertOk();
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertNotNull($order->fresh()->parts_reconciled_at);
        $this->patchJson($url.'/complete')->assertOk();

        $this->assertSame('completed', $order->fresh()->status->value);
        $this->assertSame('2.000', $usage->fresh()->quantity);
    }

    public function test_fully_returned_parts_do_not_need_an_attestation_to_complete(): void
    {
        [$technician, $order, $item, $location, $workLog] = $this->fixture();
        $usage = $this->issue($order, $technician, $item, $location, $workLog, '2');
        app(SparePartUsageService::class)->returnPart($order, $usage, '2', $technician, (string) Str::uuid());
        $this->recordPass($order, $technician);

        $this->actingAs($technician)->patchJson($this->url($order).'/complete')->assertOk();

        $this->assertSame('completed', $order->fresh()->status->value);
        $this->assertNull($order->fresh()->parts_reconciled_at);
    }

    public function test_successful_issue_and_return_clear_attestation_but_issue_replay_does_not(): void
    {
        [$technician, $order, $item, $location, $workLog] = $this->fixture();
        $first = $this->issue($order, $technician, $item, $location, $workLog, '3');
        $url = $this->url($order);
        $this->actingAs($technician)->postJson($url.'/reconcile-parts', ['confirm_consumed' => true])->assertOk();

        $key = (string) Str::uuid();
        $second = $this->issue($order, $technician, $item, $location, $workLog, '1', $key);
        $this->assertNull($order->fresh()->parts_reconciled_at);
        $this->postJson($url.'/reconcile-parts', ['confirm_consumed' => true])->assertOk();
        $this->issue($order, $technician, $item, $location, $workLog, '1', $key);
        $this->assertNotNull($order->fresh()->parts_reconciled_at);

        app(SparePartUsageService::class)->returnPart($order, $second, '1', $technician, (string) Str::uuid());
        $this->assertNull($order->fresh()->parts_reconciled_at);
        $this->assertSame('3.000', $first->fresh()->quantity);
    }

    public function test_reassignment_requires_the_new_technician_to_reconcile_remaining_parts(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 10:00', config('app.timezone')));
        [$technician, $order, $item, $location, $workLog] = $this->fixture();
        $replacement = $this->actor('maintenance_tech');
        $supervisor = $this->actor('maintenance_supervisor');
        $this->setTechnicianAvailability($technician, '08:00', '16:00', 'present');
        $this->setTechnicianAvailability($replacement, '08:00', '16:00', 'present');
        $this->issue($order, $technician, $item, $location, $workLog, '1');
        $this->recordPass($order, $technician);
        $url = $this->url($order);

        $this->actingAs($technician)->postJson($url.'/reconcile-parts', ['confirm_consumed' => true])->assertOk();
        $this->actingAs($supervisor)->patchJson($url.'/assign', [
            'employee_id' => $replacement->employee->hash_id,
            'expected_assignee_id' => $technician->employee->hash_id,
            'handover_note' => 'Replacement technician will inspect the issued part.',
        ])->assertOk();

        $this->assertNull($order->fresh()->parts_reconciled_at);
        $this->actingAs($replacement)->getJson($url)->assertOk()
            ->assertJsonPath('data.parts_readiness.can_close_parts', false);
        $this->patchJson($url.'/complete')->assertUnprocessable()
            ->assertJsonValidationErrors('parts_reconciliation');
        $this->postJson($url.'/reconcile-parts', ['confirm_consumed' => true])->assertOk();
        $this->patchJson($url.'/complete')->assertOk();
    }

    public function test_repair_submission_and_verifier_acceptance_recheck_part_reconciliation(): void
    {
        [$technician, $order, $item, $location, $workLog] = $this->fixture(true);
        $verifier = $this->actor('production_manager');
        $this->issue($order, $technician, $item, $location, $workLog, '1');
        $this->recordPass($order, $technician);
        $url = $this->url($order);
        $evidence = [
            'diagnosis' => 'Worn coupling',
            'repair_action' => 'Replaced coupling',
            'functional_test_result' => 'Loaded test passed',
        ];

        $this->actingAs($technician)->postJson($url.'/submit-repair', $evidence)->assertUnprocessable()
            ->assertJsonValidationErrors('parts_reconciliation');
        $this->postJson($url.'/reconcile-parts', ['confirm_consumed' => true])->assertOk();
        $this->postJson($url.'/submit-repair', $evidence)->assertOk();

        // Exercise the final locked guard against an invalidated legacy/direct write.
        $order->forceFill(['parts_reconciled_at' => null, 'parts_reconciled_by' => null])->save();
        $this->actingAs($verifier)->postJson($url.'/verify', ['decision' => 'accept'])->assertUnprocessable()
            ->assertJsonValidationErrors('parts_reconciliation');
    }

    public function test_supervisor_can_reconcile_and_cancel_only_after_assignee_becomes_unavailable(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 10:00', config('app.timezone')));
        [$technician, $order, $item, $location, $workLog] = $this->fixture();
        $supervisor = $this->actor('maintenance_supervisor');
        $this->setTechnicianAvailability($technician, '08:00', '16:00', 'present');
        $this->issue($order, $technician, $item, $location, $workLog, '1');
        $url = $this->url($order);
        $payload = ['confirm_consumed' => true, 'inspection_reason' => 'Technician account unavailable'];

        $this->actingAs($supervisor)->postJson($url.'/reconcile-parts', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_to');
        $technician->update(['is_active' => false]);
        $this->postJson($url.'/reconcile-parts', $payload)->assertOk();
        $attestedAt = $order->fresh()->parts_reconciled_at;
        $reconciliationLogs = $order->logs()->where('description', 'like', 'Parts reconciled:%')->count();
        $this->postJson($url.'/reconcile-parts', $payload)->assertOk();
        $this->assertTrue($order->fresh()->parts_reconciled_at->equalTo($attestedAt));
        $this->assertSame($reconciliationLogs, $order->logs()->where('description', 'like', 'Parts reconciled:%')->count());
        $this->patchJson($url.'/cancel', ['reason' => 'Technician unavailable; parts inspected'])->assertOk();
        $this->assertSame('cancelled', $order->fresh()->status->value);

        $next = $this->newOrder($technician, false);
        $nextUrl = $this->url($next);
        $this->patchJson($nextUrl.'/start')->assertForbidden();
        $this->postJson($nextUrl.'/work-records', [
            'finding' => 'Fault found', 'action_taken' => 'Testing', 'result' => 'in_progress',
        ])->assertForbidden();
        $this->postJson($nextUrl.'/submit-repair', [
            'diagnosis' => 'Fault', 'repair_action' => 'Repair', 'functional_test_result' => 'Passed',
        ])->assertForbidden();
        $this->patchJson($nextUrl.'/complete')->assertForbidden();
    }

    public function test_supervisor_may_cancel_when_technician_is_absent_or_off_shift(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 10:00', config('app.timezone')));
        $supervisor = $this->actor('maintenance_supervisor');

        foreach ([['08:00', '16:00', 'absent'], ['08:00', '09:00', null]] as [$start, $end, $attendance]) {
            [$technician, $order] = $this->fixture();
            $this->setTechnicianAvailability($technician, $start, $end, $attendance);
            $url = $this->url($order);

            $this->actingAs($supervisor)->postJson($url.'/reconcile-parts', [
                'inspection_reason' => 'Technician unavailable for inspection',
            ])->assertOk();
            $this->patchJson($url.'/cancel', ['reason' => 'Technician unavailable; equipment withdrawn'])->assertOk();
            $this->assertSame('cancelled', $order->fresh()->status->value);
        }
    }

    public function test_supervisor_detail_offers_cancellation_only_when_technician_is_unavailable(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 10:00', config('app.timezone')));
        [$technician, $order] = $this->fixture();
        $supervisor = $this->actor('maintenance_supervisor');
        $this->setTechnicianAvailability($technician, '08:00', '16:00', 'present');
        $url = $this->url($order);

        $present = $this->actingAs($supervisor)->getJson($url)->assertOk();
        $this->assertNotContains('cancel', $present->json('data.available_actions'));
        Attendance::where('employee_id', $technician->employee_id)->update(['status' => 'absent']);
        $absent = $this->getJson($url)->assertOk();
        $this->assertContains('cancel', $absent->json('data.available_actions'));
        $this->assertNotContains('start', $absent->json('data.available_actions'));
    }

    public function test_cancel_and_verified_acceptance_cannot_bypass_positive_outstanding_parts(): void
    {
        [$technician, $order, $item, $location, $workLog] = $this->fixture(true);
        $this->issue($order, $technician, $item, $location, $workLog, '1');
        $url = $this->url($order);
        $this->actingAs($technician)->patchJson($url.'/cancel', ['reason' => 'Work withdrawn'])->assertUnprocessable()
            ->assertJsonValidationErrors('parts_reconciliation');

        $order->forceFill([
            'repair_submitted_at' => now(),
            'repair_submitted_by' => $technician->id,
            'diagnosis' => 'Fault',
            'repair_action' => 'Repair',
            'functional_test_result' => 'Passed',
        ])->save();
        $verifier = $this->actor('production_manager');
        $this->actingAs($verifier)->postJson($url.'/verify', ['decision' => 'accept'])->assertUnprocessable()
            ->assertJsonValidationErrors('parts_reconciliation');
    }

    public function test_issue_and_close_serialize_when_callers_hold_stale_work_order_instances(): void
    {
        [$technician, $order, $item, $location, $workLog] = $this->fixture();
        $staleIssuerView = MaintenanceWorkOrder::query()->findOrFail($order->id);
        $this->recordPass($order, $technician);
        $this->actingAs($technician)->patchJson($this->url($order).'/complete')->assertOk();
        $movementCount = StockMovement::query()->count();

        try {
            app(SparePartUsageService::class)->record($staleIssuerView, [
                'item_id' => $item->id,
                'location_id' => $location->id,
                'quantity' => '1',
                'work_record_id' => $workLog->id,
            ], $technician);
            $this->fail('A stale issue caller changed stock after the work order closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame('completed', $order->fresh()->status->value);
    }

    private function fixture(bool $verificationRequired = false): array
    {
        $technician = $this->actor('maintenance_tech');
        $order = $this->newOrder($technician, $verificationRequired);
        $item = Item::factory()->create(['item_type' => 'spare_part', 'unit_of_measure' => 'pcs']);
        $location = WarehouseLocation::factory()->create();
        StockLevel::create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '100.000',
            'weighted_avg_cost' => '5.0000',
        ]);
        $workLog = $order->logs()->create([
            'description' => 'Finding and action recorded.',
            'finding' => 'Worn part',
            'action_taken' => 'Removed for inspection',
            'result' => 'in_progress',
            'logged_by' => $technician->id,
        ]);

        return [$technician, $order, $item, $location, $workLog];
    }

    private function newOrder(User $technician, bool $verificationRequired): MaintenanceWorkOrder
    {
        $machine = Machine::factory()->create(['status' => 'maintenance']);

        return MaintenanceWorkOrder::create([
            'mwo_number' => 'RECON-'.Str::upper(Str::random(8)),
            'maintainable_type' => 'machine',
            'maintainable_id' => $machine->id,
            'type' => 'corrective',
            'priority' => 'high',
            'description' => 'Part reconciliation fixture',
            'status' => 'in_progress',
            'assigned_to' => $technician->employee_id,
            'started_at' => now()->subMinutes(20),
            'verification_required' => $verificationRequired,
            'created_by' => $technician->id,
        ]);
    }

    private function actor(string $roleSlug): User
    {
        $department = Department::firstOrCreate(['code' => 'MAINT'], ['name' => 'Maintenance']);
        $employee = Employee::factory()->create(['department_id' => $department->id, 'status' => 'active']);

        return User::factory()->create([
            'role_id' => Role::where('slug', $roleSlug)->firstOrFail()->id,
            'employee_id' => $employee->id,
            'is_active' => true,
        ]);
    }

    private function setTechnicianAvailability(User $technician, string $start, string $end, ?string $attendance): void
    {
        $shift = Shift::create([
            'name' => 'Reconciliation shift '.Str::upper(Str::random(6)),
            'start_time' => $start,
            'end_time' => $end,
            'is_active' => true,
        ]);
        EmployeeShiftAssignment::create([
            'employee_id' => $technician->employee_id,
            'shift_id' => $shift->id,
            'effective_date' => now()->toDateString(),
            'working_days' => [1, 2, 3, 4, 5, 6, 7],
        ]);
        if ($attendance !== null) {
            Attendance::create([
                'employee_id' => $technician->employee_id,
                'shift_id' => $shift->id,
                'date' => now()->toDateString(),
                'status' => $attendance,
                'time_in' => $attendance === 'present' ? now()->subHour() : null,
            ]);
        }
    }

    private function issue(MaintenanceWorkOrder $order, User $technician, Item $item, WarehouseLocation $location,
        $workLog, string $quantity, ?string $operationKey = null): SparePartUsage
    {
         $usage = app(SparePartUsageService::class)->record($order, [
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => $quantity,
            'work_record_id' => $workLog->id,
            'operation_key' => $operationKey,
        ], $technician);
        foreach (SparePartUsage::where('work_order_id', $order->id)->whereDoesntHave('stockMovement.handover')->get() as $issued) {
            \App\Modules\Inventory\Models\MaterialHandover::create(['stock_movement_id' => $issued->stock_movement_id,
                'received_by_employee_id' => $technician->employee_id, 'recipient_name' => $technician->name,
                'recorded_by' => $technician->id, 'quantity' => $issued->quantity, 'handed_over_at' => now()]);
        }
        return $usage;
    }

    private function recordPass(MaintenanceWorkOrder $order, User $technician): void
    {
        $order->logs()->create([
            'description' => 'Passing work result.',
            'finding' => 'Fault corrected',
            'action_taken' => 'Tested machine under load',
            'result' => 'passed',
            'logged_by' => $technician->id,
        ]);
    }

    private function url(MaintenanceWorkOrder $order): string
    {
        return "/api/v1/maintenance/work-orders/{$order->hash_id}";
    }
}
