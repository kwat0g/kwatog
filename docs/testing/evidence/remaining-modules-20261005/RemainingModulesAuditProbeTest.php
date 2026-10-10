<?php

declare(strict_types=1);

namespace Tests\Audit;

use App\Modules\Accounting\Models\{Account, CreditNote, Customer, Invoice, JournalEntry};
use App\Modules\Accounting\Services\CreditNoteService;
use App\Modules\Accounting\Services\Statements\BalanceSheetService;
use App\Modules\Assets\Models\{Asset, AssetDepreciation};
use App\Modules\Assets\Services\DepreciationService;
use App\Modules\Auth\Models\{Role, User};
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\{Item, StockLevel, WarehouseLocation};
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Maintenance\Models\{MaintenanceSchedule, MaintenanceWorkOrder};
use App\Modules\Maintenance\Services\{MaintenanceScheduleService, MaintenanceWorkOrderService, SparePartUsageService};
use App\Modules\MRP\Models\Machine;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\SupplyChain\Models\{Container, Shipment};
use Database\Seeders\{ChartOfAccountsSeeder, RolePermissionSeeder, SettingsSeeder};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, Event};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Audit probes assert observed defects, not desired behavior or acceptance of fixes. */
class RemainingModulesAuditProbeTest extends TestCase
{
    use DatabaseTransactions;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('ogami_test_pricing_20261004', config('database.connections.pgsql.database'));
        Event::fake();
        $this->seed([RolePermissionSeeder::class, ChartOfAccountsSeeder::class, SettingsSeeder::class]);
        $this->actor = User::factory()->create(['role_id' => Role::where('slug', 'system_admin')->value('id')]);
        $this->actingAs($this->actor);
        Carbon::setTestNow('2026-10-05 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function schedule(): MaintenanceSchedule
    {
        return app(MaintenanceScheduleService::class)->create([
            'maintainable_type' => 'machine', 'maintainable_id' => Machine::factory()->create()->id,
            'description' => 'Audit PM', 'interval_type' => 'days', 'interval_value' => 30,
        ]);
    }

    private function workOrder(?Machine $machine = null): MaintenanceWorkOrder
    {
        return MaintenanceWorkOrder::create([
            'mwo_number' => 'AUD-'.uniqid(), 'maintainable_type' => 'machine',
            'maintainable_id' => ($machine ?? Machine::factory()->create())->id,
            'type' => 'corrective', 'priority' => 'high', 'description' => 'Audit repair',
            'status' => 'open', 'cost' => '0', 'created_by' => $this->actor->id,
        ]);
    }

    public function test_description_edit_postpones_unperformed_calendar_maintenance(): void
    {
        $schedule = $this->schedule();
        self::assertSame('2026-11-04', $schedule->next_due_at->toDateString());
        Carbon::setTestNow('2026-10-06 12:00:00');
        app(MaintenanceScheduleService::class)->update($schedule, ['description' => 'Spelling correction']);
        self::assertSame('2026-11-05', $schedule->fresh()->next_due_at->toDateString());
    }

    public function test_stale_description_probe_does_not_reproduce_due_date_regression(): void
    {
        $schedule = $this->schedule();
        $schedule->forceFill(['last_performed_at' => '2026-09-01', 'next_due_at' => '2026-10-01'])->save();
        $stale = $schedule->fresh();
        $service = app(MaintenanceScheduleService::class);
        $service->recomputeNextDueAt($schedule, Carbon::parse('2026-10-10 12:00:00'));
        $service->update($stale, ['description' => 'Edited from old form']);
        $fresh = $schedule->fresh();
        self::assertSame('2026-10-10', $fresh->last_performed_at->toDateString());
        self::assertSame('2026-11-09', $fresh->next_due_at->toDateString());
    }

    public function test_disabled_schedule_still_materializes_from_stale_due_hint(): void
    {
        $schedule = $this->schedule();
        $schedule->forceFill(['is_active' => false])->save();
        $order = app(MaintenanceWorkOrderService::class)->create(['priority' => 'high'], $this->actor, $schedule);
        self::assertSame($schedule->id, $order->schedule_id);
        self::assertFalse($schedule->fresh()->is_active);
    }

    public function test_assignment_accepts_any_active_employee_and_cannot_be_replaced(): void
    {
        $employee = Employee::factory()->create(['status' => 'active']);
        $order = $this->workOrder();
        $service = app(MaintenanceWorkOrderService::class);
        $service->assign($order, $employee->id, $this->actor);
        self::assertSame($employee->id, $order->fresh()->assigned_to);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cannot transition from Assigned to Assigned');
        $service->assign($order, Employee::factory()->create()->id, $this->actor);
    }

    public function test_cancel_of_unrepaired_down_machine_marks_it_idle(): void
    {
        $machine = Machine::factory()->create(['status' => 'breakdown', 'current_work_order_id' => null]);
        $order = $this->workOrder($machine);
        $service = app(MaintenanceWorkOrderService::class);
        $service->start($order, $this->actor);
        $service->cancel($order, 'No spare part; repair not performed', $this->actor);
        self::assertSame('idle', $machine->fresh()->status->value);
    }

    private function sparePartFixture(bool $lots = false): array
    {
        $item = Item::factory()->create(['item_type' => 'spare_part', 'unit_of_measure' => 'pcs']);
        $bin = WarehouseLocation::factory()->create();
        if ($lots) {
            foreach (['A', 'B'] as $lot) {
                app(StockMovementService::class)->move(new StockMovementInput(
                    type: StockMovementType::AdjustmentIn, itemId: $item->id,
                    quantity: '5', toLocationId: $bin->id, unitCost: '5', lotNumber: $lot,
                ));
            }
        } else {
            StockLevel::create(['item_id' => $item->id, 'location_id' => $bin->id, 'quantity' => '10', 'weighted_avg_cost' => '5']);
        }
        $order = $this->workOrder();
        $order->forceFill(['status' => 'in_progress'])->save();
        return [$item, $bin, $order];
    }

    public function test_repeated_spare_part_intent_deducts_twice(): void
    {
        [$item, $bin, $order] = $this->sparePartFixture();
        $data = ['item_id' => $item->id, 'location_id' => $bin->id, 'quantity' => '2'];
        $service = app(SparePartUsageService::class);
        $service->record($order, $data, $this->actor);
        $service->record($order, $data, $this->actor);
        self::assertSame('6.000', StockLevel::where('item_id', $item->id)->first()->quantity);
        self::assertSame(2, $order->spareParts()->count());
    }

    public function test_spare_part_issue_cannot_span_two_usable_lots(): void
    {
        [$item, $bin, $order] = $this->sparePartFixture(true);
        $this->expectException(\App\Modules\Inventory\Exceptions\InsufficientStockException::class);
        app(SparePartUsageService::class)->record($order, ['item_id' => $item->id, 'location_id' => $bin->id, 'quantity' => '8'], $this->actor);
    }

    private function journal(array $lines): JournalEntry
    {
        $total = array_reduce($lines, static fn ($sum, $line) => bcadd($sum, $line[1], 2), '0');
        $journal = JournalEntry::create(['entry_number' => 'AUD-'.uniqid(), 'date' => '2026-10-01', 'description' => 'Audit ledger', 'status' => 'draft', 'total_debit' => $total, 'total_credit' => $total, 'posted_by' => $this->actor->id]);
        foreach ($lines as $i => [$code, $debit, $credit]) {
            $journal->lines()->create(['account_id' => Account::where('code', $code)->value('id'), 'line_no' => $i + 1, 'debit' => $debit, 'credit' => $credit]);
        }
        $journal->forceFill(['status' => 'posted'])->save();
        return $journal;
    }

    public function test_contra_asset_increases_reported_assets(): void
    {
        $this->journal([['1400', '1000', '0'], ['3010', '0', '1000'], ['6080', '100', '0'], ['1410', '0', '100']]);
        $statement = app(BalanceSheetService::class)->generate(Carbon::parse('2026-10-05'));
        self::assertSame('1100.00', $statement['total_assets']);
        self::assertSame('900.00', $statement['total_liabilities_equity']);
        self::assertFalse($statement['balanced']);
    }

    public function test_partial_credit_intent_replay_reduces_both_balances_twice(): void
    {
        $customer = Customer::factory()->create();
        $journal = $this->journal([['1020', '1000', '0'], ['3010', '0', '1000']]);
        $invoice = Invoice::create(['invoice_number' => 'AUD-'.uniqid(), 'customer_id' => $customer->id,
            'status' => 'finalized', 'date' => '2026-10-01', 'due_date' => '2026-11-01',
            'subtotal' => '1000', 'vat_amount' => '0', 'total_amount' => '1000', 'amount_paid' => '0', 'balance' => '1000', 'journal_entry_id' => $journal->id, 'created_by' => $this->actor->id]);
        $credit = CreditNote::create(['type' => 'customer', 'customer_id' => $customer->id, 'date' => '2026-10-01',
            'subtotal' => '1000', 'vat_amount' => '0', 'total_amount' => '1000', 'applied_amount' => '0', 'balance' => '1000', 'created_by' => $this->actor->id]);
        $credit->forceFill(['status' => 'finalized'])->save();
        $service = app(CreditNoteService::class);
        $data = ['invoice_id' => $invoice->id, 'amount' => '100'];
        $service->apply($credit, $data, $this->actor);
        $service->apply($credit, $data, $this->actor);
        self::assertSame('800.00', $invoice->fresh()->balance);
        self::assertSame('800.00', $credit->fresh()->balance);
        self::assertSame(2, $credit->applications()->count());
    }

    public function test_scientific_asset_money_causes_server_error(): void
    {
        $this->postJson('/api/v1/assets', ['name' => 'Invalid money audit', 'category' => 'equipment',
            'acquisition_date' => '2026-10-01', 'acquisition_cost' => '1e3', 'salvage_value' => '1', 'useful_life_years' => 5])->assertStatus(500);
    }

    public function test_backdated_asset_catch_up_rewinds_balance_but_keeps_later_rows(): void
    {
        $asset = Asset::create(['asset_code' => 'AUD-'.uniqid(), 'name' => 'Audit asset', 'category' => 'equipment',
            'acquisition_date' => '2026-01-01', 'acquisition_cost' => '12000', 'useful_life_years' => 5,
            'salvage_value' => '0', 'status' => 'active', 'accumulated_depreciation' => '1800']);
        $journal = $this->journal([['6080', '1800', '0'], ['1410', '0', '1800']]);
        for ($month = 1; $month <= 9; $month++) {
            AssetDepreciation::create(['asset_id' => $asset->id, 'period_year' => 2026, 'period_month' => $month,
                'depreciation_amount' => '200', 'accumulated_after' => (string) ($month * 200), 'journal_entry_id' => $journal->id, 'created_at' => now()]);
        }
        app(DepreciationService::class)->catchUpThrough($asset, \Carbon\CarbonImmutable::parse('2026-03-01'), $this->actor);
        self::assertSame('600.00', $asset->fresh()->accumulated_depreciation);
        self::assertSame(9, $asset->depreciations()->count());
    }

    public function test_null_container_identifier_passes_request_then_fails_database(): void
    {
        $shipment = Shipment::create(['shipment_number' => 'AUD-'.uniqid(), 'purchase_order_id' => PurchaseOrder::factory()->create()->id, 'status' => 'ordered', 'created_by' => $this->actor->id]);
        $container = Container::create(['shipment_id' => $shipment->id, 'container_number' => 'AUD-CONTAINER']);
        $this->putJson('/api/v1/supply-chain/containers/'.$container->hash_id, ['container_number' => null])->assertStatus(500);
    }

    public function test_net_container_weight_can_exceed_gross_weight(): void
    {
        $shipment = Shipment::create(['shipment_number' => 'AUD-'.uniqid(), 'purchase_order_id' => PurchaseOrder::factory()->create()->id, 'status' => 'ordered', 'created_by' => $this->actor->id]);
        $this->postJson('/api/v1/supply-chain/shipments/'.$shipment->hash_id.'/containers', [
            'container_number' => 'AUD-WEIGHT', 'gross_weight_kg' => '10', 'net_weight_kg' => '20',
        ])->assertSuccessful()->assertJsonPath('data.net_weight_kg', 20);
    }

    public function test_partial_shipment_documents_use_entire_purchase_order(): void
    {
        $po = PurchaseOrder::factory()->create();
        $item = Item::factory()->create();
        $line = $po->items()->create(['item_id' => $item->id, 'description' => 'Order 100, receive 10', 'quantity' => '100', 'unit' => 'pcs', 'unit_price' => '5', 'total' => '500']);
        $shipment = Shipment::create(['shipment_number' => 'AUD-'.uniqid(), 'purchase_order_id' => $po->id, 'status' => 'received', 'created_by' => $this->actor->id]);
        $grn = \App\Modules\Inventory\Models\GoodsReceiptNote::factory()->create(['purchase_order_id' => $po->id, 'vendor_id' => $po->vendor_id, 'shipment_id' => $shipment->id]);
        $grn->items()->create(['purchase_order_item_id' => $line->id, 'item_id' => $item->id, 'quantity_received' => '10', 'unit_cost' => '5']);
        $renderer = \Mockery::mock(\App\Common\Services\Pdf\PdfRenderService::class);
        $renderer->shouldReceive('render')->twice()->withArgs(function ($view, $data, $options): bool {
            self::assertSame('100.000', bcadd((string) $data['items']->first()->quantity, '0', 3));
            return true;
        })->andReturn('%PDF audit');
        $vault = \Mockery::mock(\App\Common\Services\DocumentVaultService::class);
        $document = new \App\Common\Models\Document;
        $vault->shouldReceive('store')->twice()->andReturn($document);
        $vault->shouldReceive('streamInline')->twice()->andReturn(new \Symfony\Component\HttpFoundation\StreamedResponse);
        $service = new \App\Modules\SupplyChain\Services\ImpexDocumentService($renderer, $vault);
        $service->generatePackingList($shipment);
        $service->generateCommercialInvoice($shipment);
    }

    public function test_deactivated_export_owner_still_passes_background_permission_check(): void
    {
        $role = Role::create(['name' => 'Audit ordinary exporter', 'slug' => 'audit_exporter']);
        $role->permissions()->sync(\App\Modules\Auth\Models\Permission::where('slug', 'hr.employees.export')->pluck('id'));
        $owner = User::factory()->create(['role_id' => $role->id, 'is_active' => false]);
        self::assertTrue($owner->can('hr.employees.export'));
        $exporter = app(\App\Common\Services\Export\ExportRunner::class)->build('hr.employees', ['employee_no'], [], $owner);
        self::assertInstanceOf(\App\Common\Exports\BaseModuleExport::class, $exporter);
    }

    public function test_schedule_manager_cannot_access_existing_equipment_lookup(): void
    {
        $role = Role::create(['name' => 'Audit Schedule Manager', 'slug' => 'audit_schedule_manager']);
        $role->permissions()->sync(\App\Modules\Auth\Models\Permission::whereIn('slug', ['maintenance.view', 'maintenance.schedules.manage'])->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user)->getJson('/api/v1/maintenance/schedules/options')->assertOk();
        $this->getJson('/api/v1/mrp/machines')->assertForbidden();
        $this->getJson('/api/v1/maintenance/target-options?type=machine')->assertForbidden();
    }

    public function test_full_approval_disposes_before_latest_depreciation_without_reversing_later_rows(): void
    {
        $this->seed(\Database\Seeders\WorkflowSeeder::class);
        $asset = Asset::create(['asset_code' => 'AUD-'.uniqid(), 'name' => 'Backdated disposal audit', 'category' => 'equipment',
            'acquisition_date' => '2026-01-01', 'acquisition_cost' => '12000', 'useful_life_years' => 5,
            'salvage_value' => '0', 'status' => 'active', 'accumulated_depreciation' => '1800']);
        $journal = $this->journal([['6080', '1800', '0'], ['1410', '0', '1800']]);
        for ($month = 1; $month <= 9; $month++) {
            AssetDepreciation::create(['asset_id' => $asset->id, 'period_year' => 2026, 'period_month' => $month,
                'depreciation_amount' => '200', 'accumulated_after' => (string) ($month * 200), 'journal_entry_id' => $journal->id, 'created_at' => now()]);
        }
        $service = app(\App\Modules\Assets\Services\AssetService::class);
        $requester = User::factory()->create(['role_id' => Role::where('slug', 'finance_officer')->value('id')]);
        $checker = User::factory()->create(['role_id' => $requester->role_id]);
        $executive = User::factory()->create(['role_id' => Role::where('slug', 'vice_president')->value('id')]);
        $service->requestDisposal($asset, ['disposed_date' => '2026-03-15', 'disposal_amount' => '0', 'remarks' => 'Audit backdated disposal'], $requester);
        $service->approveDisposal($asset, $checker);
        $service->approveDisposal($asset, $executive);
        self::assertSame('disposed', $asset->fresh()->status->value);
        self::assertSame('600.00', $asset->fresh()->accumulated_depreciation);
        self::assertSame(6, $asset->depreciations()->where('period_month', '>', 3)->count());
        self::assertSame('posted', $journal->fresh()->status->value);
    }

    public function test_previous_year_profit_without_closing_journal_is_omitted_from_equity(): void
    {
        $journal = JournalEntry::create(['entry_number' => 'AUD-'.uniqid(), 'date' => '2025-12-01', 'description' => 'Previous year cash sale', 'status' => 'draft', 'total_debit' => '100', 'total_credit' => '100']);
        foreach ([['1020', '100', '0'], ['4010', '0', '100']] as $index => [$code, $debit, $credit]) {
            $journal->lines()->create(['account_id' => Account::where('code', $code)->value('id'), 'line_no' => $index + 1, 'debit' => $debit, 'credit' => $credit]);
        }
        $journal->forceFill(['status' => 'posted'])->save();
        $statement = app(BalanceSheetService::class)->generate(Carbon::parse('2026-10-05'));
        self::assertSame('100.00', $statement['total_assets']);
        self::assertSame('0.00', $statement['total_liabilities_equity']);
        self::assertFalse($statement['balanced']);
    }

    public function test_stock_out_advisory_counts_blocked_location_as_available(): void
    {
        $item = Item::factory()->create();
        $bin = WarehouseLocation::factory()->create(['is_blocked' => true]);
        StockLevel::create(['item_id' => $item->id, 'location_id' => $bin->id, 'quantity' => '10', 'weighted_avg_cost' => '5']);
        $rows = app(\App\Modules\Forecasting\Services\StockOutProjectionService::class)->projectAll();
        $row = collect($rows)->firstWhere('code', $item->code);
        self::assertNotNull($row);
        self::assertSame('10.000', $row['available']);
        self::assertSame('ok', $row['risk']);
    }
}
