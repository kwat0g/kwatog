<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\Document;
use App\Common\Services\ApprovalService;
use App\Common\Services\DocumentVaultService;
use App\Common\Services\Pdf\PdfRenderService;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\CreditNoteService;
use App\Modules\Accounting\Services\Statements\BalanceSheetService;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetDepreciation;
use App\Modules\Assets\Services\AssetService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Services\MaintenanceScheduleService;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderService;
use App\Modules\Maintenance\Services\SparePartUsageService;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\SupplyChain\Models\Shipment;
use App\Modules\SupplyChain\Services\ImpexDocumentService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/** Regression checks for the approved major integrity fixes. */
class MajorIntegrityRegressionTest extends TestCase
{
    use DatabaseTransactions;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', config('app.env'));
        self::assertMatchesRegularExpression('/\Aogami_test(?:_|$)/', (string) config('database.connections.pgsql.database'));
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

    public function test_cancel_preserves_unrepaired_machine_fault(): void
    {
        $machine = Machine::factory()->create(['status' => 'breakdown', 'current_work_order_id' => null]);
        $order = $this->workOrder($machine);
        $service = app(MaintenanceWorkOrderService::class);
        $service->start($order, $this->actor);
        $service->cancel($order, 'No spare part; repair not performed', $this->actor);
        self::assertSame('breakdown', $machine->fresh()->status->value);
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

    public function test_spare_part_retry_deducts_once(): void
    {
        [$item, $bin, $order] = $this->sparePartFixture();
        $data = ['item_id' => $item->id, 'location_id' => $bin->id, 'quantity' => '2', 'operation_key' => '53c5b184-ff96-4b91-b45a-1bb351b29981'];
        $service = app(SparePartUsageService::class);
        $service->record($order, $data, $this->actor);
        $service->record($order, $data, $this->actor);
        self::assertSame('8.000', StockLevel::where('item_id', $item->id)->first()->quantity);
        self::assertSame(1, $order->spareParts()->count());
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

    public function test_contra_asset_reduces_reported_assets(): void
    {
        $this->journal([['1400', '1000', '0'], ['3010', '0', '1000'], ['6080', '100', '0'], ['1410', '0', '100']]);
        $statement = app(BalanceSheetService::class)->generate(Carbon::parse('2026-10-05'));
        self::assertSame('900.00', $statement['total_assets']);
        self::assertSame('900.00', $statement['total_liabilities_equity']);
        self::assertTrue($statement['balanced']);
    }

    public function test_partial_credit_retry_reduces_balances_once(): void
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
        $data = ['invoice_id' => $invoice->id, 'amount' => '100', 'operation_key' => '5a073d45-e80c-4ed1-b083-ea8d5586cbe9'];
        $service->apply($credit, $data, $this->actor);
        $service->apply($credit, $data, $this->actor);
        self::assertSame('900.00', $invoice->fresh()->balance);
        self::assertSame('900.00', $credit->fresh()->balance);
        self::assertSame(1, $credit->applications()->count());
    }

    public function test_partial_shipment_draft_uses_receipt_quantity(): void
    {
        $po = PurchaseOrder::factory()->create();
        $item = Item::factory()->create();
        $line = $po->items()->create(['item_id' => $item->id, 'description' => 'Order 100, receive 10', 'quantity' => '100', 'unit' => 'pcs', 'unit_price' => '5', 'total' => '500']);
        $shipment = Shipment::create(['shipment_number' => 'AUD-'.uniqid(), 'purchase_order_id' => $po->id, 'status' => 'received', 'created_by' => $this->actor->id]);
        $grn = GoodsReceiptNote::factory()->create(['purchase_order_id' => $po->id, 'vendor_id' => $po->vendor_id, 'shipment_id' => $shipment->id]);
        $grn->items()->create(['purchase_order_item_id' => $line->id, 'item_id' => $item->id, 'quantity_received' => '10', 'unit_cost' => '5']);
        $renderer = \Mockery::mock(PdfRenderService::class);
        $renderer->shouldReceive('render')->twice()->withArgs(function ($view, $data, $options): bool {
            self::assertSame('10.000', bcadd((string) $data['items']->first()->quantity, '0', 3));
            self::assertTrue($data['isDraft']);
            self::assertSame('50.00', (string) $data['items']->first()->total);
            if (isset($data['subtotal'])) {
                self::assertSame('50.00', $data['subtotal']);
            }

            return true;
        })->andReturn('%PDF audit');
        $vault = \Mockery::mock(DocumentVaultService::class);
        $document = new Document;
        $vault->shouldReceive('store')->twice()->andReturn($document);
        $vault->shouldReceive('streamInline')->twice()->andReturn(new StreamedResponse);
        $service = new ImpexDocumentService($renderer, $vault);
        $service->generatePackingList($shipment);
        $service->generateCommercialInvoice($shipment);
    }

    public function test_disposal_before_posted_depreciation_is_rejected(): void
    {
        $this->seed(WorkflowSeeder::class);
        $asset = Asset::create(['asset_code' => 'AUD-'.uniqid(), 'name' => 'Backdated disposal audit', 'category' => 'equipment',
            'acquisition_date' => '2026-01-01', 'acquisition_cost' => '12000', 'useful_life_years' => 5,
            'salvage_value' => '0', 'status' => 'active', 'accumulated_depreciation' => '1800']);
        $journal = $this->journal([['6080', '1800', '0'], ['1410', '0', '1800']]);
        for ($month = 1; $month <= 9; $month++) {
            AssetDepreciation::create(['asset_id' => $asset->id, 'period_year' => 2026, 'period_month' => $month,
                'depreciation_amount' => '200', 'accumulated_after' => (string) ($month * 200), 'journal_entry_id' => $journal->id, 'created_at' => now()]);
        }
        $service = app(AssetService::class);
        $requester = User::factory()->create(['role_id' => Role::where('slug', 'finance_officer')->value('id')]);
        $checker = User::factory()->create(['role_id' => $requester->role_id]);
        $executive = User::factory()->create(['role_id' => Role::where('slug', 'vice_president')->value('id')]);
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Disposal date precedes posted depreciation');
        $service->requestDisposal($asset, ['disposed_date' => '2026-03-15', 'disposal_amount' => '0', 'remarks' => 'Audit backdated disposal'], $requester);
    }

    public function test_spare_issue_key_cannot_be_reused_for_changed_quantity(): void
    {
        [$item, $bin, $order] = $this->sparePartFixture();
        $service = app(SparePartUsageService::class);
        $data = ['item_id' => $item->id, 'location_id' => $bin->id, 'quantity' => '2', 'operation_key' => '23b49835-0356-4086-b94e-3769dbe1fa4b'];
        $service->record($order, $data, $this->actor);
        try {
            $service->record($order, array_replace($data, ['quantity' => '3']), $this->actor);
            self::fail('Changed intent must reject.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('operation_key', $exception->errors());
        }
        self::assertSame('8.000', StockLevel::where('item_id', $item->id)->first()->quantity);
        self::assertSame(1, $order->spareParts()->count());
    }

    public function test_spare_issue_replay_does_not_require_still_open_work_order(): void
    {
        [$item, $bin, $order] = $this->sparePartFixture();
        $data = ['item_id' => $item->id, 'location_id' => $bin->id, 'quantity' => '2', 'operation_key' => 'ce93900e-93c1-4f15-b1cb-60df7b921fb4'];
        $service = app(SparePartUsageService::class);
        $first = $service->record($order, $data, $this->actor);
        $order->forceFill(['status' => 'completed'])->save();
        self::assertSame($first->id, $service->record($order, $data, $this->actor)->id);
    }

    public function test_spare_issue_http_requires_key_and_preserves_stock_for_employee(): void
    {
        [$item, $bin, $order] = $this->sparePartFixture();
        $payload = ['item_id' => $item->hash_id, 'location_id' => $bin->hash_id, 'quantity' => '2'];
        $this->postJson('/api/v1/maintenance/work-orders/'.$order->hash_id.'/spare-parts', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('operation_key');
        $employee = User::factory()->create(['role_id' => Role::where('slug', 'employee')->value('id')]);
        $this->actingAs($employee)->postJson('/api/v1/maintenance/work-orders/'.$order->hash_id.'/spare-parts',
            $payload + ['operation_key' => 'f32c06c8-b0a1-4128-bd1e-97f9069f15f0'])->assertForbidden();
        self::assertSame('10.000', StockLevel::where('item_id', $item->id)->first()->quantity);
    }

    public function test_cancel_idle_preventive_hold_restores_idle_but_legacy_state_stays_unavailable(): void
    {
        $machine = Machine::factory()->create(['status' => 'idle', 'current_work_order_id' => null]);
        $order = $this->workOrder($machine);
        $service = app(MaintenanceWorkOrderService::class);
        $service->start($order, $this->actor);
        $service->cancel($order, 'No work performed', $this->actor);
        self::assertSame('idle', $machine->fresh()->status->value);
        $legacy = $this->workOrder($machine);
        $service->start($legacy, $this->actor);
        $legacy->forceFill(['pre_maintenance_status' => null])->save();
        $service->cancel($legacy, 'Legacy repair canceled', $this->actor);
        self::assertSame('breakdown', $machine->fresh()->status->value);
    }

    public function test_credit_replay_key_payload_and_actor_are_checked(): void
    {
        $customer = Customer::factory()->create();
        $journal = $this->journal([['1020', '1000', '0'], ['3010', '0', '1000']]);
        $invoice = Invoice::create(['invoice_number' => 'CN-REPLAY-'.uniqid(), 'customer_id' => $customer->id,
            'status' => 'finalized', 'date' => '2026-10-01', 'due_date' => '2026-11-01',
            'subtotal' => '1000', 'vat_amount' => '0', 'total_amount' => '1000', 'amount_paid' => '0', 'balance' => '1000',
            'journal_entry_id' => $journal->id, 'created_by' => $this->actor->id]);
        $credit = CreditNote::create(['type' => 'customer', 'customer_id' => $customer->id, 'date' => '2026-10-01',
            'subtotal' => '1000', 'vat_amount' => '0', 'total_amount' => '1000', 'applied_amount' => '0', 'balance' => '1000', 'created_by' => $this->actor->id]);
        $credit->forceFill(['status' => 'finalized'])->save();
        $service = app(CreditNoteService::class);
        $data = ['invoice_id' => $invoice->id, 'amount' => '100', 'operation_key' => 'B42A3B70-D6D4-4E7B-B412-7E3C19760919'];
        $first = $service->apply($credit, $data, $this->actor);
        self::assertSame($first->id, $service->apply($credit, array_replace($data, ['operation_key' => strtolower($data['operation_key'])]), $this->actor)->id);
        foreach ([$data + ['bill_id' => 123], array_replace($data, ['amount' => '101'])] as $changed) {
            try {
                $service->apply($credit, $changed, $this->actor);
                self::fail('Changed intent must reject.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('operation_key', $exception->errors());
            }
        }
        try {
            $service->apply($credit, $data, User::factory()->create());
            self::fail('Another actor cannot claim replay.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('operation_key', $exception->errors());
        }
        $next = array_replace($data, ['operation_key' => '79039c65-e660-499d-b135-97b971e79b35']);
        $service->apply($credit, $next, $this->actor);
        self::assertSame('800.00', $invoice->fresh()->balance);
        self::assertSame(2, $credit->applications()->count());
    }

    public function test_final_disposal_rechecks_depreciation_posted_after_request(): void
    {
        $this->seed(WorkflowSeeder::class);
        $asset = Asset::create(['asset_code' => 'GUARD-'.uniqid(), 'name' => 'Disposal approval guard', 'category' => 'equipment',
            'acquisition_date' => '2026-01-01', 'acquisition_cost' => '12000', 'useful_life_years' => 5,
            'salvage_value' => '0', 'status' => 'active', 'accumulated_depreciation' => '0']);
        $service = app(AssetService::class);
        $requester = User::factory()->create(['role_id' => Role::where('slug', 'finance_officer')->value('id')]);
        $checker = User::factory()->create(['role_id' => $requester->role_id]);
        $executive = User::factory()->create(['role_id' => Role::where('slug', 'vice_president')->value('id')]);
        $service->requestDisposal($asset, ['disposed_date' => '2026-03-15', 'disposal_amount' => '0', 'remarks' => 'Retire asset'], $requester);
        $service->approveDisposal($asset, $checker);
        $journal = $this->journal([['6080', '200', '0'], ['1410', '0', '200']]);
        AssetDepreciation::create(['asset_id' => $asset->id, 'period_year' => 2026, 'period_month' => 4,
            'depreciation_amount' => '200', 'accumulated_after' => '200', 'journal_entry_id' => $journal->id, 'created_at' => now()]);
        try {
            $service->approveDisposal($asset, $executive);
            self::fail('Final execution must recheck new posted depreciation.');
        } catch (BusinessRuleException $exception) {
            self::assertStringContainsString('Disposal date precedes posted depreciation', $exception->getMessage());
        }
        self::assertSame('active', $asset->fresh()->status->value);
        self::assertSame('0.00', $asset->fresh()->accumulated_depreciation);
        self::assertSame('posted', $journal->fresh()->status->value);
        self::assertNotNull(app(ApprovalService::class)->nextStep($asset));
    }

    public function test_supplier_credit_retry_is_atomic_and_uses_one_application(): void
    {
        $vendor = Vendor::factory()->create();
        $journal = $this->journal([['6080', '1000', '0'], ['2010', '0', '1000']]);
        $bill = Bill::create(['bill_number' => 'BILL-REPLAY-'.uniqid(), 'vendor_id' => $vendor->id,
            'status' => 'unpaid', 'date' => '2026-10-01', 'due_date' => '2026-11-01', 'subtotal' => '1000',
            'vat_amount' => '0', 'total_amount' => '1000', 'amount_paid' => '0', 'balance' => '1000',
            'journal_entry_id' => $journal->id, 'created_by' => $this->actor->id]);
        $credit = CreditNote::create(['type' => 'supplier', 'vendor_id' => $vendor->id, 'date' => '2026-10-01',
            'subtotal' => '1000', 'vat_amount' => '0', 'total_amount' => '1000', 'applied_amount' => '0', 'balance' => '1000', 'created_by' => $this->actor->id]);
        $credit->forceFill(['status' => 'finalized'])->save();
        $data = ['bill_id' => $bill->hash_id, 'amount' => '100', 'operation_key' => 'd19c4e11-3e99-4186-a180-a7ebd407002a'];
        $finance = User::factory()->create(['role_id' => Role::where('slug', 'finance_officer')->value('id')]);
        $endpoint = '/api/v1/accounting/credit-notes/'.$credit->hash_id.'/apply';
        $this->actingAs($finance)->postJson($endpoint, $data)->assertSuccessful();
        $this->postJson($endpoint, $data)->assertSuccessful();
        self::assertSame('900.00', $bill->fresh()->balance);
        self::assertSame(1, $credit->applications()->count());
        $this->postJson($endpoint, array_replace($data, ['amount' => '101']))->assertUnprocessable()->assertJsonValidationErrors('operation_key');
    }

    public function test_cancel_preserves_open_fault_even_when_previous_machine_status_was_idle(): void
    {
        $machine = Machine::factory()->create(['status' => 'idle', 'current_work_order_id' => null]);
        $downtime = MachineDowntime::create([
            'machine_id' => $machine->id, 'category' => 'breakdown', 'start_time' => now()->subHour(), 'description' => 'Unresolved fault',
        ]);
        $order = $this->workOrder($machine);
        $service = app(MaintenanceWorkOrderService::class);
        $service->start($order, $this->actor);
        $service->cancel($order, 'Fault not repaired', $this->actor);
        self::assertSame('breakdown', $machine->fresh()->status->value);
        self::assertNull($downtime->fresh()->end_time);
        self::assertNull($downtime->fresh()->maintenance_order_id);
    }
}
