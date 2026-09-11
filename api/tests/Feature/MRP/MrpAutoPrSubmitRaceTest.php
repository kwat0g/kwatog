<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * MRP-03 — an MRP rerun must never clobber a draft auto-PR that a buyer
 * submitted concurrently. The rerun reads the prior run's draft auto-PRs to
 * reconcile them; if a submit lands between that read and the reuse/cancel
 * write, the rerun used to reset the PR to Draft and delete its line items.
 *
 * This test simulates the interleaving in-process: the moment the rerun reads
 * the prior draft auto-PR, the row is flipped to Pending (what
 * PurchaseRequestService::submit() commits). The rerun must then leave that PR
 * alone — status still Pending, line items intact — and consolidate demand
 * into a fresh draft PR instead.
 */
class MrpAutoPrSubmitRaceTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private Item $material;
    private WarehouseLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // Suppress MrpPlanGenerated broadcast; it fires after-commit and
        // tries to notify WebSocket channels that don't exist in test env.
        Event::fake([\App\Modules\MRP\Events\MrpPlanGenerated::class]);

        $settings = app(SettingsService::class);
        $settings->set('mrp.safety_buffer_days', 2, 'mrp');
        $settings->set('mrp.work_order.urgent_delivery_days', 5, 'mrp');
        $settings->set('mrp.work_order.urgent_priority', 100, 'mrp');
        $settings->set('mrp.work_order.normal_priority', 50, 'mrp');

        $this->product = Product::create([
            'part_number'     => 'TEST-001',
            'name'            => 'Test Product',
            'unit_of_measure' => 'pcs',
            'standard_cost'   => 10.00,
            'is_active'       => true,
        ]);

        $this->material = Item::factory()->create([
            'code'            => 'RM-TEST-001',
            'unit_of_measure' => 'pcs',
            'lead_time_days'  => 7,
            'standard_cost'   => 5.00,
        ]);

        $this->location = WarehouseLocation::factory()->create();
    }

    public function test_rerun_does_not_clobber_auto_pr_submitted_during_run(): void
    {
        $this->createBom(qtyPerUnit: 2.0, wasteFactor: 0.0);
        $this->setOnHand(qty: 8.0); // gross 20 → net 12 → shortage
        $so = $this->createConfirmedSo(lineQty: 10);

        $this->actingAs($this->ppcActor(), 'sanctum');
        $this->postJson('/api/v1/mrp/runs')->assertAccepted();

        $pr = PurchaseRequest::where('is_auto_generated', true)
            ->whereHas('mrpPlan', fn ($q) => $q->where('sales_order_id', $so->id))
            ->firstOrFail();
        $this->assertSame(PurchaseRequestStatus::Draft->value, $pr->status->value);
        $itemsBefore = $pr->items()->count();
        $this->assertGreaterThan(0, $itemsBefore, 'The first run must have created PR line items.');

        // Simulate the purchasing submit winning the race: as soon as the
        // rerun hydrates the prior draft auto-PR, commit the row as Pending and
        // align the in-memory snapshot so a clobbering writer would see it as
        // dirty. This fires after the read but before the reuse/cancel write.
        $raced = false;
        PurchaseRequest::retrieved(function (PurchaseRequest $model) use ($pr, &$raced): void {
            if ($raced || (int) $model->id !== (int) $pr->id) {
                return;
            }
            $raced = true;

            DB::table('purchase_requests')->where('id', $model->id)->update([
                'status'     => PurchaseRequestStatus::Pending->value,
                'updated_at' => now(),
            ]);
            $model->forceFill(['status' => PurchaseRequestStatus::Pending->value])->syncOriginal();
        });

        $this->postJson('/api/v1/mrp/runs')->assertAccepted();

        $this->assertTrue($raced, 'The race hook must have fired during the rerun.');

        $submitted = $pr->fresh();
        $this->assertSame(
            PurchaseRequestStatus::Pending->value,
            $submitted->status->value,
            'A PR submitted during the MRP rerun must not be reset to Draft.',
        );
        $this->assertSame(
            $itemsBefore,
            $submitted->items()->count(),
            'The submitted PR line items must not be deleted by the rerun.',
        );

        // The rerun must have consolidated the demand into a fresh draft PR
        // rather than reusing the submitted one.
        $this->assertSame(1, PurchaseRequest::where('is_auto_generated', true)
            ->where('status', PurchaseRequestStatus::Draft->value)
            ->whereHas('mrpPlan', fn ($q) => $q->where('sales_order_id', $so->id))
            ->count(), 'The rerun must create a fresh draft auto-PR when the only candidate has been submitted.');
        $this->assertSame(2, PurchaseRequest::where('is_auto_generated', true)
            ->whereHas('mrpPlan', fn ($q) => $q->where('sales_order_id', $so->id))
            ->count(), 'The submitted PR must survive alongside the replacement.');
    }

    private function createBom(float $qtyPerUnit = 2.0, float $wasteFactor = 0.0): Bom
    {
        $bom = Bom::create([
            'product_id' => $this->product->id,
            'version'    => 1,
            'is_active'  => true,
        ]);

        BomItem::create([
            'bom_id'            => $bom->id,
            'item_id'           => $this->material->id,
            'quantity_per_unit' => $qtyPerUnit,
            'unit'              => 'pcs',
            'waste_factor'      => $wasteFactor,
            'sort_order'        => 0,
        ]);

        return $bom;
    }

    private function createConfirmedSo(int $lineQty, int $daysAhead = 30): SalesOrder
    {
        $user = User::factory()->create();

        $so = SalesOrder::create([
            'so_number'          => 'SO-'.now()->format('Ym').'-'.rand(1000, 9999),
            'customer_id'        => $this->createCustomer(),
            'date'               => now()->format('Y-m-d'),
            'subtotal'           => $lineQty * 10,
            'vat_amount'         => 0,
            'total_amount'       => $lineQty * 10,
            'status'             => 'confirmed',
            'payment_terms_days' => 30,
            'created_by'         => $user->id,
        ]);

        SalesOrderItem::create([
            'sales_order_id'  => $so->id,
            'product_id'      => $this->product->id,
            'quantity'        => $lineQty,
            'unit_price'      => 10.00,
            'total'           => $lineQty * 10,
            'delivery_date'   => Carbon::today()->addDays($daysAhead)->format('Y-m-d'),
        ]);

        return $so;
    }

    private function createCustomer(): int
    {
        return DB::table('customers')->insertGetId([
            'name'               => 'Test Customer',
            'is_active'          => true,
            'payment_terms_days' => 30,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    private function setOnHand(float $qty, float $reserved = 0.0): StockLevel
    {
        return StockLevel::create([
            'item_id'           => $this->material->id,
            'location_id'       => $this->location->id,
            'quantity'          => $qty,
            'reserved_quantity' => $reserved,
            'weighted_avg_cost' => 5.00,
            'lock_version'      => 0,
        ]);
    }

    private function ppcActor(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'ppc_head')->value('id'),
        ]);
    }
}
