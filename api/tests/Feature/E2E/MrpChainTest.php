<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\PricingMethod;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\MRP\Models\MrpRun;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mission Phase 2 — the MRP planning leg of the Order-to-Cash chain over the
 * real HTTP API, every step performed by its real seeded role (never
 * system_admin).
 *
 * Chain: sales officer confirms an SO → the queued SalesOrderConfirmed
 * listener runs MRP (sync queue in tests) → an MrpPlan explodes the active
 * BOM → material shortages become one consolidated DRAFT auto-PR → each SO
 * line gets one Planned root work order → PPC confirms the WO onto a
 * compatible machine + mold (materials reserved) → production starts it
 * (materials issued, machine flips to running).
 *
 * Adversarial probes ride along: wrong actor (sales officer triggering MRP
 * runs, confirming WOs), wrong data (WO confirm without machine/mold, mold
 * for a different product), planning integrity (missing-BOM diagnostics
 * produce no WO/PR, rerun reuses rather than duplicates the planned WO and
 * retires a draft auto-PR once stock covers the demand, prior plan
 * superseded).
 */
class MrpChainTest extends TestCase
{
    use RefreshDatabase;

    private User $soOfficer;
    private User $ppcHead;
    private User $productionManager;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(UomSeeder::class);

        $make = fn (string $slug): User => User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'is_active' => true,
        ]);

        $this->soOfficer = $make('sales_officer');
        $this->ppcHead = $make('ppc_head');
        $this->productionManager = $make('production_manager');

        $this->customer = Customer::create([
            'name' => 'MRP chain customer '.substr(uniqid(), -6),
            'is_active' => true,
            'payment_terms_days' => 30,
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @template T @param class-string<T> $class @return T */
    private function fromApiId(string $class, ?string $hash): mixed
    {
        $this->assertNotNull($hash, 'API response must carry an id');
        $decoded = app('hashids')->decode($hash);
        $this->assertNotEmpty($decoded, "hash id did not decode: {$hash}");

        return $class::query()->findOrFail($decoded[0]);
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'part_number' => 'FG-'.strtoupper(substr(uniqid(), -8)),
            'name' => 'Relay cover '.substr(uniqid(), -6),
            'unit_of_measure' => 'pcs',
            'standard_cost' => '50.00',
            'is_active' => true,
        ]);
    }

    private function makeRawItem(string $code = null): Item
    {
        return Item::create([
            'code' => $code ?? 'RM-'.strtoupper(substr(uniqid(), -8)),
            'name' => 'Polypropylene resin '.substr(uniqid(), -5),
            'category_id' => ItemCategory::factory()->create()->id,
            'item_type' => 'raw_material',
            'unit_of_measure' => 'kg',
            'standard_cost' => '4.2000',
            'is_active' => true,
        ]);
    }

    private function makePriceAgreement(Product $product): void
    {
        PriceAgreement::create([
            'customer_id' => $this->customer->id,
            'product_id' => $product->id,
            'price' => '100.00',
            'pricing_method' => PricingMethod::Flat,
            'effective_from' => now()->subDay()->toDateString(),
            'effective_to' => now()->addYear()->toDateString(),
        ]);
    }

    /** Draft + confirm an SO for one product/quantity as the sales officer. */
    private function createConfirmedSo(Product $product, string $qty): SalesOrder
    {
        $this->makePriceAgreement($product);

        $res = $this->actingAs($this->soOfficer)
            ->postJson('/api/v1/crm/sales-orders', [
                'customer_id' => $this->customer->hash_id,
                'date' => now()->toDateString(),
                'items' => [[
                    'product_id' => $product->hash_id,
                    'quantity' => $qty,
                    'delivery_date' => now()->addDays(30)->toDateString(),
                ]],
            ]);
        $res->assertCreated();

        /** @var SalesOrder $so */
        $so = $this->fromApiId(SalesOrder::class, $res->json('data.id'));

        $this->actingAs($this->soOfficer)
            ->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/confirm")
            ->assertOk();
        $so->refresh();
        $this->assertSame('confirmed', $so->status->value);

        return $so;
    }

    /** PPC authors a one-component active BOM over the real endpoint. */
    private function createBomOverHttp(Product $product, Item $component, string $qtyPerUnit): Bom
    {
        $res = $this->actingAs($this->ppcHead)
            ->postJson('/api/v1/mrp/boms', [
                'product_id' => $product->hash_id,
                'cost_batch_size' => '1',
                'items' => [[
                    'item_id' => $component->hash_id,
                    'quantity_per_unit' => $qtyPerUnit,
                    'unit' => $component->unit_of_measure,
                    'waste_factor' => '0.00',
                    'sort_order' => 0,
                ]],
            ]);
        $res->assertCreated();

        /** @var Bom $bom */
        $bom = $this->fromApiId(Bom::class, $res->json('data.id'));
        $this->assertTrue((bool) $bom->is_active);

        return $bom;
    }

    /** A usable raw-material stock bin (raw-goods zone, plentiful quantity). */
    private function stockItem(Item $item, string $quantity): void
    {
        $zone = WarehouseZone::query()->where('zone_type', 'raw_materials')->first()
            ?? WarehouseZone::factory()->create(['zone_type' => 'raw_materials']);
        $location = WarehouseLocation::query()->where('zone_id', $zone->id)->first()
            ?? WarehouseLocation::factory()->create(['zone_id' => $zone->id]);

        StockLevel::create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => $quantity,
            'reserved_quantity' => 0,
            'weighted_avg_cost' => '4.2000',
            'lock_version' => 0,
        ]);
    }

    // ------------------------------------------------------------------
    // 1. Confirm → plan → auto-PR for shortages, missing-BOM diagnostics
    // ------------------------------------------------------------------

    public function test_confirmed_order_plans_shortages_and_flags_missing_bom(): void
    {
        // ------------------------------------------------------------------
        // Product WITH an active BOM: 2 kg per piece × 10 pcs = 20 kg gross,
        // nothing in stock → full shortage becomes one draft auto-PR.
        // ------------------------------------------------------------------
        $product = $this->makeProduct();
        $resin = $this->makeRawItem();
        $this->createBomOverHttp($product, $resin, '2.0000');

        $so = $this->createConfirmedSo($product, '10.00');

        // The confirmed SO event ran MRP synchronously (queue:sync).
        $this->assertSame(1, MrpRun::query()->count());
        $run = MrpRun::query()->firstOrFail();
        // The queued listener labels the run "automatic" — the reason string
        // "sales_order_confirmed" rides the summary, not the trigger column.
        $this->assertSame('automatic', $run->triggered_by->value);
        $this->assertSame('completed', $run->status->value);

        $plan = MrpPlan::query()->where('sales_order_id', $so->id)->firstOrFail();
        $this->assertSame('active', $plan->status->value);
        $this->assertSame(1, (int) $plan->version);
        $this->assertMatchesRegularExpression('/^MRP-\d{6}-\d{4}$/', (string) $plan->mrp_plan_no);
        $this->assertSame(1, (int) $plan->shortages_found);
        $this->assertSame(1, (int) $plan->auto_pr_count);
        $this->assertSame(1, (int) $plan->draft_wo_count);

        // One consolidated draft auto-PR carrying the full gross requirement.
        $pr = PurchaseRequest::query()
            ->where('is_auto_generated', true)
            ->where('mrp_plan_id', $plan->id)
            ->firstOrFail();
        $this->assertSame('draft', $pr->status->value);
        $this->assertSame(1, $pr->items()->count());
        $this->assertEquals('20.00', (string) $pr->items()->first()->quantity);

        // One planned root WO for the SO line.
        $wo = WorkOrder::query()
            ->where('sales_order_id', $so->id)
            ->whereNull('parent_wo_id')
            ->firstOrFail();
        $this->assertSame(WorkOrderStatus::Planned->value, $wo->status->value);
        $this->assertSame($plan->id, $wo->mrp_plan_id);
        $this->assertSame(10, (int) $wo->quantity_target);
        $this->assertMatchesRegularExpression('/^WO-\d{6}-\d{4}$/', (string) $wo->wo_number);
        // The BOM was live at WO creation → materials exploded onto the WO.
        $this->assertSame(1, $wo->materials()->count());
        $this->assertEquals('20.000', (string) $wo->materials()->first()->bom_quantity);

        // ------------------------------------------------------------------
        // Product WITHOUT a BOM on the same plant: demand stays visible but
        // produces NO work order and NO auto-PR — only a diagnostic.
        // ------------------------------------------------------------------
        $unbommed = $this->makeProduct();
        $so2 = $this->createConfirmedSo($unbommed, '5.00');

        $plan2 = MrpPlan::query()->where('sales_order_id', $so2->id)->firstOrFail();
        $diagnostics = collect($plan2->diagnostics);
        $this->assertTrue($diagnostics->contains(fn ($row) => ($row['type'] ?? null) === 'missing_bom'));
        $this->assertSame(0, (int) $plan2->auto_pr_count);
        $this->assertSame(0, (int) $plan2->draft_wo_count);
        $this->assertSame(0, WorkOrder::query()->where('sales_order_id', $so2->id)->count());
        $this->assertSame(0, PurchaseRequest::query()->where('mrp_plan_id', $plan2->id)->count());

        // ------------------------------------------------------------------
        // Wrong actor: the sales officer holds no MRP trigger permission.
        // ------------------------------------------------------------------
        $this->actingAs($this->soOfficer)
            ->postJson('/api/v1/mrp/runs', [])
            ->assertStatus(403);

        // PPC can trigger a manual recovery run (accepted for processing).
        $this->actingAs($this->ppcHead)
            ->postJson('/api/v1/mrp/runs', [])
            ->assertStatus(202);
    }

    // ------------------------------------------------------------------
    // 2. Rerun reconciles (no duplicates), WO confirm → start lifecycle
    // ------------------------------------------------------------------

    public function test_rerun_reconciles_children_and_work_order_lifecycle(): void
    {
        $product = $this->makeProduct();
        $resin = $this->makeRawItem();
        $this->createBomOverHttp($product, $resin, '2.0000');

        $so = $this->createConfirmedSo($product, '10.00');
        $planV1 = MrpPlan::query()->where('sales_order_id', $so->id)->firstOrFail();
        $prV1 = PurchaseRequest::query()->where('mrp_plan_id', $planV1->id)->firstOrFail();
        $wo = WorkOrder::query()->where('sales_order_id', $so->id)->firstOrFail();

        // ------------------------------------------------------------------
        // Stock now covers the demand → a rerun must NOT duplicate children:
        // the planned WO is reused, the now-pointless draft auto-PR retired.
        // ------------------------------------------------------------------
        $this->stockItem($resin, '1000');

        $this->actingAs($this->ppcHead)
            ->postJson('/api/v1/mrp/runs', [])
            ->assertStatus(202);

        $planV2 = MrpPlan::query()
            ->where('sales_order_id', $so->id)
            ->where('id', '!=', $planV1->id)
            ->firstOrFail();
        $this->assertSame('active', $planV2->status->value);
        $this->assertSame(2, (int) $planV2->version);
        $planV1->refresh();
        $this->assertSame('superseded', $planV1->status->value);

        // The old draft PR was cancelled — the purchasing queue never shows
        // demand that no longer exists.
        $prV1->refresh();
        $this->assertSame('cancelled', $prV1->status->value);
        $this->assertSame(
            0,
            PurchaseRequest::query()
                ->where('is_auto_generated', true)
                ->where('status', 'draft')
                ->whereHas('mrpPlan', fn ($q) => $q->where('sales_order_id', $so->id))
                ->count(),
        );

        // Still exactly ONE planned root WO for the line — reused, not duplicated.
        $this->assertSame(
            1,
            WorkOrder::query()
                ->where('sales_order_item_id', $wo->sales_order_item_id)
                ->whereNull('parent_wo_id')
                ->where('status', WorkOrderStatus::Planned->value)
                ->count(),
        );
        $wo->refresh();
        $this->assertSame($planV2->id, $wo->mrp_plan_id, 'rerun repoints the reused WO to the new plan');

        // ------------------------------------------------------------------
        // Wrong time: confirm without machine + mold is refused.
        // ------------------------------------------------------------------
        $this->actingAs($this->ppcHead)
            ->postJson("/api/v1/production/work-orders/{$wo->hash_id}/confirm", [])
            ->assertStatus(422);

        // Wrong actor: the sales officer cannot confirm work orders.
        $machine = Machine::factory()->create(['status' => 'idle']);
        $this->actingAs($this->soOfficer)
            ->postJson("/api/v1/production/work-orders/{$wo->hash_id}/confirm", [
                'machine_id' => $machine->id,
            ])
            ->assertStatus(403);

        // Wrong data: a mold for a DIFFERENT product is refused.
        $otherMold = Mold::create([
            'mold_code' => 'MD-'.substr(uniqid(), -5),
            'name' => 'Wrong product mold',
            'product_id' => $this->makeProduct()->id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100,
            'setup_time_minutes' => 10,
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000,
            'status' => 'available',
        ]);
        $this->actingAs($this->ppcHead)
            ->postJson("/api/v1/production/work-orders/{$wo->hash_id}/confirm", [
                'machine_id' => $machine->id,
                'mold_id' => $otherMold->id,
            ])
            ->assertStatus(422);

        // ------------------------------------------------------------------
        // ACT — confirm onto a compatible machine + mold: materials reserved.
        // ------------------------------------------------------------------
        $mold = Mold::create([
            'mold_code' => 'MD-'.substr(uniqid(), -5),
            'name' => 'Relay cover mold',
            'product_id' => $product->id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100,
            'setup_time_minutes' => 10,
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000,
            'status' => 'available',
        ]);
        $mold->compatibleMachines()->syncWithoutDetaching([$machine->id]);

        $this->actingAs($this->ppcHead)
            ->postJson("/api/v1/production/work-orders/{$wo->hash_id}/confirm", [
                'machine_id' => $machine->id,
                'mold_id' => $mold->id,
            ])
            ->assertOk();

        $wo->refresh();
        $this->assertSame(WorkOrderStatus::Confirmed->value, $wo->status->value);
        $this->assertSame($machine->id, $wo->machine_id);
        $this->assertSame($mold->id, $wo->mold_id);
        // Materials were reserved against stock at confirm.
        $this->assertSame(
            1,
            \App\Modules\Inventory\Models\MaterialReservation::query()
                ->where('work_order_id', $wo->id)
                ->count(),
        );

        // Double confirm is refused — already past planned.
        $this->actingAs($this->ppcHead)
            ->postJson("/api/v1/production/work-orders/{$wo->hash_id}/confirm", [
                'machine_id' => $machine->id,
                'mold_id' => $mold->id,
            ])
            ->assertStatus(409);

        // ------------------------------------------------------------------
        // ACT — production starts the WO: InProgress, machine flips to
        // running (production_manager holds the lifecycle permission).
        // ------------------------------------------------------------------
        $this->actingAs($this->productionManager)
            ->postJson("/api/v1/production/work-orders/{$wo->hash_id}/start", [])
            ->assertOk();

        $wo->refresh();
        $this->assertSame(WorkOrderStatus::InProgress->value, $wo->status->value);
        $machine->refresh();
        $this->assertSame('running', $machine->status->value ?? $machine->getRawOriginal('status'));
    }
}
