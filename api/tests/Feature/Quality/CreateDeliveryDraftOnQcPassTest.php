<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Services\DocumentSequenceService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Production\Models\WorkOrder;
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
            'name'               => 'Cust ' . uniqid(),
            'is_active'          => true,
            'payment_terms_days' => 30,
        ]);

        $product = Product::create([
            'part_number'     => strtoupper(substr(uniqid('PT-'), 0, 12)),
            'name'            => 'Wiper Bushing ' . uniqid(),
            'unit_of_measure' => 'pcs',
            'standard_cost'   => '50.00',
            'is_active'       => true,
        ]);

        $so = SalesOrder::create([
            'so_number'    => 'SO-L7-' . substr(uniqid(), -10),
            'customer_id'  => $customer->id,
            'date'         => now()->toDateString(),
            'subtotal'     => '0.00',
            'vat_amount'   => '0.00',
            'total_amount' => '0.00',
            'status'       => 'in_production',
            'created_by'   => $user->id,
        ]);

        $soItem = SalesOrderItem::create([
            'sales_order_id'     => $so->id,
            'product_id'         => $product->id,
            'quantity'           => '10',
            'unit_price'         => $unitPrice,
            'total'              => bcmul('10', $unitPrice, 2),
            'quantity_delivered' => 0,
            'delivery_date'      => now()->addDays(7)->toDateString(),
        ]);

        $wo = WorkOrder::create([
            'wo_number'           => 'WO-L7-' . substr(uniqid(), -8),
            'product_id'          => $product->id,
            'sales_order_id'      => $so->id,
            'sales_order_item_id' => $soItem->id,
            'quantity_target'     => 10,
            'quantity_produced'   => 10,
            'quantity_good'       => 10,
            'quantity_rejected'   => 0,
            'planned_start'       => now()->subDay(),
            'planned_end'         => now(),
            'status'              => 'completed',
            'created_by'          => $user->id,
        ]);

        $inspection = Inspection::create([
            'inspection_number' => 'QC-L7-' . substr(uniqid(), -8),
            'stage'             => InspectionStage::Outgoing->value,
            'status'            => InspectionStatus::Passed->value,
            'product_id'        => $product->id,
            'entity_type'       => InspectionEntityType::WorkOrder->value,
            'entity_id'         => $wo->id,
            'batch_quantity'    => 10,
            'sample_size'       => 5,
            'accept_count'      => 0,
            'reject_count'      => 1,
            'defect_count'      => 0,
            'completed_at'      => now(),
        ]);

        return [$wo, $inspection];
    }
}
