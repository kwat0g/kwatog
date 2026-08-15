<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Events\InspectionPassed;
use App\Modules\Quality\Listeners\CreateDeliveryDraftOnQcPass;
use App\Modules\Quality\Models\Inspection;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L-7 — Auto-delivery draft must inherit unit_price from the SO item, not '0.00'.
 *
 * The listener fires when an outgoing inspection passes; the resulting
 * DeliveryItem flows into the C-1 auto-invoice path on customer confirm.
 * Hardcoded '0.00' produced zero-amount invoices. Fix: copy unit_price from
 * the parent SalesOrderItem.
 */
class CreateDeliveryDraftOnQcPassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_auto_draft_inherits_unit_price_from_sales_order_item(): void
    {
        [$wo, $inspection] = $this->arrange(unitPrice: '75.00');

        $listener = new CreateDeliveryDraftOnQcPass(app(DocumentSequenceService::class));
        $listener->handle(new InspectionPassed($inspection));

        $delivery = Delivery::query()
            ->where('sales_order_id', $wo->sales_order_id)
            ->first();
        $this->assertNotNull($delivery, 'Listener must draft a delivery for the SO.');

        $item = DeliveryItem::query()->where('delivery_id', $delivery->id)->first();
        $this->assertNotNull($item, 'Listener must add a delivery item.');

        $this->assertSame(
            '75.00',
            (string) $item->unit_price,
            'unit_price must be copied from the SalesOrderItem, not hardcoded 0.00.',
        );
        $this->assertSame('10.000', (string) $item->quantity);
        $this->assertSame($inspection->id, $item->inspection_id);
    }

    public function test_auto_draft_does_not_bypass_sales_order_remaining_quantity(): void
    {
        [$wo, $inspection] = $this->arrange(unitPrice: '75.00');
        $soItem = SalesOrderItem::query()->whereKey($wo->sales_order_item_id)->firstOrFail();

        $existing = Delivery::create([
            'delivery_number' => 'DL-L7-'.substr(uniqid(), -8),
            'sales_order_id' => $wo->sales_order_id,
            'status' => 'scheduled',
            'scheduled_date' => now()->toDateString(),
            'created_by' => $wo->created_by,
        ]);
        DeliveryItem::create([
            'delivery_id' => $existing->id,
            'sales_order_item_id' => $soItem->id,
            'quantity' => '6',
            'unit_price' => '75.00',
        ]);

        // The QC event would request 10 more units. The listener must surface
        // the state error to the queue worker and leave no over-delivery draft.
        try {
            (new CreateDeliveryDraftOnQcPass(app(DocumentSequenceService::class)))
                ->handle(new InspectionPassed($inspection));
            $this->fail('An over-delivery must fail the stateful chain step.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('exceeds', strtolower($e->getMessage()));
        }

        $this->assertSame(1, Delivery::query()->count());
        $this->assertSame(1, DeliveryItem::query()->count());
    }

    public function test_invalid_work_order_line_does_not_commit_an_empty_delivery(): void
    {
        [$wo, $inspection] = $this->arrange(unitPrice: '75.00');
        $wo->update(['sales_order_item_id' => null]);

        try {
            (new CreateDeliveryDraftOnQcPass(app(DocumentSequenceService::class)))
                ->handle(new InspectionPassed($inspection));
            $this->fail('A WO without an SO line must fail the stateful chain step.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('sales-order line', strtolower($e->getMessage()));
        }

        $this->assertSame(0, Delivery::query()->count());
        $this->assertSame(0, DeliveryItem::query()->count());
    }

    public function test_stale_pass_event_does_not_draft_delivery_for_non_passed_inspection(): void
    {
        [$wo, $inspection] = $this->arrange(unitPrice: '75.00');
        $staleEvent = new InspectionPassed($inspection->fresh());
        $inspection->update(['status' => InspectionStatus::Failed->value]);

        (new CreateDeliveryDraftOnQcPass(app(DocumentSequenceService::class)))
            ->handle($staleEvent);

        $this->assertSame(0, Delivery::query()
            ->where('sales_order_id', $wo->sales_order_id)
            ->count());
    }

    public function test_legacy_product_only_pass_does_not_draft_delivery(): void
    {
        [$wo, $inspection] = $this->arrange(unitPrice: '75.00');
        $inspection->update(['work_order_output_id' => null, 'accepted_quantity' => 0]);

        (new CreateDeliveryDraftOnQcPass(app(DocumentSequenceService::class)))
            ->handle(new InspectionPassed($inspection->fresh()));

        $this->assertSame(0, Delivery::query()->where('sales_order_id', $wo->sales_order_id)->count());
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a minimum-viable SO+SOItem+WO+Inspection chain so the listener
     * has everything it needs to draft a delivery.
     *
     * @return array{0: WorkOrder, 1: Inspection}
     */
    private function arrange(string $unitPrice): array
    {
        $role = Role::firstOrCreate(['slug' => 'l7_test'], ['name' => 'L7 Test']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $customer = Customer::create([
            'name' => 'Cust '.uniqid(),
            'is_active' => true,
            'payment_terms_days' => 30,
        ]);

        $product = Product::create([
            'part_number' => strtoupper(substr(uniqid('PT-'), 0, 12)),
            'name' => 'Wiper Bushing '.uniqid(),
            'unit_of_measure' => 'pcs',
            'standard_cost' => '50.00',
            'is_active' => true,
        ]);

        $so = SalesOrder::create([
            'so_number' => 'SO-L7-'.substr(uniqid(), -10),
            'customer_id' => $customer->id,
            'date' => now()->toDateString(),
            'subtotal' => '0.00',
            'vat_amount' => '0.00',
            'total_amount' => '0.00',
            'status' => 'in_production',
            'created_by' => $user->id,
        ]);

        $soItem = SalesOrderItem::create([
            'sales_order_id' => $so->id,
            'product_id' => $product->id,
            'quantity' => '10',
            'unit_price' => $unitPrice,
            'total' => bcmul('10', $unitPrice, 2),
            'quantity_delivered' => 0,
            'delivery_date' => now()->addDays(7)->toDateString(),
        ]);

        $wo = WorkOrder::create([
            'wo_number' => 'WO-L7-'.substr(uniqid(), -8),
            'product_id' => $product->id,
            'sales_order_id' => $so->id,
            'sales_order_item_id' => $soItem->id,
            'quantity_target' => 10,
            'quantity_produced' => 10,
            'quantity_good' => 10,
            'quantity_rejected' => 0,
            'planned_start' => now()->subDay(),
            'planned_end' => now(),
            'status' => 'completed',
            'created_by' => $user->id,
        ]);

        $output = WorkOrderOutput::create([
            'work_order_id' => $wo->id,
            'recorded_by' => $user->id,
            'recorded_at' => now(),
            'good_count' => 10,
            'reject_count' => 0,
            'batch_code' => 'L7-BATCH-001',
        ]);

        $inspection = Inspection::create([
            'inspection_number' => 'QC-L7-'.substr(uniqid(), -8),
            'stage' => InspectionStage::Outgoing->value,
            'status' => InspectionStatus::Passed->value,
            'product_id' => $product->id,
            'entity_type' => InspectionEntityType::WorkOrder->value,
            'entity_id' => $wo->id,
            'work_order_output_id' => $output->id,
            'batch_quantity' => 10,
            'accepted_quantity' => 10,
            'sample_size' => 5,
            'accept_count' => 0,
            'reject_count' => 1,
            'defect_count' => 0,
            'completed_at' => now(),
        ]);

        return [$wo, $inspection];
    }
}
