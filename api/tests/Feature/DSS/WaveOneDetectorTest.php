<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Assets\Models\Asset;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\DSS\Detectors\ApprovalBottleneckDetector;
use App\Modules\DSS\Detectors\ChainBottleneckDetector;
use App\Modules\DSS\Detectors\NcrSlaDetector;
use App\Modules\DSS\Detectors\PayrollAnomalySummaryDetector;
use App\Modules\DSS\Detectors\PreventiveMaintenanceDetector;
use App\Modules\DSS\Detectors\ReceivablesPriorityDetector;
use App\Modules\DSS\Detectors\TrainingExpirySummaryDetector;
use App\Modules\DSS\Detectors\WorkOrderInterventionDetector;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeTraining;
use App\Modules\HR\Models\Training;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\MRP\Models\Machine;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WaveOneDetectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_chain_bottlenecks_reuse_existing_rules_and_keep_owner_scope_per_emission(): void
    {
        app(SettingsService::class)->set('dashboard.chain_bottlenecks', [
            'invoice_draft_overdue' => ['label' => 'Invoice draft awaiting finalization', 'hours' => 24, 'audience' => 'finance_officer'],
        ]);
        $invoice = Invoice::factory()->create(['status' => 'draft', 'updated_at' => now()->subDays(2)]);

        $scan = app(ChainBottleneckDetector::class)->detect();

        $this->assertTrue($scan->complete);
        $this->assertCount(1, $scan->emissions);
        $this->assertSame(['accounting.invoices.view'], $scan->emissions[0]->ownerPermissions);
        $this->assertSame(['finance_officer'], $scan->emissions[0]->ownerRoleSlugs);
        $this->assertSame($invoice->invoice_number, $scan->emissions[0]->rationale['sample_documents'][0]['document']);
        $this->assertArrayNotHasKey('entity_id', $scan->emissions[0]->rationale['sample_documents'][0]);
        $this->assertArrayNotHasKey('source_reference', $scan->emissions[0]->rationale['sample_documents'][0]);
        $this->assertSame($invoice->hash_id, $scan->emissions[0]->sourceReferences[0]['public_id']);
        $this->assertSame($invoice->invoice_number, $scan->emissions[0]->sourceReferences[0]['display_reference']);
        $this->assertSame('draft', $invoice->fresh()->status->value);
    }

    public function test_approval_bottlenecks_aggregate_only_current_overdue_steps(): void
    {
        app(SettingsService::class)->set('approvals.reminder_hours', 1);
        $payrollOldest = now()->startOfSecond()->subHours(5);
        $payrollOne = PayrollPeriod::factory()->create();
        $payrollTwo = PayrollPeriod::factory()->create();
        $payrollThree = PayrollPeriod::factory()->create();
        $payrollFour = PayrollPeriod::factory()->create();
        $supplierReturn = ReturnRequest::query()->create([
            'rma_number' => 'RMA-DSS-001',
            'type' => 'supplier_return',
            'status' => 'pending_approval',
        ]);
        $assetId = DB::table('assets')->insertGetId([
            'asset_code' => 'ASSET-DSS-001',
            'name' => 'DSS source row',
            'category' => 'equipment',
            'acquisition_date' => today()->subYear()->toDateString(),
            'acquisition_cost' => '1000.00',
            'useful_life_years' => 5,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => now(),
        ]);
        DB::table('approval_records')->insert([
            ['approvable_type' => PayrollPeriod::class, 'approvable_id' => $payrollOne->id, 'step_order' => 1, 'role_slug' => 'finance_officer', 'action' => 'pending', 'is_current' => true, 'created_at' => $payrollOldest],
            ['approvable_type' => PayrollPeriod::class, 'approvable_id' => $payrollTwo->id, 'step_order' => 1, 'role_slug' => 'finance_officer', 'action' => 'pending', 'is_current' => true, 'created_at' => now()->subHours(3)],
            ['approvable_type' => PayrollPeriod::class, 'approvable_id' => 999, 'step_order' => 1, 'role_slug' => 'finance_officer', 'action' => 'pending', 'is_current' => true, 'created_at' => now()->subDays(2)],
            ['approvable_type' => PayrollPeriod::class, 'approvable_id' => $payrollThree->id, 'step_order' => 1, 'role_slug' => 'finance_officer', 'action' => 'pending', 'is_current' => false, 'created_at' => now()->subDays(2)],
            ['approvable_type' => PayrollPeriod::class, 'approvable_id' => $payrollFour->id, 'step_order' => 1, 'role_slug' => 'hr_officer', 'action' => 'pending', 'is_current' => true, 'created_at' => now()->subHours(2)],
            ['approvable_type' => ReturnRequest::class, 'approvable_id' => $supplierReturn->id, 'step_order' => 1, 'role_slug' => 'customer_service_officer', 'action' => 'pending', 'is_current' => true, 'created_at' => now()->subHours(3)],
            ['approvable_type' => Asset::class, 'approvable_id' => $assetId, 'step_order' => 1, 'role_slug' => 'finance_officer', 'action' => 'pending', 'is_current' => true, 'created_at' => now()->subHours(4)],
            ['approvable_type' => Asset::class, 'approvable_id' => 1002, 'step_order' => 1, 'role_slug' => 'finance_officer', 'action' => 'pending', 'is_current' => true, 'created_at' => now()->subDays(2)],
            ['approvable_type' => LeaveRequest::class, 'approvable_id' => 1003, 'step_order' => 1, 'role_slug' => 'finance_officer', 'action' => 'pending', 'is_current' => true, 'created_at' => now()->subDays(2)],
        ]);

        $scan = app(ApprovalBottleneckDetector::class)->detect();

        $this->assertCount(3, $scan->emissions);
        $byQueueRole = collect($scan->emissions)->keyBy(fn ($emission) => $emission->rationale['queue'].'|'.$emission->rationale['role']);
        $payroll = $byQueueRole->get('Payroll|finance_officer');
        $payrollHr = $byQueueRole->get('Payroll|hr_officer');
        $asset = $byQueueRole->get('Asset disposals|finance_officer');
        $this->assertNotNull($payroll);
        $this->assertNotNull($payrollHr);
        $this->assertNotNull($asset);
        $this->assertSame(2, $payroll->rationale['waiting_count']);
        $this->assertSame(1, $payrollHr->rationale['waiting_count']);
        $this->assertSame(['payroll.periods.view', 'payroll.periods.approve'], $payroll->ownerPermissions);
        $this->assertSame(['finance_officer'], $payroll->ownerRoleSlugs);
        $this->assertSame('approval:payroll:role:finance_officer', $payroll->dedupeKey);
        $this->assertSame('approval:payroll:role:hr_officer', $payrollHr->dedupeKey);
        $this->assertSame('/payroll/periods/', $payroll->action['route']);
        $this->assertEquals($payrollOldest->copy()->addHour(), $payroll->businessDueAt);
        $this->assertSame(['assets.view', 'assets.dispose.approve'], $asset->ownerPermissions);
        $this->assertSame(1, $asset->rationale['waiting_count']);
        $this->assertSame('approval:asset_disposal:role:finance_officer', $asset->dedupeKey);
        $this->assertSame('/assets/', $asset->action['route']);
        $this->assertSame('partial', $payroll->dataSufficiency);
        $this->assertSame(['Leave', 'Purchase requests', 'Purchase orders', 'Loans', 'Return requests'], $payroll->rationale['excluded_queues']);
        $this->assertArrayNotHasKey('approvable_id', $payroll->rationale);
        $this->assertArrayNotHasKey('approver_id', $payroll->rationale);
    }

    public function test_ncr_sla_uses_escalation_clock_and_does_not_mutate_quality_record(): void
    {
        $ncr = NonConformanceReport::factory()->create([
            'severity' => 'critical',
            'status' => 'open',
            'created_at' => now()->subHours(10),
            'last_escalated_at' => now()->subHours(8),
        ]);

        $scan = app(NcrSlaDetector::class)->detect();

        $this->assertCount(1, $scan->emissions);
        $this->assertSame('critical', $scan->emissions[0]->severity);
        $this->assertSame($ncr->id, $scan->emissions[0]->subjectId);
        $this->assertSame($ncr->hash_id, $scan->emissions[0]->sourceReferences[0]['public_id']);
        $this->assertSame($ncr->ncr_number, $scan->emissions[0]->sourceReferences[0]['display_reference']);
        $this->assertEquals($ncr->last_escalated_at, $scan->emissions[0]->sourceObservedAt);
        $this->assertSame($ncr->id, $scan->emissions[0]->verificationContext['source_id']);
        $this->assertSame('critical', $scan->emissions[0]->verificationContext['severity']);
        $this->assertGreaterThan(0, $scan->emissions[0]->verificationContext['sla_hours']);
        $this->assertSame('COALESCE(last_escalated_at, created_at)', $scan->emissions[0]->verificationContext['source_clock_rule']);
        $this->assertEquals(
            $ncr->last_escalated_at->copy()->addHours($scan->emissions[0]->verificationContext['sla_hours']),
            $scan->emissions[0]->businessDueAt,
        );
        $this->assertSame('open', $ncr->fresh()->status->value);
    }

    public function test_ncr_sla_marks_limited_severity_scan_incomplete(): void
    {
        app(SettingsService::class)->set('quality.ncr.sla_low_hours', 1);
        NonConformanceReport::factory()->create([
            'severity' => 'critical',
            'status' => 'open',
            'created_at' => now()->subDays(3),
        ]);
        NonConformanceReport::factory()->create([
            'severity' => 'low',
            'status' => 'open',
            'created_at' => now()->subDays(3),
        ]);

        $scan = app(NcrSlaDetector::class)->detect(['limit' => 1]);

        $this->assertCount(1, $scan->emissions);
        $this->assertFalse($scan->complete);
    }

    public function test_ncr_sla_marks_exact_limit_scan_complete_when_no_more_severities_match(): void
    {
        NonConformanceReport::factory()->create([
            'severity' => 'critical',
            'status' => 'open',
            'created_at' => now()->subDays(3),
        ]);

        $scan = app(NcrSlaDetector::class)->detect(['limit' => 1]);

        $this->assertCount(1, $scan->emissions);
        $this->assertTrue($scan->complete);
    }

    public function test_receivables_priority_aggregates_overdue_balance_by_customer(): void
    {
        app(SettingsService::class)->set('accounting.ar_dunning.tier_days_csv', '7,14,30');
        $customer = Customer::factory()->create(['name' => 'Northwind Manufacturing']);
        $overdueInvoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'finalized',
            'balance' => '1250.00',
            'due_date' => today()->subDays(12),
        ]);
        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'paid',
            'balance' => '0.00',
            'due_date' => today()->subDays(40),
        ]);

        $scan = app(ReceivablesPriorityDetector::class)->detect();

        $this->assertCount(1, $scan->emissions);
        $this->assertSame('Northwind Manufacturing', $scan->emissions[0]->rationale['customer']);
        $this->assertSame(1, $scan->emissions[0]->rationale['overdue_invoice_count']);
        $this->assertSame(['accounting.invoices.collect'], $scan->emissions[0]->ownerPermissions);
        $this->assertSame('PHP', $scan->emissions[0]->impactUnit);
        $this->assertSame('PHP', $scan->emissions[0]->impactCurrency);
        $this->assertSame('Overdue receivable balance', $scan->emissions[0]->impactMetric);
        $this->assertEquals(
            Carbon::parse($overdueInvoice->getRawOriginal('due_date'))->addDays(7),
            $scan->emissions[0]->businessDueAt,
        );
    }

    public function test_payroll_summary_contains_period_totals_without_employee_details(): void
    {
        $period = PayrollPeriod::factory()->create();
        $period->forceFill(['status' => 'computed'])->save();
        $payroll = Payroll::factory()->create(['payroll_period_id' => $period->id]);
        DB::table('payroll_anomaly_flags')->insert([
            'payroll_id' => $payroll->id,
            'payroll_period_id' => $period->id,
            'employee_id' => $payroll->employee_id,
            'flag_type' => 'zero_pay',
            'details' => json_encode(['employee_name' => 'must not leak']),
            'is_resolved' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scan = app(PayrollAnomalySummaryDetector::class)->detect();

        $this->assertCount(1, $scan->emissions);
        $this->assertSame(1, $scan->emissions[0]->rationale['unresolved_flags']);
        $this->assertArrayNotHasKey('employee_name', $scan->emissions[0]->rationale);
        $this->assertSame(['payroll.periods.view'], $scan->emissions[0]->ownerPermissions);
    }

    public function test_overdue_work_order_emits_intervention_link(): void
    {
        $product = Product::factory()->create(['unit_of_measure' => 'kg']);
        $salesOrder = SalesOrder::factory()->create();
        $salesOrderItem = SalesOrderItem::factory()->create([
            'sales_order_id' => $salesOrder->id,
            'product_id' => $product->id,
        ]);
        $workOrder = WorkOrder::factory()->create([
            'status' => 'in_progress',
            'planned_end' => now()->subDays(3),
            'product_id' => $product->id,
            'sales_order_id' => $salesOrder->id,
            'sales_order_item_id' => $salesOrderItem->id,
            'quantity_target' => 1000,
            'quantity_good' => 300,
        ]);

        $scan = app(WorkOrderInterventionDetector::class)->detect();

        $this->assertCount(1, $scan->emissions);
        $this->assertSame($workOrder->id, $scan->emissions[0]->subjectId);
        $this->assertSame('warning', $scan->emissions[0]->severity);
        $this->assertSame('/production/work-orders/'.$workOrder->hash_id, $scan->emissions[0]->action['route']);
        $this->assertSame($workOrder->hash_id, $scan->emissions[0]->sourceReferences[0]['public_id']);
        $this->assertSame($workOrder->wo_number, $scan->emissions[0]->sourceReferences[0]['display_reference']);
        $this->assertSame([
            'source_kind' => 'sales_order',
            'public_id' => $salesOrder->hash_id,
            'display_reference' => $salesOrder->so_number.' · '.$product->part_number,
            'destination' => '/crm/sales-orders/'.$salesOrder->hash_id,
        ], $scan->emissions[0]->sourceReferences[1]);
        $this->assertSame('700', $scan->emissions[0]->rationale['remaining_quantity']);
        $this->assertSame('kg', $scan->emissions[0]->rationale['unit_of_measure']);
        $this->assertEquals($workOrder->planned_end, $scan->emissions[0]->businessDueAt);
        $this->assertSame($workOrder->id, $scan->emissions[0]->verificationContext['source_id']);
        $this->assertSame($workOrder->planned_end->toIso8601String(), $scan->emissions[0]->verificationContext['planned_end']);
    }

    public function test_overdue_work_order_omits_mismatched_sales_order_line(): void
    {
        $salesOrder = SalesOrder::factory()->create();
        $otherOrder = SalesOrder::factory()->create();
        $salesOrderItem = SalesOrderItem::factory()->create(['sales_order_id' => $otherOrder->id]);
        WorkOrder::factory()->create([
            'status' => 'in_progress',
            'planned_end' => now()->subDays(3),
            'sales_order_id' => $salesOrder->id,
            'sales_order_item_id' => $salesOrderItem->id,
        ]);

        $scan = app(WorkOrderInterventionDetector::class)->detect();

        $this->assertCount(1, $scan->emissions);
        $this->assertCount(1, $scan->emissions[0]->sourceReferences);
        $this->assertSame('work_order', $scan->emissions[0]->sourceReferences[0]['source_kind']);
    }

    public function test_preventive_maintenance_summary_matches_open_overdue_queue(): void
    {
        $machine = Machine::factory()->create();
        $user = User::factory()->create();
        $schedule = MaintenanceSchedule::query()->create([
            'maintainable_type' => 'machine',
            'maintainable_id' => $machine->id,
            'description' => 'Monthly inspection',
            'interval_type' => 'days',
            'interval_value' => 30,
            'next_due_at' => now()->subDays(8),
            'is_active' => true,
        ]);
        DB::table('maintenance_work_orders')->insert([
            'mwo_number' => 'MWO-DSS-0001',
            'maintainable_type' => 'machine',
            'maintainable_id' => $machine->id,
            'schedule_id' => $schedule->id,
            'type' => 'preventive',
            'priority' => 'medium',
            'description' => 'Monthly inspection',
            'status' => 'open',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('maintenance_work_orders')->insert([
            'mwo_number' => 'MWO-DSS-0002',
            'maintainable_type' => 'machine',
            'maintainable_id' => $machine->id,
            'schedule_id' => $schedule->id,
            'type' => 'corrective',
            'priority' => 'medium',
            'description' => 'Repair',
            'status' => 'open',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scan = app(PreventiveMaintenanceDetector::class)->detect();

        $this->assertTrue($scan->complete);
        $this->assertCount(1, $scan->emissions);
        $this->assertSame(1, $scan->emissions[0]->rationale['overdue_count']);
        $this->assertSame(['maintenance_supervisor'], $scan->emissions[0]->ownerRoleSlugs);
        $this->assertEquals($schedule->next_due_at, $scan->emissions[0]->businessDueAt);
    }

    public function test_training_expiry_aggregates_current_certificates_for_hr_only(): void
    {
        app(SettingsService::class)->set('hr.training_expiry.t30_days', 45);
        $employee = Employee::factory()->create();
        $training = Training::create(['name' => 'Forklift safety', 'validity_months' => 12, 'is_active' => true]);
        $record = EmployeeTraining::create([
            'employee_id' => $employee->id,
            'training_id' => $training->id,
            'scheduled_for' => today()->subYear()->toDateString(),
        ]);
        $record->forceFill([
            'status' => 'completed',
            'completed_at' => today()->subYear()->toDateString(),
            'expires_at' => today()->addDays(40)->toDateString(),
        ])->save();

        $scan = app(TrainingExpirySummaryDetector::class)->detect();

        $this->assertCount(1, $scan->emissions);
        $this->assertSame(1, $scan->emissions[0]->rationale['affected_records']);
        $this->assertSame(45, $scan->emissions[0]->rationale['horizon_days']);
        $this->assertSame(['hr.employees.trainings.manage'], $scan->emissions[0]->ownerPermissions);
        $this->assertEquals(
            Carbon::parse($record->getRawOriginal('expires_at')),
            $scan->emissions[0]->businessDueAt,
        );
    }
}
