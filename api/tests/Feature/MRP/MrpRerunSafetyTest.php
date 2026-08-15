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
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Round 2 — MRP rerun safety (demo-hardening design §3.1).
 *
 * The "Run MRP now" button POSTs to /api/v1/mrp/runs, which runs MRP for
 * every active sales order. A re-run must reconcile against the superseded
 * plan's children — reuse the draft auto-PR and the planned WOs — instead of
 * piling duplicates into the purchasing and production queues.
 */
class MrpRerunSafetyTest extends TestCase
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

    /** Confirmed SO, one line for $this->product. Shortage: on-hand 8 < gross 20. */
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

    private function draftAutoPrsFor(SalesOrder $so): int
    {
        return PurchaseRequest::where('is_auto_generated', true)
            ->where('status', PurchaseRequestStatus::Draft->value)
            ->whereHas('mrpPlan', fn ($q) => $q->where('sales_order_id', $so->id))
            ->count();
    }

    private function plannedWosFor(SalesOrder $so): int
    {
        return WorkOrder::where('sales_order_item_id', $so->items()->value('id'))
            ->where('status', 'planned')
            ->count();
    }

    /**
     * P24/R2 — the demo button path: pressing "Run MRP now" twice must leave
     * exactly ONE draft auto-PR and ONE planned WO per SO line, with one
     * superseded plan per press — not a pile of duplicates.
     */
    public function test_double_run_button_does_not_duplicate_prs_or_wos(): void
    {
        $this->createBom(qtyPerUnit: 2.0, wasteFactor: 0.0);
        $this->setOnHand(qty: 8.0); // gross 20 → net 12 → shortage
        $so = $this->createConfirmedSo(lineQty: 10);

        $this->actingAs($this->ppcActor(), 'sanctum');
        $this->postJson('/api/v1/mrp/runs')->assertAccepted();
        $this->postJson('/api/v1/mrp/runs')->assertAccepted();

        $this->assertSame(2, MrpPlan::where('sales_order_id', $so->id)->count(),
            'Two runs = two plans; the second supersedes the first.');

        $this->assertSame(1, $this->draftAutoPrsFor($so),
            'Repeated Run MRP must leave exactly ONE draft auto-PR, not a pile.');

        $this->assertSame(1, $this->plannedWosFor($so),
            'Repeated Run MRP must leave exactly ONE planned WO per SO line.');

        // The reused PR must belong to the LATEST plan (repointed, not orphaned).
        $latest = MrpPlan::where('sales_order_id', $so->id)->orderByDesc('version')->first();
        $this->assertSame(1, PurchaseRequest::where('mrp_plan_id', $latest->id)
            ->where('is_auto_generated', true)
            ->count());
    }

    /**
     * R2 — a PR that has progressed past draft must never be cancelled or
     * repointed by a re-run; its quantity is treated as already-covered
     * demand, so no duplicate draft PR is created.
     */
    public function test_rerun_preserves_progressed_auto_pr(): void
    {
        $this->createBom(qtyPerUnit: 2.0, wasteFactor: 0.0);
        $this->setOnHand(qty: 8.0);
        $so = $this->createConfirmedSo(lineQty: 10);

        $this->actingAs($this->ppcActor(), 'sanctum');
        $this->postJson('/api/v1/mrp/runs')->assertAccepted();

        $progressed = PurchaseRequest::where('is_auto_generated', true)
            ->whereHas('mrpPlan', fn ($q) => $q->where('sales_order_id', $so->id))
            ->firstOrFail();
        $progressed->forceFill(['status' => PurchaseRequestStatus::Pending->value])->save();

        $this->postJson('/api/v1/mrp/runs')->assertAccepted();

        $this->assertSame(PurchaseRequestStatus::Pending->value, $progressed->fresh()->status->value,
            'A progressed auto-PR must never be cancelled or repointed by a re-run.');
        $this->assertSame(0, $this->draftAutoPrsFor($so),
            'An open progressed auto-PR already covers the shortage; the re-run must not create a duplicate draft.');
        $this->assertSame(1, PurchaseRequest::where('is_auto_generated', true)
            ->whereHas('mrpPlan', fn ($q) => $q->where('sales_order_id', $so->id))
            ->count(),
            'The progressed auto-PR remains the only auto-generated request for the SO.');
    }
}
