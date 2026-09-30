<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\CRM\Models\Product;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\DisbursementProof;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DashboardWidgetSeeder;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\PayrollChartAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\UomSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Business-process audit suite, walked over the real HTTP API:
 *
 *  1. Salary disbursement   — payroll period → compute → approve → finalize →
 *                             bank file → GL handoff → disbursement proof → disbursed
 *  2. Bidding               — approved PR → RFQ → portal quotes → sealed close →
 *                             comparison → award → draft PO
 *  3. B2B transaction       — sent PO → supplier acknowledge → shipment →
 *                             accepted receipt → supplier invoice (live bill)
 *  4. Warehouse management  — warehouse/zone/location map → GRN receive-with-QC →
 *                             stock at WAC → material issue → transfer → stock count
 *  5. Return policy         — customer-return RMA: draft → submit → approve chain →
 *                             receive (quarantine) → inspect (Quality) → disposition →
 *                             credit note + complete
 *  6. Forecasting           — manual override, MRP projection gated by product opt-in,
 *                             accuracy summary, stock-out projection, and the
 *                             forecast.* dashboard widgets for every holding role
 *
 * Adversarial probes ride along: wrong actor at each step, wrong-time
 * transitions, sealed-bid leaks, cross-vendor tenancy, and the
 * terminal-only rules.
 */
class BusinessProcessAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $hr;
    private User $finance;
    private User $vp;
    private User $buyer;
    private User $warehouse;
    private User $qc;
    private User $deptHead;
    private User $prodManager;
    private User $csOfficer;
    private User $ppc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(UomSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(PayrollChartAccountsSeeder::class);
        $this->seed(GovernmentTableSeeder::class);
        $this->seed(WorkflowSeeder::class);
        $this->seed(DashboardWidgetSeeder::class);

        $make = fn (string $slug): User => User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'is_active' => true,
        ]);

        $this->admin = $make('system_admin');
        $this->hr = $make('hr_officer');
        $this->finance = $make('finance_officer');
        $this->vp = $make('vice_president');
        $this->buyer = $make('purchasing_officer');
        $this->warehouse = $make('warehouse_staff');
        $this->qc = $make('qc_inspector');
        $this->deptHead = $make('department_head');
        $this->prodManager = $make('production_manager');
        $this->csOfficer = $make('customer_service_officer');
        $this->ppc = $make('ppc_head');

        Storage::fake('local');

        // Chain listeners that attribute artifacts need an automation actor.
        app(SettingsService::class)->set('system.automation.actor_roles', ['system_admin']);
        User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
            'is_active' => true,
            'email' => 'automation-'.substr(uniqid(), -8).'@ogami.test',
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════
       1. SALARY DISBURSEMENT
       ══════════════════════════════════════════════════════════════════ */

    public function test_1_salary_disbursement_from_period_to_disbursed(): void
    {
        // ── Fixture: one banked employee, full cutoff ──
        $dept = Department::create(['name' => 'Payroll Audit', 'code' => 'PAB']);
        $pos = Position::create(['title' => 'Operator-'.substr(uniqid(), -5), 'department_id' => $dept->id]);
        $employee = Employee::factory()->create([
            'department_id' => $dept->id,
            'position_id' => $pos->id,
            'employment_type' => 'regular',
            'pay_type' => 'semi_monthly',
            'semi_monthly_rate' => '9460.00',
            'date_hired' => '2025-01-01',
            'status' => 'active',
            'bank_name' => 'BPI',
            'bank_account_no' => '1234567890',
        ]);

        // ── Wrong actor: HR cannot create a period they cannot compute ──
        // (hr_officer HOLDS payroll.periods.create)
        $create = $this->actingAs($this->hr)->postJson('/api/v1/payroll-periods', [
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-15',
            'payroll_date' => '2026-10-15',
        ]);
        $create->assertCreated();
        $periodId = $create->json('data.id');
        $this->assertNotEmpty($create->json('data.label'), 'period carries a human label');

        // Wrong actor: a warehouse user cannot create periods.
        $this->actingAs($this->warehouse)
            ->postJson('/api/v1/payroll-periods', [
                'period_start' => '2026-11-01',
                'period_end' => '2026-11-15',
                'payroll_date' => '2026-11-15',
            ])
            ->assertForbidden();

        // Wrong time: straddling halves is refused (derived-half contract).
        $this->actingAs($this->hr)
            ->postJson('/api/v1/payroll-periods', [
                'period_start' => '2026-12-10',
                'period_end' => '2026-12-20',
                'payroll_date' => '2026-12-20',
            ])
            ->assertStatus(422);

        // ── Compute (hr_officer holds payroll.periods.compute) ──
        $this->actingAs($this->hr)
            ->postJson("/api/v1/payroll-periods/{$periodId}/compute")
            ->assertStatus(202);
        $period = PayrollPeriod::query()->findOrFail(
            (int) app('hashids')->decode((string) $periodId)[0]
        );
        $this->assertSame(PayrollPeriodStatus::Computed->value, $period->status->value);
        $periodDbId = $period->id;

        $payrollRow = \App\Modules\Payroll\Models\Payroll::query()
            ->where('payroll_period_id', $periodDbId)
            ->where('employee_id', $employee->id)
            ->firstOrFail();
        $this->assertSame('9460.00', (string) $payrollRow->basic_pay);
        $this->assertGreaterThan(0, (float) $payrollRow->sss_ee, 'First-half cutoff withholds SSS');

        // ── Approve (finance only; hr is refused) ──
        $this->actingAs($this->hr)
            ->patchJson("/api/v1/payroll-periods/{$periodId}/approve")
            ->assertForbidden();
        $this->actingAs($this->finance)
            ->patchJson("/api/v1/payroll-periods/{$periodId}/approve")
            ->assertOk();

        // ── Finalize (finance) — triggers bank file + GL posting ──
        // Anomaly flags raised at compute must be resolved first; hr_officer
        // holds payroll.anomalies.review (the checker on the anomaly board).
        $flags = $this->actingAs($this->hr)
            ->getJson("/api/v1/payroll-periods/{$periodId}/anomalies")
            ->assertOk()
            ->json('data');
        foreach ($flags as $flag) {
            $this->actingAs($this->hr)
                ->patchJson("/api/v1/payroll-anomalies/{$flag['id']}/resolve", [
                    'remarks' => 'Reviewed: contribution basis matches the prorated salary.',
                ])
                ->assertOk();
        }

        $finalize = $this->actingAs($this->finance)
            ->patchJson("/api/v1/payroll-periods/{$periodId}/finalize");
        $finalize->assertOk();
        $period->refresh();
        $this->assertSame(PayrollPeriodStatus::Finalized->value, $period->status->value);
        $periodDbId = $period->id;

        // The finalize chain produced a bank file artifact.
        $this->assertNotNull($period->fresh()->bank_file_status ?? null, 'bank file should be generated on finalize');

        // ── Disbursement evidence: refused until a matching proof exists ──
        $this->actingAs($this->finance)
            ->patchJson("/api/v1/payroll-periods/{$period->hash_id}/mark-disbursed")
            ->assertStatus(422);

        $netPay = (string) $payrollRow->net_pay;
        $this->actingAs($this->finance)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/disbursement-proofs", [
                'proof_type' => 'deposit_slip',
                'disbursed_amount' => $netPay,
                'disbursement_date' => now()->toDateString(),
                'file' => UploadedFile::fake()->create('deposit.pdf', 30, 'application/pdf'),
            ])
            ->assertCreated();

        // Evidence totals the payable net → the disbursement completes.
        $this->actingAs($this->finance)
            ->patchJson("/api/v1/payroll-periods/{$period->hash_id}/mark-disbursed")
            ->assertOk();

        $period->refresh();
        $this->assertSame(PayrollPeriodStatus::Disbursed->value, $period->status->value);
        $this->assertSame('disbursed', (string) $period->disbursement_status);
        $this->assertNotNull($period->disbursed_at);

        // Wrong time: re-disbursing is refused.
        $this->actingAs($this->finance)
            ->patchJson("/api/v1/payroll-periods/{$period->hash_id}/mark-disbursed")
            ->assertStatus(422);

        // GL handoff posted a journal entry (posted or not-required are the
        // only states that allow disbursement, and we forced one above).
        $this->assertContains(
            $period->fresh()->gl_handoff_status?->value,
            ['posted', 'not_required'],
        );
    }

    /* ══════════════════════════════════════════════════════════════════
       2. BIDDING (RFQ)
       ══════════════════════════════════════════════════════════════════ */

    public function test_2_bidding_sealed_quotes_comparison_and_award(): void
    {
        [$pr, $item] = $this->approvedPurchaseRequest();
        [$vendorA, $portalA] = $this->supplier();
        [$vendorB, $portalB] = $this->supplier();

        // ── Wrong actor: PPC cannot run sourcing (no purchasing.rfq.manage) ──
        $this->actingAs($this->ppc)
            ->postJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/rfqs", $this->rfqBody([$vendorA]))
            ->assertForbidden();

        // ── Buyer opens an RFQ, published to both suppliers ──
        $rfq = $this->actingAs($this->buyer)
            ->postJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/rfqs", $this->rfqBody([$vendorA, $vendorB], true))
            ->assertCreated()
            ->json('data');
        $lineId = $rfq['items'][0]['id'];

        // Sealed while open: the buyer cannot see quotes before close.
        $this->actingAs($this->buyer)
            ->getJson("/api/v1/purchasing/rfqs/{$rfq['id']}")
            ->assertOk()
            ->assertJsonPath('data.quotes', []);

        // ── Suppliers quote in the portal ──
        $this->quoteThroughPortal($portalA, $rfq['id'], $lineId, '10', '50.0000', '100.00');
        $this->quoteThroughPortal($portalB, $rfq['id'], $lineId, '10', '45.0000', '200.00');

        // ── Close (now that both responded) and compare ──
        $this->asWeb($this->buyer)
            ->postJson("/api/v1/purchasing/rfqs/{$rfq['id']}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $comparison = $this->asWeb($this->buyer)
            ->getJson("/api/v1/purchasing/rfqs/{$rfq['id']}/comparison")
            ->assertOk()
            ->json('data.quotes');
        $byVendor = collect($comparison)->keyBy(fn (array $q): string => $q['vendor']['id']);
        // B's unit price is lower; freight makes A cheaper delivered.
        $this->assertTrue($byVendor[$vendorA->hash_id]['items'][0]['is_recommended']);
        $this->assertFalse($byVendor[$vendorB->hash_id]['items'][0]['is_recommended']);

        // ── Award → draft PO ──
        $award = $this->asWeb($this->buyer)
            ->postJson("/api/v1/purchasing/rfqs/{$rfq['id']}/award", [
                'award_reason' => 'Lowest delivered cost.',
                'lines' => [[
                    'request_for_quote_item_id' => $lineId,
                    'supplier_quote_item_id' => $byVendor[$vendorA->hash_id]['items'][0]['id'],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'awarded');
        $this->assertCount(1, $award->json('purchase_orders'));

        $po = PurchaseOrder::query()->where('request_for_quote_id', (int) app('hashids')->decode($rfq['id'])[0])->sole();
        $this->assertSame((int) $vendorA->id, (int) $po->vendor_id);
        $this->assertSame(PurchaseOrderStatus::Draft->value, $po->status->value);
        $this->assertSame('600.00', (string) $po->subtotal);

        // Wrong time: double award refused.
        $this->asWeb($this->buyer)
            ->postJson("/api/v1/purchasing/rfqs/{$rfq['id']}/award", [
                'award_reason' => 'Again.',
                'lines' => [[
                    'request_for_quote_item_id' => $lineId,
                    'supplier_quote_item_id' => $byVendor[$vendorA->hash_id]['items'][0]['id'],
                ]],
            ])
            ->assertStatus(422);

        // The invitation ledger records who won and who did not.
        $rfqModel = \App\Modules\Purchasing\Models\RequestForQuote::query()
            ->findOrFail((int) app('hashids')->decode($rfq['id'])[0]);
        $this->assertSame(
            'awarded',
            $rfqModel->invitations()->where('vendor_id', $vendorA->id)->first()->status->value,
        );
        $this->assertSame(
            'not_awarded',
            $rfqModel->invitations()->where('vendor_id', $vendorB->id)->first()->status->value,
        );
    }

    /* ══════════════════════════════════════════════════════════════════
       3. B2B TRANSACTION
       ══════════════════════════════════════════════════════════════════ */

    public function test_3_b2b_supplier_transaction_from_po_to_invoice(): void
    {
        [$vendor, $portal] = $this->supplier();
        $po = $this->sentPurchaseOrder($vendor, '500.00');

        // ── Cross-tenant: another supplier is refused this PO ──
        [$otherVendor, $otherPortal] = $this->supplier();
        $poHash = $po->hash_id;
        $this->actingAsPortal($otherPortal)
            ->getJson("/api/v1/b2b/supplier/purchase-orders/{$poHash}")
            ->assertStatus(403);

        // ── Supplier acknowledges the sent PO ──
        $this->actingAsPortal($portal)
            ->postJson("/api/v1/b2b/supplier/purchase-orders/{$poHash}/acknowledge", [
                'expected_delivery_date' => now()->addDays(5)->toDateString(),
                'notes' => 'Accepted as ordered.',
            ])
            ->assertOk();
        $po->refresh();
        $this->assertSame('acknowledged', $po->status->value);

        // ── Supplier ships against the accepted PO ──
        $this->actingAsPortal($portal)
            ->postJson("/api/v1/b2b/supplier/purchase-orders/{$poHash}/shipments", [
                'shipped_date' => now()->toDateString(),
                'carrier' => '2GO',
                'tracking_number' => 'TRK-'.substr(uniqid(), -6),
                'estimated_arrival' => now()->addDays(3)->toDateString(),
                'notes' => 'Partial lot 1 of 1.',
            ])
            ->assertCreated();

        // ── Warehouse receives; QC maker + checker pass → GRN accepted ──
        $receiveLocation = WarehouseLocation::factory()->create();
        $this->receiveAndAcceptPo($po, $receiveLocation);

        // ── Supplier submits an invoice; it lands as a live bill ──
        $grn = \App\Modules\Inventory\Models\GoodsReceiptNote::query()
            ->where('purchase_order_id', $po->id)
            ->where('status', GrnStatus::Accepted->value)
            ->firstOrFail();
        $grnHash = $grn->hash_id;

        $invoice = $this->actingAsPortal($portal)
            ->postJson("/api/v1/b2b/supplier/purchase-orders/{$poHash}/submit-invoice", [
                'bill_number' => 'SINV-'.substr(uniqid(), -6),
                'date' => now()->toDateString(),
                'goods_receipt_note_id' => $grnHash,
            ]);
        $invoice->assertCreated();

        // The accepted GRN auto-staged a draft bill; the supplier invoice
        // attach makes it live with the supplier's invoice number.
        $bill = \App\Modules\Accounting\Models\Bill::query()
            ->where('vendor_id', $vendor->id)
            ->whereNotNull('supplier_invoice_number')
            ->first();
        $this->assertNotNull($bill, 'supplier invoice must turn the staged bill live');

        // Wrong time: a second invoice on the now-invoiced PO is refused.
        $this->actingAsPortal($portal)
            ->postJson("/api/v1/b2b/supplier/purchase-orders/{$poHash}/submit-invoice", [
                'bill_number' => 'SINV-'.substr(uniqid(), -6),
                'date' => now()->toDateString(),
            ])
            ->assertStatus(422);
    }

    /* ══════════════════════════════════════════════════════════════════
       4. WAREHOUSE MANAGEMENT
       ══════════════════════════════════════════════════════════════════ */

    public function test_4_warehouse_map_receive_issue_transfer_count(): void
    {
        // ── Wrong actor: finance cannot manage the warehouse map ──
        $this->actingAs($this->finance)
            ->postJson('/api/v1/inventory/warehouses', [
                'name' => 'Finance Warehouse',
                'code' => 'FIN-WH',
            ])
            ->assertForbidden();

        // ── Warehouse map: warehouse → zone → location ──
        $whId = $this->actingAs($this->warehouse)
            ->postJson('/api/v1/inventory/warehouses', [
                'name' => 'Main Warehouse Audit',
                'code' => 'WHA',
            ])
            ->assertCreated()
            ->json('data.id');
        $zoneId = $this->actingAs($this->warehouse)
            ->postJson('/api/v1/inventory/zones', [
                'warehouse_id' => $whId,
                'name' => 'Resin Zone',
                'code' => 'RZ',
                'zone_type' => 'raw_materials',
            ])
            ->assertCreated()
            ->json('data.id');
        $locId = $this->actingAs($this->warehouse)
            ->postJson('/api/v1/inventory/locations', [
                'zone_id' => $zoneId,
                'code' => 'RZ-A1',
                'rack' => 'A',
                'bin' => '01',
            ])
            ->assertCreated()
            ->json('data.id');

        $zone = WarehouseZone::query()->whereKey((int) app('hashids')->decode($zoneId)[0])->firstOrFail();
        $location = WarehouseLocation::query()->whereKey((int) app('hashids')->decode($locId)[0])->firstOrFail();

        // ── Receive stock through the segregated receive → QC pass flow ──
        $item = Item::factory()->create(['is_active' => true, 'unit_of_measure' => 'pcs']);
        $po = $this->sentPurchaseOrder($this->supplier()[0], '100.00', $item);
        $receive = $this->asWeb($this->warehouse)
            ->postJson('/api/v1/inventory/receive-goods', [
                'purchase_order_id' => $po->hash_id,
                'received_date' => now()->toDateString(),
                'items' => [[
                    'purchase_order_item_id' => $po->items->first()->hash_id,
                    'item_id' => $item->hash_id,
                    'location_id' => $location->hash_id,
                    'quantity_received' => '100.000',
                    'unit_cost' => '10.0000',
                ]],
                'qc' => ['result' => 'pending'],
            ]);
        $receive->assertCreated();

        $grn = \App\Modules\Inventory\Models\GoodsReceiptNote::query()
            ->where('purchase_order_id', $po->id)
            ->firstOrFail();
        $this->assertSame(GrnStatus::PendingQc->value, $grn->status->value);

        $inspection = \App\Modules\Quality\Models\Inspection::query()
            ->where('stage', 'incoming')
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->firstOrFail();
        $rows = \App\Modules\Quality\Models\InspectionMeasurement::query()
            ->where('inspection_id', $inspection->id)->get();
        $this->asWeb($this->qc)
            ->postJson("/api/v1/quality/inspections/{$inspection->hash_id}/lot-result", [
                'checklist' => $rows->map(fn ($r) => ['id' => $r->hash_id, 'is_pass' => true])->all(),
                'measurements' => [],
                'sample_defect_count' => 0,
                'complete' => true,
            ])
            ->assertOk();
        $checker = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'qc_inspector')->value('id'),
            'is_active' => true,
        ]);
        $this->asWeb($checker)
            ->patchJson("/api/v1/quality/inspections/{$inspection->hash_id}/review", ['decision' => 'passed'])
            ->assertOk();

        $grn = \App\Modules\Inventory\Models\GoodsReceiptNote::query()
            ->where('purchase_order_id', $po->id)
            ->firstOrFail();
        $this->assertSame(GrnStatus::Accepted->value, $grn->status->value, 'QC pass auto-accepts the receipt');

        $level = StockLevel::query()->where('item_id', $item->id)->where('location_id', $location->id)->firstOrFail();
        $this->assertSame('100.000', (string) $level->quantity);
        $this->assertSame('10.0000', (string) $level->weighted_avg_cost);

        // ── Issue materials against the stock ──
        $issue = $this->actingAs($this->warehouse)
            ->postJson('/api/v1/inventory/material-issues', [
                'issued_date' => now()->toDateString(),
                'reference_text' => 'WO-TEST feedstock',
                'items' => [[
                    'item_id' => $item->hash_id,
                    'location_id' => $location->hash_id,
                    'quantity_issued' => '30.000',
                ]],
            ]);
        $issue->assertCreated();

        $level->refresh();
        $this->assertSame('70.000', (string) $level->quantity);
        $this->assertSame('10.0000', (string) $level->weighted_avg_cost, 'issues do not change WAC');

        // ── Transfer the rest to a second bin ──
        $loc2Id = $this->actingAs($this->warehouse)
            ->postJson('/api/v1/inventory/locations', [
                'zone_id' => $zoneId,
                'code' => 'RZ-A2',
            ])
            ->assertCreated()
            ->json('data.id');

        $transfer = $this->actingAs($this->warehouse)
            ->postJson('/api/v1/inventory/transfer-orders', [
                'from_location_id' => $location->hash_id,
                'to_location_id' => $loc2Id,
                'item_id' => $item->hash_id,
                'quantity' => '20.000',
            ]);
        $transfer->assertCreated();
        $transferId = $transfer->json('data.id');
        $this->actingAs($this->warehouse)
            ->postJson("/api/v1/inventory/transfer-orders/{$transferId}/execute")
            ->assertOk();

        $this->assertSame('50.000', (string) $level->fresh()->quantity);
        $loc2 = WarehouseLocation::query()->whereKey((int) app('hashids')->decode($loc2Id)[0])->firstOrFail();
        $level2 = StockLevel::query()->where('item_id', $item->id)->where('location_id', $loc2->id)->firstOrFail();
        $this->assertSame('20.000', (string) $level2->quantity);

        // ── Cycle count: warehouse-scoped session, count, approve, complete ──
        $count = $this->actingAs($this->warehouse)
            ->postJson('/api/v1/inventory/stock-counts', [
                'title' => 'Audit cycle count',
                'scope' => 'warehouse',
                'warehouse_id' => $whId,
            ]);
        $count->assertCreated();
        $countId = $count->json('data.id');

        $this->actingAs($this->warehouse)
            ->postJson("/api/v1/inventory/stock-counts/{$countId}/start")
            ->assertOk();

        // The session snapshot lists the location's stock; count 48 vs 50.
        $sessionItems = $this->actingAs($this->warehouse)
            ->getJson("/api/v1/inventory/stock-counts/{$countId}")
            ->assertOk()
            ->json('data.items');
        $this->assertNotEmpty($sessionItems);

        $countItemId = null;
        foreach ($sessionItems as $si) {
            // Item rows hash-bind the location under a nested 'location' key.
            $candidate = $si['location']['id'] ?? $si['location_id'] ?? null;
            if ($candidate !== null) {
                $decodedLoc = (int) app('hashids')->decode((string) $candidate)[0];
                if ($decodedLoc === $location->id) {
                    $countItemId = $si['id'];
                }
            }
        }
        $this->assertNotNull($countItemId, 'the stocked bin is part of the count scope');

        // The transfer created a second stocked bin in scope — EVERY count
        // line must be recorded before the session can complete. Count the
        // variance bin at 48 and the untouched bin at its system quantity.
        $varianceItemId = $countItemId;
        foreach ($sessionItems as $si) {
            $candidate = $si['location']['id'] ?? $si['location_id'] ?? null;
            if ($candidate === null) {
                continue;
            }
            $decodedLoc = (int) app('hashids')->decode((string) $candidate)[0];
            if ($decodedLoc === $location->id) {
                continue; // the variance line, handled below
            }
            $zeroVariance = $this->actingAs($this->warehouse)
                ->postJson("/api/v1/inventory/stock-counts/items/{$si['id']}/count", [
                    'counted_quantity' => $si['system_quantity'],
                ])
                ->assertOk();
            $varianceItemId = $varianceItemId === $si['id'] ? null : $varianceItemId;
        }

        $this->actingAs($this->warehouse)
            ->postJson("/api/v1/inventory/stock-counts/items/{$varianceItemId}/count", ['counted_quantity' => '48.000'])
            ->assertOk();
        // Maker-checker: the counter cannot approve their own variance —
        // a different stock-count manager (here: admin) signs it off.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/inventory/stock-counts/items/{$varianceItemId}/approve", [])
            ->assertOk();

        // Session completion is checker-gated too: the session creator (the
        // counting warehouse user) cannot complete it — the approver does.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/inventory/stock-counts/{$countId}/complete")
            ->assertOk();

        // The approved variance moved stock: 50 booked → 48 counted.
        $this->assertSame('48.000', (string) $level->fresh()->quantity);
    }

    /* ══════════════════════════════════════════════════════════════════
       5. RETURN POLICY (customer-return RMA, full walk)
       ══════════════════════════════════════════════════════════════════ */

    public function test_5_return_policy_full_rma_walk_with_credit_note(): void
    {
        $customer = \App\Modules\Accounting\Models\Customer::create([
            'name' => 'RMA Audit Customer', 'payment_terms_days' => 30,
        ]);
        $product = Product::create([
            'part_number' => 'PT-'.substr(uniqid(), -5),
            'name' => 'Wiper Bushing Audit',
        ]);
        // A stockable restock needs the finished-good ITEM, not just the product.
        $item = Item::factory()->create([
            'code' => $product->part_number,
            'item_type' => \App\Modules\Inventory\Enums\ItemType::FinishedGood->value,
            'is_active' => true,
        ]);
        // …and provenance: the delivered sales-order line being returned.
        $salesOrder = \App\Modules\CRM\Models\SalesOrder::factory()->create([
            'customer_id' => $customer->id,
            'status' => \App\Modules\CRM\Enums\SalesOrderStatus::Delivered->value,
            'created_by' => $this->csOfficer->id,
        ]);
        $soItem = \App\Modules\CRM\Models\SalesOrderItem::factory()->create([
            'sales_order_id' => $salesOrder->id,
            'product_id' => $product->id,
            'quantity_delivered' => '10.000',
            'unit_price' => '100.00',
        ]);
        // Return inspections are spec-driven: an active spec with ≥1 parameter
        // must exist or the Quality handoff falls to manual_required.
        $spec = \App\Modules\Quality\Models\InspectionSpec::create([
            'product_id' => $product->id,
            'version' => 1,
            'is_active' => true,
            'created_by' => $this->qc->id,
        ]);
        \App\Modules\Quality\Models\InspectionSpecItem::create([
            'inspection_spec_id' => $spec->id,
            'parameter_name' => 'Visual return condition',
            'parameter_type' => \App\Modules\Quality\Enums\InspectionParameterType::Visual->value,
            'is_critical' => true,
            'sort_order' => 1,
        ]);

        // ── Wrong actor: warehouse cannot raise an RMA ──
        $payload = [
            'type' => 'customer_return',
            'customer_id' => $customer->hash_id,
            'sales_order_id' => $salesOrder->hash_id,
            'reason_code' => 'defective',
            'return_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $product->hash_id,
                'item_id' => $item->hash_id,
                'source_sales_order_item_id' => $soItem->hash_id,
                'quantity' => '10.000',
                'unit_price' => '100.00',
                'reason' => 'defective',
                'condition' => 'damaged',
            ]],
        ];
        $this->actingAs($this->warehouse)
            ->postJson('/api/v1/return-management/return-requests', $payload)
            ->assertForbidden();

        // ── CS officer raises and submits the RMA ──
        $rma = $this->actingAs($this->csOfficer)
            ->postJson('/api/v1/return-management/return-requests', $payload)
            ->assertCreated()
            ->json('data');
        $rmaId = (int) app('hashids')->decode($rma['id'])[0];

        $this->actingAs($this->csOfficer)
            ->postJson("/api/v1/return-management/return-requests/{$rma['id']}/submit")
            ->assertOk();

        $rmaModel = \App\Modules\ReturnManagement\Models\ReturnRequest::query()->findOrFail($rmaId);
        $this->assertSame('pending_approval', $rmaModel->status->value);

        // ── Approval chain: department_head → production_manager ──
        $this->actingAs($this->deptHead)
            ->postJson("/api/v1/return-management/return-requests/{$rma['id']}/approve")
            ->assertOk();
        // Wrong order: VP cannot jump the chain (not step 2's role holder for
        // the row-scope; finance also cannot double-tap step 1).
        $this->actingAs($this->finance)
            ->postJson("/api/v1/return-management/return-requests/{$rma['id']}/approve")
            ->assertStatus(422);
        $this->actingAs($this->prodManager)
            ->postJson("/api/v1/return-management/return-requests/{$rma['id']}/approve")
            ->assertOk();
        $this->assertSame('approved', $rmaModel->fresh()->status->value);

        // ── Receive into quarantine (map of item hash_id → received qty) ──
        $quarantine = WarehouseLocation::factory()->create([
            'zone_id' => WarehouseZone::factory()->create([
                'warehouse_id' => Warehouse::factory()->create(['code' => 'QWH'])->id,
                'code' => 'QZ',
                'name' => 'Quarantine Zone',
                'zone_type' => 'quarantine',
            ])->id,
            'code' => 'QZ-A1',
        ]);
        $receive = $this->actingAs($this->warehouse)
            ->postJson("/api/v1/return-management/return-requests/{$rma['id']}/receive", [
                'received_quantities' => [$rmaModel->items->first()->hash_id => '8.000'],
                'quarantine_location_id' => $quarantine->hash_id,
                'final_receipt' => true,
            ]);
        $receive->assertOk();
        $this->assertSame('received', $rmaModel->fresh()->status->value);

        // ── Stage the Quality inspection ──
        $inspect = $this->actingAs($this->qc)
            ->postJson("/api/v1/return-management/return-requests/{$rma['id']}/inspect");
        $inspect->assertOk();
        $handoff = $inspect->json('data.inspection_handoff.status');
        $this->assertSame(
            'inspected',
            $rmaModel->fresh()->status->value,
            "inspection handoff must complete cleanly, got: {$handoff}",
        );

        // QC answers the spec rows (all pass) and completes the staged return
        // inspection — a restock disposition requires a positive verdict.
        $returnInspection = \App\Modules\Quality\Models\Inspection::query()
            ->where('entity_type', 'return_request')
            ->where('entity_id', $rmaModel->id)
            ->firstOrFail();
        $rows = \App\Modules\Quality\Models\InspectionMeasurement::query()
            ->where('inspection_id', $returnInspection->id)->get();
        $this->actingAs($this->qc)
            ->patchJson("/api/v1/quality/inspections/{$returnInspection->hash_id}/measurements", [
                'measurements' => $rows->map(fn ($r) => ['id' => $r->hash_id, 'is_pass' => true])->all(),
            ])
            ->assertOk();
        $this->actingAs($this->qc)
            ->postJson("/api/v1/quality/inspections/{$returnInspection->hash_id}/complete")
            ->assertOk();

        // ── Dispose: restock the good pieces (needs a location) ──
        $location = WarehouseLocation::factory()->create();
        $dispose = $this->actingAs($this->csOfficer)
            ->postJson("/api/v1/return-management/return-requests/{$rma['id']}/dispose", [
                'dispositions' => [[
                    'item_id' => $rmaModel->items->first()->hash_id,
                    'disposition' => 'restock',
                    'notes' => 'Minor cosmetic damage only.',
                ]],
                'location_id' => $location->hash_id,
            ]);
        $dispose->assertOk();
        $this->assertSame('disposed', $rmaModel->fresh()->disposition_status);

        // ── Complete ──
        $this->actingAs($this->csOfficer)
            ->postJson("/api/v1/return-management/return-requests/{$rma['id']}/complete", [
                'location_id' => $location->hash_id,
            ])
            ->assertOk();
        $this->assertSame('completed', $rmaModel->fresh()->status->value);

        // Wrong time: a completed RMA cannot be cancelled.
        $this->actingAs($this->csOfficer)
            ->postJson("/api/v1/return-management/return-requests/{$rma['id']}/cancel", ['reason' => 'Too late.'])
            ->assertStatus(422);
    }

    /* ══════════════════════════════════════════════════════════════════
       6. FORECASTING (module + dashboard surface)
       ══════════════════════════════════════════════════════════════════ */

    public function test_6_forecasting_module_and_dashboard_widgets(): void
    {
        $product = Product::factory()->create(['include_forecast_in_mrp' => true]);
        $excluded = Product::factory()->create(['include_forecast_in_mrp' => false]);

        // ── Wrong actor: warehouse can view forecasts but not manage them ──
        $this->actingAs($this->warehouse)
            ->postJson('/api/v1/forecasting/demand-forecasts/manual', [
                'product_id' => $product->hash_id,
                'forecast_year' => 2026,
                'forecast_month' => 10,
                'forecasted_quantity' => 500,
            ])
            ->assertForbidden();

        // ── PPC enters a manual forecast override ──
        $this->actingAs($this->ppc)
            ->postJson('/api/v1/forecasting/demand-forecasts/manual', [
                'product_id' => $product->hash_id,
                'forecast_year' => 2026,
                'forecast_month' => 10,
                'forecasted_quantity' => 500,
                'confidence_level' => 80,
            ])
            ->assertCreated();

        // Uniqueness: re-saving the same period updates, not duplicates.
        $this->actingAs($this->ppc)
            ->postJson('/api/v1/forecasting/demand-forecasts/manual', [
                'product_id' => $product->hash_id,
                'forecast_year' => 2026,
                'forecast_month' => 10,
                'forecasted_quantity' => 600,
            ])
            ->assertCreated();
        $this->assertSame(
            1,
            \App\Modules\Forecasting\Models\DemandForecast::query()
                ->where('product_id', $product->id)
                ->where('forecast_year', 2026)
                ->where('forecast_month', 10)
                ->whereNull('customer_id')
                ->count(),
        );

        // ── MRP projection honours the product opt-in ──
        $projection = $this->actingAs($this->ppc)
            ->getJson('/api/v1/forecasting/mrp-projection?year=2026&month=10')
            ->assertOk()
            ->json('data');
        $projectedIds = collect($projection['products'])->pluck('product_id');
        $this->assertTrue($projectedIds->contains($product->hash_id));
        $this->assertFalse($projectedIds->contains($excluded->hash_id), 'opted-out product must not explode');

        // ── Accuracy + stock-out surfaces respond ──
        $this->actingAs($this->ppc)->getJson('/api/v1/forecasting/accuracy/summary')->assertOk();
        $this->actingAs($this->ppc)->getJson('/api/v1/forecasting/stock-out')->assertOk();

        // ── Dashboard: forecast.* widgets render for EVERY holding role ──
        // forecast.headcount (hr.employees.view), forecast.revenue
        // (accounting.dashboard.view), forecast.defect_rate (quality.view).
        $widgetRoles = ['system_admin', 'hr_officer', 'finance_officer', 'production_manager', 'qc_inspector', 'ppc_head', 'purchasing_officer'];
        foreach ($widgetRoles as $slug) {
            $user = User::factory()->create([
                'role_id' => Role::query()->where('slug', $slug)->value('id'),
                'is_active' => true,
            ]);
            $allowed = collect($this->actingAs($user)->getJson('/api/v1/dashboard/widgets')->assertOk()->json('data'))
                ->pluck('key');

            if ($slug === 'system_admin' || in_array($slug, ['hr_officer', 'production_manager', 'qc_inspector'], true)) {
                // hr.employees.view / quality.view / accounting.dashboard.view
                // holders each qualify for at least one forecast widget.
                $this->assertTrue(
                    $allowed->contains('forecast.headcount') || $allowed->contains('forecast.defect_rate'),
                    "{$slug} should qualify for at least one forecast widget",
                );
            }

            // Any forecast widget they qualify for must render data without error.
            $widgetKeys = array_values(array_intersect(
                ['forecast.headcount', 'forecast.revenue', 'forecast.defect_rate'],
                $allowed->all(),
            ));
            if ($widgetKeys === []) {
                continue;
            }
            $data = $this->actingAs($user)
                ->getJson('/api/v1/dashboard/widget-data?'.http_build_query(['keys' => $widgetKeys]));
            $data->assertOk();
        }

        // Employee self-service role has NO forecast widget.
        $employee = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
            'is_active' => true,
        ]);
        $employeeKeys = collect($this->actingAs($employee)->getJson('/api/v1/dashboard/widgets')->assertOk()->json('data'))
            ->pluck('key');
        $this->assertFalse($employeeKeys->contains(fn ($k) => str_starts_with((string) $k, 'forecast.')));
    }

    /* ══════════════════════════════════════════════════════════════════
       Fixtures & helpers
       ══════════════════════════════════════════════════════════════════ */

    /** @return array{0: \App\Modules\Purchasing\Models\PurchaseRequest, 1: Item} */
    private function approvedPurchaseRequest(): array
    {
        $item = Item::factory()->create(['unit_of_measure' => 'kg', 'is_active' => true]);
        $pr = \App\Modules\Purchasing\Models\PurchaseRequest::factory()->create([
            'requested_by' => $this->buyer->id,
            'is_auto_generated' => true,
        ]);
        $pr->forceFill([
            'status' => \App\Modules\Purchasing\Enums\PurchaseRequestStatus::Approved,
            'po_conversion_status' => \App\Modules\Purchasing\Enums\PurchaseRequestConversionStatus::SourcingPending,
            'sourcing_method' => \App\Modules\Purchasing\Enums\PurchaseRequestSourcingMethod::Rfq,
            'required_delivery_date' => now()->addDays(30)->toDateString(),
        ])->save();
        \App\Modules\Purchasing\Models\PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'item_id' => $item->id,
            'description' => 'PP resin audit',
            'quantity' => '10.00',
            'unit' => 'kg',
            'estimated_unit_price' => '60.00',
        ]);

        return [$pr->fresh(), $item];
    }

    /** @return array{0: Vendor, 1: SupplierPortalUser} */
    private function supplier(): array
    {
        $vendor = Vendor::factory()->create(['email' => fake()->unique()->safeEmail()]);
        $portal = SupplierPortalUser::factory()->create([
            'vendor_id' => $vendor->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);

        return [$vendor, $portal];
    }

    /** @param list<Vendor> $vendors */
    private function rfqBody(array $vendors, bool $publish = false): array
    {
        return [
            'title' => 'Audit RFQ',
            'closes_at' => now()->addDay()->toIso8601String(),
            'publish' => $publish,
            'invitations' => array_map(fn (Vendor $vendor): array => [
                'vendor_id' => $vendor->hash_id,
                'exception_reason' => 'Not on the approved list yet.',
            ], $vendors),
        ];
    }

    private function quoteThroughPortal(SupplierPortalUser $portal, string $rfqId, string $lineId, string $qty, string $price, string $freight): void
    {
        $this->actingAsPortal($portal)
            ->postJson("/api/v1/b2b/supplier/rfqs/{$rfqId}/documents", [
                'document_type' => 'quotation_pdf',
                'file' => UploadedFile::fake()->create('quotation.pdf', 20, 'application/pdf'),
            ])
            ->assertCreated();

        $this->actingAsPortal($portal)
            ->putJson("/api/v1/b2b/supplier/rfqs/{$rfqId}/quote", [
                'submit' => true,
                'vat_treatment' => 'exclusive',
                'freight_amount' => $freight,
                'quote_valid_until' => now()->addDays(30)->toDateString(),
                'payment_terms' => '30 days',
                'items' => [[
                    'request_for_quote_item_id' => $lineId,
                    'response_status' => 'quoted',
                    'offered_quantity' => $qty,
                    'unit_price' => $price,
                    'lead_time_days' => 7,
                ]],
            ])
            ->assertOk();
    }

    private function sentPurchaseOrder(Vendor $vendor, string $qty = '500.00', ?Item $item = null): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $po->forceFill([
            'status' => PurchaseOrderStatus::Sent->value,
            'sent_to_supplier_at' => now(),
        ])->save();
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => ($item ?? Item::factory()->create(['is_active' => true]))->id,
            'description' => 'Audit item',
            'quantity' => $qty,
            'quantity_received' => '0.00',
            'quantity_accepted' => '0.00',
            'unit' => 'pcs',
            'unit_price' => '10.00',
            'total' => \App\Common\Support\Money::mul($qty, '10.00'),
        ]);

        return $po->refresh();
    }

    /**
     * Receive the PO fully through the segregated two-actor flow: warehouse
     * stages the receipt (inventory.grn.create), the listener stages a per-line
     * incoming inspection, QC records the pass (quality.inspections.manage),
     * an independent checker confirms — ending at GRN accepted.
     */
    private function receiveAndAcceptPo(PurchaseOrder $po, WarehouseLocation $location, string $qty = '500.000'): void
    {
        $poItem = $po->items->first();

        // Warehouse stages the receipt WITHOUT a terminal QC verdict.
        $this->asWeb($this->warehouse)
            ->postJson('/api/v1/inventory/receive-goods', [
                'purchase_order_id' => $po->hash_id,
                'received_date' => now()->toDateString(),
                'items' => [[
                    'purchase_order_item_id' => $poItem->hash_id,
                    'item_id' => $poItem->item->hash_id,
                    'location_id' => $location->hash_id,
                    'quantity_received' => $qty,
                    'unit_cost' => '10.0000',
                ]],
                'qc' => ['result' => 'pending'],
            ])
            ->assertCreated();

        $grn = \App\Modules\Inventory\Models\GoodsReceiptNote::query()
            ->where('purchase_order_id', $po->id)
            ->firstOrFail();
        $this->assertSame(GrnStatus::PendingQc->value, $grn->status->value);

        $inspection = \App\Modules\Quality\Models\Inspection::query()
            ->where('stage', 'incoming')
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->firstOrFail();
        $rows = \App\Modules\Quality\Models\InspectionMeasurement::query()
            ->where('inspection_id', $inspection->id)->get();

        // QC maker records an all-PASS lot checklist.
        $this->asWeb($this->qc)
            ->postJson("/api/v1/quality/inspections/{$inspection->hash_id}/lot-result", [
                'checklist' => $rows->map(fn ($r) => ['id' => $r->hash_id, 'is_pass' => true])->all(),
                'measurements' => [],
                'sample_defect_count' => 0,
                'complete' => true,
            ])
            ->assertOk();

        // Independent checker confirms (maker-checker gate).
        $checker = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'qc_inspector')->value('id'),
            'is_active' => true,
        ]);
        $this->asWeb($checker)
            ->patchJson("/api/v1/quality/inspections/{$inspection->hash_id}/review", [
                'decision' => 'passed',
            ])
            ->assertOk();

        $this->assertSame(GrnStatus::Accepted->value, $grn->fresh()->status->value);
    }

    private function actingAsPortal(SupplierPortalUser $portal): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($portal, ['*'], 'supplier_portal');

        return $this;
    }

    /**
     * Switch back to a web-guard user after a portal session. Without
     * forgetting the guards the stale supplier token keeps answering.
     */
    private function asWeb(User $user): self
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->guard('web')->setUser($user);
        $this->app['auth']->shouldUse('web');

        return $this->actingAs($user, 'web');
    }
}
