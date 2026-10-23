<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillPayment;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Models\SupplierShipment;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Events\PurchaseOrderSent;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Models\InspectionMeasurement;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\UomSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Database-backed supplier fulfillment, from an acknowledged PO to payment. */
class SupplierPoFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_dispatch_is_reconciled_to_received_goods_before_invoice_and_payment(): void
    {
        Storage::fake('local');
        config(['inspection.checklist_capture_enabled' => true]);
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, ChartOfAccountsSeeder::class, UomSeeder::class, WorkflowSeeder::class]);
        $actor = static fn (string $role): User => User::factory()->create([
            'role_id' => Role::where('slug', $role)->value('id'), 'is_active' => true,
        ]);
        $buyer = $actor('purchasing_officer');
        $this->assertTrue($buyer->hasPermission('purchasing.delivery_schedules.review'));
        $warehouse = $actor('warehouse_staff');
        $qc = $actor('qc_inspector');
        $qcChecker = $actor('production_manager');
        $finance = $actor('finance_officer');
        $vp = $actor('vice_president');
        $actor('system_admin');
        app(SettingsService::class)->set('system.automation.actor_roles', ['system_admin']);

        $vendor = Vendor::factory()->create(['payment_terms_days' => 30]);
        $supplier = SupplierPortalUser::create([
            'vendor_id' => $vendor->id, 'name' => 'Supplier test', 'email' => 'supplier-fulfillment@test.example',
            'password' => bcrypt('Password1!'), 'is_active' => true,
        ]);
        $otherSupplier = SupplierPortalUser::create([
            'vendor_id' => Vendor::factory()->create()->id, 'name' => 'Other supplier', 'email' => 'other-fulfillment@test.example',
            'password' => bcrypt('Password1!'), 'is_active' => true,
        ]);
        $day = now()->addDays(5)->toDateString();
        $po = PurchaseOrder::factory()->create([
            'vendor_id' => $vendor->id, 'created_by' => $buyer->id,
            'expected_delivery_date' => $day, 'confirmed_delivery_date' => $day,
            'subtotal' => '60000.00', 'vat_amount' => '7200.00', 'total_amount' => '67200.00', 'is_vatable' => true,
        ]);
        $po->forceFill(['status' => 'sent', 'sent_to_supplier_at' => now()])->save();
        $item = Item::factory()->create(['is_active' => true, 'unit_of_measure' => 'kg']);
        $line = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'item_id' => $item->id, 'description' => 'Test resin',
            'quantity' => '100.00', 'unit' => 'kg', 'unit_price' => '600.00', 'total' => '60000.00',
        ]);
        event(new PurchaseOrderSent($po->fresh()));
        $grn = GoodsReceiptNote::where('purchase_order_id', $po->id)->firstOrFail();
        $this->assertSame('draft', $grn->status->value);
        $po->forceFill(['status' => 'acknowledged'])->save();

        Sanctum::actingAs($supplier, ['*'], 'supplier_portal');
        $shipmentId = $this->post("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/shipments", [
            'shipped_date' => now()->toDateString(), 'estimated_arrival' => $day,
            'tracking_number' => 'E2E-LOT-1',
            'dispatch_photo' => UploadedFile::fake()->image('dispatch.jpg'),
            'items' => [['purchase_order_item_id' => $line->hash_id, 'quantity_shipped' => '100.00']],
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.quantity_recorded', true)->json('data.id');
        $this->assertSame('0.00', $line->fresh()->quantity_received);
        $this->assertSame('draft', $grn->fresh()->status->value);

        $location = WarehouseLocation::factory()->create();
        $this->actingAs($warehouse, 'sanctum')->patchJson("/api/v1/inventory/grn/{$grn->hash_id}/finalize", [
            'supplier_shipment_id' => $shipmentId,
            'items' => [[
                'purchase_order_item_id' => $line->hash_id, 'location_id' => $location->hash_id,
                'quantity_received' => '100.000',
            ]],
        ])->assertOk()->assertJsonPath('data.status', 'pending_qc');
        $this->assertSame(SupplierShipment::where('tracking_number', 'E2E-LOT-1')->firstOrFail()->id, $grn->fresh()->supplier_shipment_id);

        $inspection = $grn->fresh()->qcInspection()->firstOrFail();
        $measurements = InspectionMeasurement::where('inspection_id', $inspection->id)->get()
            ->map(static fn ($row): array => ['id' => $row->hash_id, 'result' => 'passed'])->all();
        $qcResult = $this->actingAs($qc, 'sanctum')->postJson("/api/v1/quality/inspections/{$inspection->hash_id}/batch-result", [
            'expected_result_version' => (int) $inspection->result_version,
            'items' => $measurements, 'coverage_confirmed' => true,
            'inspected_quantity' => (int) $inspection->sample_size,
        ])->assertOk();
        if ($qcResult->json('data.status') === 'awaiting_review') {
            $inspection->refresh();
            $this->actingAs($qcChecker, 'sanctum')->patchJson("/api/v1/quality/inspections/{$inspection->hash_id}/review", [
                'decision' => 'passed', 'expected_result_version' => (int) $inspection->result_version,
            ])->assertOk();
        }
        if ($grn->fresh()->status->value === 'pending_qc') {
            $this->actingAs($warehouse, 'sanctum')->patchJson("/api/v1/inventory/grn/{$grn->hash_id}/accept")->assertOk();
        }
        $this->assertSame('accepted', $grn->fresh()->status->value);
        $bill = Bill::where('goods_receipt_note_id', $grn->id)->firstOrFail();
        $this->assertSame('draft', $bill->status->value);

        Sanctum::actingAs($supplier, ['*'], 'supplier_portal');
        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
            'bill_number' => 'SUP-E2E-001', 'date' => now()->toDateString(), 'goods_receipt_note_id' => $grn->hash_id,
        ])->assertCreated();
        $this->assertSame('SUP-E2E-001', $bill->fresh()->supplier_invoice_number);

        $this->actingAs($finance, 'sanctum')->postJson("/api/v1/bills/{$bill->hash_id}/post", [])->assertOk();
        $cash = Account::query()->where('type', 'asset')->where('is_active', true)
            ->whereNotExists(static fn ($query) => $query->selectRaw(1)->from('accounts as children')->whereColumn('children.parent_id', 'accounts.id'))
            ->orderBy('code')->firstOrFail();
        $paymentId = $this->actingAs($finance, 'sanctum')->withHeaders(['Idempotency-Key' => 'supplier-fulfillment-e2e'])
            ->postJson("/api/v1/bills/{$bill->hash_id}/payments", [
                'cash_account_id' => $cash->hash_id, 'payment_date' => now()->toDateString(),
                'amount' => (string) $bill->fresh()->total_amount, 'payment_method' => 'bank_transfer',
                'reference_number' => 'SUP-E2E-PAY',
            ])->assertCreated()->json('data.id');
        // The bill_payment chain is a single VP step at/above ₱50,000; kept
        // above the threshold so the approval gate stays in the walk.
        $this->actingAs($vp, 'sanctum')->postJson("/api/v1/bills/{$bill->hash_id}/payments/{$paymentId}/approve")->assertOk();
        $this->assertSame('paid', $bill->fresh()->status->value);
        $payment = BillPayment::where('bill_id', $bill->id)->firstOrFail();
        $this->assertSame('posted', $payment->status->value);
        $this->assertNotNull($payment->journal_entry_id);
    }
}
