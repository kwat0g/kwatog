<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\SupplyChain\Enums\ShipmentStatus;
use App\Modules\SupplyChain\Models\Container;
use App\Modules\SupplyChain\Models\Shipment;
use App\Modules\SupplyChain\Models\ShipmentDocument;
use App\Modules\SupplyChain\Services\LandedCostService;
use App\Modules\SupplyChain\Services\ShipmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * M043 import-shipments-customs audit probe (2026-09-01 re-audit).
 *
 * These tests RECORD MEASURED BEHAVIOUR. Several of them assert the *defective*
 * state on purpose so the finding is reproducible and so a later fix turns them
 * red rather than silently changing meaning. Every such test is labelled
 * `PASS-EITHER-WAY LOCK ON A KNOWN DEFECT` in its docblock — a green run of this
 * file does NOT mean the module is healthy.
 *
 * `Zz` prefix keeps it last in the alphabetical suite order.
 */
class ZzM043ImportAuditProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    // ───────────────────────── landed cost: the money core ─────────────────

    /**
     * INVARIANT: apportionment sums EXACTLY to the total charged.
     *
     * PASS-EITHER-WAY LOCK ON A KNOWN DEFECT — asserts the measured residual.
     */
    public function test_probe_landed_cost_allocation_does_not_sum_to_the_total(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);

        // 7 equal lines and 100.00 freight: 100/7 = 14.2857…, round(2) = 14.29,
        // x7 = 100.03 — three cents MORE than was charged.
        $shipment = $this->seedShipment($user, lineCount: 7, lineTotal: '1000.00');
        $shipment->forceFill(['freight_cost' => '100.00'])->save();

        $out = app(LandedCostService::class)->calculate($shipment, 'by_value');

        $sum = '0.00';
        foreach ($out->landedCosts as $row) {
            $sum = bcadd($sum, (string) $row->total_allocated, 2);
        }
        $total = (string) $out->landed_cost_total;

        fwrite(STDERR, "\n[M043 landed-cost 7-line] header total={$total} sum(lines)={$sum} delta="
            .bcsub($sum, $total, 2)."\n");

        $this->assertSame('100.00', $total, 'header total');
        $this->assertSame('100.03', $sum, 'MEASURED: rounded lines over-allocate by 0.03');
        $this->assertNotSame($total, $sum, 'DEFECT: allocation does not reconcile to the charged total');
    }

    /**
     * Same defect in the losing direction: 3 lines under-allocate by a cent.
     *
     * PASS-EITHER-WAY LOCK ON A KNOWN DEFECT.
     */
    public function test_probe_landed_cost_three_line_residual_is_lost(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user, lineCount: 3, lineTotal: '1000.00');
        $shipment->forceFill(['freight_cost' => '100.00'])->save();

        $out = app(LandedCostService::class)->calculate($shipment, 'by_value');

        $sum = '0.00';
        foreach ($out->landedCosts as $row) {
            $sum = bcadd($sum, (string) $row->total_allocated, 2);
        }
        fwrite(STDERR, "[M043 landed-cost 3-line] total=100.00 sum={$sum}\n");
        $this->assertSame('99.99', $sum, 'MEASURED: one cent parked on no line');
    }

    /**
     * Every component rounds independently, so the per-component columns are
     * also unreconciled — the error compounds across five charge types.
     *
     * PASS-EITHER-WAY LOCK ON A KNOWN DEFECT.
     */
    public function test_probe_landed_cost_each_component_rounds_independently(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user, lineCount: 3, lineTotal: '1000.00');
        $shipment->forceFill([
            'freight_cost'   => '100.00',
            'insurance_cost' => '100.00',
            'duties_amount'  => '100.00',
            'brokerage_fee'  => '100.00',
            'other_charges'  => '100.00',
        ])->save();

        $out = app(LandedCostService::class)->calculate($shipment, 'by_value');

        $sums = ['allocated_freight' => '0.00', 'allocated_insurance' => '0.00',
            'allocated_duties' => '0.00', 'allocated_brokerage' => '0.00',
            'allocated_other' => '0.00', 'total_allocated' => '0.00'];
        foreach ($out->landedCosts as $row) {
            foreach (array_keys($sums) as $col) {
                $sums[$col] = bcadd($sums[$col], (string) $row->{$col}, 2);
            }
        }
        fwrite(STDERR, '[M043 landed-cost components] '.json_encode($sums)
            ." header={$out->landed_cost_total}\n");

        $this->assertSame('500.00', (string) $out->landed_cost_total);
        $this->assertSame('499.95', $sums['total_allocated'],
            'MEASURED: five components x one lost cent x three lines');
    }

    /**
     * INVARIANT: a zero-value / zero-quantity basis must not divide by zero.
     * MEASURED: guarded — `computeRatios()` falls back to an equal split.
     */
    public function test_probe_landed_cost_zero_basis_does_not_divide_by_zero(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);

        foreach (['by_value', 'by_quantity'] as $method) {
            $shipment = $this->seedShipment($user, lineCount: 2, lineTotal: '0.00', lineQty: '0.00');
            $shipment->forceFill(['freight_cost' => '90.00'])->save();

            $out = app(LandedCostService::class)->calculate($shipment, $method);
            $sum = '0.00';
            foreach ($out->landedCosts as $row) {
                $sum = bcadd($sum, (string) $row->total_allocated, 2);
            }
            fwrite(STDERR, "[M043 zero-basis {$method}] sum={$sum} (no DivisionByZeroError)\n");
            $this->assertSame('90.00', $sum, "equal split under {$method}");
        }
    }

    /**
     * `by_weight` is 100% DEAD CODE and has never once worked.
     *
     * `LandedCostService::getItemWeights()` declares
     * `: Illuminate\Database\Eloquent\Collection` but maps PO lines to floats.
     * `Eloquent\Collection::map()` downgrades to `Support\Collection` as soon as
     * the mapped values are not Models, so the return type is violated on EVERY
     * invocation — not only for zero weights. A `TypeError` escapes the whole
     * `DB::transaction()` closure as an uncaught 500.
     *
     * PASS-EITHER-WAY LOCK ON A KNOWN DEFECT.
     */
    public function test_probe_by_weight_allocation_always_throws_a_type_error(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        // Non-zero line values and quantities: nothing degenerate about this input.
        $shipment = $this->seedShipment($user, lineCount: 2, lineTotal: '1000.00');
        $shipment->forceFill(['freight_cost' => '100.00'])->save();

        // Second defect, same method: the weight basis column does not exist.
        $itemCols = DB::getSchemaBuilder()->getColumnListing('items');
        $weightCols = array_values(array_filter($itemCols, fn (string $c) => str_contains($c, 'weight')));
        fwrite(STDERR, '[M043 by_weight] items weight columns='.json_encode($weightCols)."\n");
        $this->assertSame([], $weightCols,
            'MEASURED: getItemWeights() reads item->net_weight / item->weight, neither of which is a column');

        $caught = null;
        try {
            app(LandedCostService::class)->calculate($shipment, 'by_weight');
        } catch (\Throwable $e) {
            $caught = $e;
        }

        fwrite(STDERR, '[M043 by_weight] '.($caught === null ? 'NO THROW' : get_class($caught).': '
            .$caught->getMessage())."\n");

        $this->assertInstanceOf(\TypeError::class, $caught,
            'MEASURED: by_weight allocation is unreachable — TypeError on every call');
        $this->assertStringContainsString('getItemWeights', $caught->getMessage());
        $this->assertSame(0, $shipment->landedCosts()->count(), 'nothing was persisted');
    }

    /**
     * INVARIANT: the apportionment basis is stated and consistent.
     * MEASURED: `manual` is an equal split, contradicting its own docblock
     * ("user enters amounts directly") — there is no per-line input at all.
     *
     * PASS-EITHER-WAY LOCK ON A KNOWN DEFECT.
     */
    public function test_probe_landed_cost_manual_method_is_an_equal_split(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        // Deliberately lopsided line values: a real manual/by-value allocation
        // would never split these evenly.
        $shipment = $this->seedShipment($user, lineCount: 2, lineTotal: '1000.00');
        $shipment->purchaseOrder->items()->orderBy('id')->first()->forceFill(['total' => '9000.00'])->save();
        $shipment->forceFill(['freight_cost' => '100.00'])->save();

        $out = app(LandedCostService::class)->calculate($shipment->fresh(), 'manual');
        $vals = $out->landedCosts->map(fn ($r) => (string) $r->total_allocated)->sort()->values()->all();
        fwrite(STDERR, '[M043 manual method] allocations='.json_encode($vals)."\n");
        $this->assertSame(['50.00', '50.00'], $vals, 'MEASURED: manual ignores line values and any operator input');
    }

    /**
     * `POST /shipments/{id}/calculate-landed-cost` 500s on EVERY request outside
     * production, and no test in the repository posts to this route.
     *
     * `calculate()` returns the shipment with `landedCosts` loaded but NOT
     * `landedCosts.shipment`; `ShipmentLandedCostResource:16` then reads
     * `$this->shipment?->hash_id`. `AppServiceProvider:237` sets
     * `Model::preventLazyLoading(! isProduction())`, so local / staging /
     * testing raise `LazyLoadingViolationException` → 500. In production the
     * guard is off, so the same line becomes a silent N+1 instead.
     *
     * PASS-EITHER-WAY LOCK ON A KNOWN DEFECT.
     */
    public function test_probe_calculate_landed_cost_endpoint_500s_outside_production(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user, lineCount: 2, lineTotal: '1000.00');

        $this->assertFalse($this->app->isProduction());
        $this->assertTrue(\Illuminate\Database\Eloquent\Model::preventsLazyLoading());

        $codes = [];
        foreach ([1, 2, 3] as $lines) {
            $s = $this->seedShipment($user, lineCount: $lines, lineTotal: '1000.00');
            $codes["lines={$lines}"] = $this->actingAs($user)->postJson(
                "/api/v1/supply-chain/shipments/{$s->hash_id}/calculate-landed-cost",
                ['allocation_method' => 'by_value'],
            )->getStatusCode();
        }
        // And with a non-zero charge, so the real allocation branch runs.
        $paid = $this->seedShipment($user, lineCount: 2, lineTotal: '1000.00');
        $paid->forceFill(['freight_cost' => '100.00'])->save();
        $codes['lines=2,freight=100'] = $this->actingAs($user)->postJson(
            "/api/v1/supply-chain/shipments/{$paid->hash_id}/calculate-landed-cost",
            ['allocation_method' => 'by_value'],
        )->getStatusCode();

        fwrite(STDERR, '[M043 calculate endpoint] '.json_encode($codes)."\n");

        $this->assertSame([
            'lines=1' => 200,
            'lines=2' => 500,
            'lines=3' => 500,
            'lines=2,freight=100' => 500,
        ], $codes, 'MEASURED: the only landed-cost endpoint 500s for any shipment with more than one PO line');

        // Name the actual exception rather than inferring it from the status.
        $multi = $this->seedShipment($user, lineCount: 2, lineTotal: '1000.00');
        $this->withoutExceptionHandling();
        $thrown = null;
        try {
            $this->actingAs($user)->postJson(
                "/api/v1/supply-chain/shipments/{$multi->hash_id}/calculate-landed-cost",
                ['allocation_method' => 'by_value'],
            );
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        fwrite(STDERR, '[M043 calculate endpoint] exception='
            .($thrown === null ? 'NONE' : get_class($thrown).': '.$thrown->getMessage())."\n");
        $this->assertNotNull($thrown, 'the 500 is a real uncaught exception');
    }

    /**
     * INVARIANT: the operator can enter duty / freight / insurance / brokerage.
     * MEASURED: no endpoint accepts them. Create and the metadata patch validate
     * a fixed allow-list and drop the cost keys, so the columns stay 0.00 and the
     * whole feature computes zero for every real shipment.
     *
     * PASS-EITHER-WAY LOCK ON A KNOWN DEFECT.
     */
    public function test_probe_no_http_path_can_enter_a_landed_cost(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user, lineCount: 2, lineTotal: '1000.00');

        // The metadata patch route with cost inputs.
        $this->actingAs($user)->patchJson(
            "/api/v1/supply-chain/shipments/{$shipment->hash_id}",
            ['freight_cost' => '5000.00', 'duties_amount' => '1200.00', 'other_charges' => '7.00'],
        )->assertOk();

        // And on create.
        $po = $this->seedPo($user, 1, '1000.00');
        $this->actingAs($user)->postJson('/api/v1/supply-chain/shipments', [
            'purchase_order_id' => $po->hash_id,
            'freight_cost'      => '5000.00',
            'duties_amount'     => '1200.00',
        ])->assertCreated();

        $shipment->refresh();
        fwrite(STDERR, "[M043 no-input-path] freight={$shipment->freight_cost} duties={$shipment->duties_amount}"
            ." landed_total=".json_encode($shipment->landed_cost_total)."\n");

        $this->assertSame('0.00', (string) $shipment->freight_cost, 'MEASURED: freight unreachable over HTTP');
        $this->assertSame('0.00', (string) $shipment->duties_amount, 'MEASURED: duty unreachable over HTTP');
        $this->assertNull($shipment->landed_cost_total, 'MEASURED: never calculated because it cannot be entered');
        $this->assertSame('0.00', (string) Shipment::query()->latest('id')->first()->freight_cost);

        // The whole feature therefore computes zero for a real shipment.
        $out = app(LandedCostService::class)->calculate($shipment->fresh(), 'by_value');
        $this->assertSame('0.00', (string) $out->landed_cost_total);
    }

    /**
     * INVARIANT: FX. Philippine Peso only per CLAUDE.md; an import carries a
     * foreign amount. MEASURED: no currency and no rate column exists anywhere
     * on shipments — there is no conversion at all, and no float rate to
     * corrupt. Recorded so the absence is deliberate and documented.
     */
    public function test_probe_no_currency_or_fx_rate_exists_on_a_shipment(): void
    {
        $cols = DB::getSchemaBuilder()->getColumnListing('shipments');
        $fx = array_values(array_filter(
            $cols,
            fn (string $c) => (bool) preg_grep('/currency|fx|exchange|forex|rate/i', [$c]),
        ));
        fwrite(STDERR, '[M043 fx] shipment currency/fx columns='.json_encode($fx)."\n");
        $this->assertSame([], $fx, 'MEASURED: no FX field — landed cost is peso-denominated by assumption');

        $poCols = DB::getSchemaBuilder()->getColumnListing('purchase_orders');
        $poFx = array_values(array_filter(
            $poCols,
            fn (string $c) => (bool) preg_grep('/currency|fx|exchange/i', [$c]),
        ));
        fwrite(STDERR, '[M043 fx] purchase_order currency/fx columns='.json_encode($poFx)."\n");
        $this->assertSame([], $poFx);
    }

    /**
     * INVARIANT: the cost reaching the GRN equals the cost computed.
     * MEASURED (static): nothing in Inventory or Accounting references landed
     * cost at all, so the computed figure never reaches GRN unit cost or
     * weighted-average cost. Asserted here structurally so the gap is a test,
     * not just a grep — without calling into the LIVE Inventory module.
     */
    public function test_probe_landed_cost_is_referenced_by_no_downstream_module(): void
    {
        $hits = [];
        foreach (['Inventory', 'Accounting', 'Purchasing'] as $module) {
            $dir = app_path("Modules/{$module}");
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($it as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $src = (string) file_get_contents($file->getPathname());
                if (preg_match('/LandedCost|landed_cost|ShipmentLandedCost/', $src)) {
                    $hits[] = $module.'/'.$file->getFilename();
                }
            }
        }
        fwrite(STDERR, '[M043 downstream] modules referencing landed cost='.json_encode($hits)."\n");
        $this->assertSame([], $hits,
            'MEASURED: landed cost is computed and stored but consumed by nothing downstream');
    }

    /**
     * `ShipmentLandedCostResource::shipment_id` reads an un-eager-loaded
     * relation. MEASURED: it throws outside production, and would be an N+1
     * (one query per allocation row) in production where the guard is off.
     *
     * PASS-EITHER-WAY LOCK ON A KNOWN DEFECT.
     */
    public function test_probe_landed_cost_resource_lazy_loads_the_shipment(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user, lineCount: 4, lineTotal: '1000.00');
        $shipment->forceFill(['freight_cost' => '100.00'])->save();
        $out = app(LandedCostService::class)->calculate($shipment, 'by_value');

        $this->assertFalse($out->landedCosts->first()->relationLoaded('shipment'),
            'MEASURED: calculate() loads landedCosts.purchaseOrderItem but not .shipment');

        $caught = null;
        try {
            \App\Modules\SupplyChain\Resources\ShipmentLandedCostResource::collection($out->landedCosts)->resolve();
        } catch (\Throwable $e) {
            $caught = $e;
        }
        fwrite(STDERR, '[M043 lazy load] '.($caught === null ? 'NO THROW' : get_class($caught))."\n");
        $this->assertNotNull($caught, 'MEASURED: serialising an allocation row throws');
        $this->assertStringContainsString('lazy load [shipment]', $caught->getMessage());
        $this->assertStringContainsString('ShipmentLandedCost', $caught->getMessage());

        // Eager-loading the relation is what the service should have done: with
        // it loaded, the same serialisation is clean.
        $out->landedCosts->load('shipment');
        $rows = \App\Modules\SupplyChain\Resources\ShipmentLandedCostResource::collection($out->landedCosts)->resolve();
        $this->assertCount(4, $rows);
        $this->assertSame($shipment->hash_id, $rows[0]['shipment_id']);
    }

    // ─────────────────────── customs evidence gate ─────────────────────────

    /**
     * INVARIANT: clearance is blocked when mandatory import documents are
     * missing. MEASURED: an entirely empty shipment — no B/L, no commercial
     * invoice, no packing list, no import entry, no BOC release, no container —
     * walks ordered → received over HTTP with six 200s.
     *
     * PASS-EITHER-WAY LOCK ON A KNOWN DEFECT.
     */
    public function test_probe_an_empty_shipment_clears_customs_and_is_received(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user, lineCount: 1, lineTotal: '1000.00');

        $codes = [];
        foreach (['shipped', 'in_transit', 'customs', 'cleared', 'received'] as $next) {
            $codes[$next] = $this->actingAs($user)->patchJson(
                "/api/v1/supply-chain/shipments/{$shipment->hash_id}/status",
                ['status' => $next],
            )->getStatusCode();
        }
        $shipment->refresh();
        fwrite(STDERR, '[M043 evidence gate] transition codes='.json_encode($codes)
            .' documents='.$shipment->documents()->count()
            .' containers='.$shipment->containers()->count()
            ." final={$shipment->status->value} clearance_date={$shipment->customs_clearance_date}\n");

        $this->assertSame(['shipped' => 200, 'in_transit' => 200, 'customs' => 200,
            'cleared' => 200, 'received' => 200], $codes,
            'MEASURED: no document is required at any customs or receipt transition');
        $this->assertSame(0, $shipment->documents()->count());
        $this->assertSame(ShipmentStatus::Received, $shipment->status);
        $this->assertNotNull($shipment->customs_clearance_date,
            'a clearance date was stamped with zero customs evidence behind it');
    }

    /**
     * INVARIANT: a document cannot be swapped after clearance.
     * MEASURED: after `received`, the API still accepts a new upload of the
     * same type and a delete of the B/L that customs was cleared against.
     *
     * PASS-EITHER-WAY LOCK ON A KNOWN DEFECT.
     */
    public function test_probe_documents_are_swappable_after_clearance_and_receipt(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user, lineCount: 1, lineTotal: '1000.00');

        $this->actingAs($user)->postJson(
            "/api/v1/supply-chain/shipments/{$shipment->hash_id}/documents",
            ['document_type' => 'bill_of_lading', 'file' => $this->realPdf('original-bl.pdf')],
        )->assertCreated();
        $original = ShipmentDocument::query()->latest('id')->firstOrFail();

        $this->advance($shipment, ShipmentStatus::Received);

        // Upload a SECOND bill of lading after receipt.
        $second = $this->actingAs($user)->postJson(
            "/api/v1/supply-chain/shipments/{$shipment->hash_id}/documents",
            ['document_type' => 'bill_of_lading', 'file' => $this->realPdf('swapped-bl.pdf')],
        );
        // And delete the one clearance actually relied on.
        $del = $this->actingAs($user)
            ->deleteJson("/api/v1/supply-chain/shipment-documents/{$original->hash_id}");

        fwrite(STDERR, "[M043 doc swap after receipt] second_upload={$second->getStatusCode()}"
            ." delete_original={$del->getStatusCode()}"
            .' bl_rows_now='.$shipment->documents()->where('document_type', 'bill_of_lading')->count()."\n");

        $this->assertSame(201, $second->getStatusCode(), 'MEASURED: no terminal-state guard on upload');
        $this->assertSame(204, $del->getStatusCode(), 'MEASURED: the cleared B/L can be deleted after receipt');
    }

    /**
     * INVARIANT: the received → GRN handoff failure is not swallowed into a log.
     * MEASURED: there is no handoff at all. `updateStatus(received)` stamps a
     * date and returns; it emits no event, writes no handoff status column,
     * queues no job and sends no notification. Contrast the sibling delivery
     * path, which has a status column, an event, an outbox row and a bottleneck.
     */
    public function test_probe_received_emits_no_handoff_of_any_kind(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user, lineCount: 1, lineTotal: '1000.00');

        \Illuminate\Support\Facades\Event::fake();
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Notification::fake();

        $this->advance($shipment, ShipmentStatus::Received);

        \Illuminate\Support\Facades\Queue::assertNothingPushed();
        \Illuminate\Support\Facades\Notification::assertNothingSent();

        $cols = DB::getSchemaBuilder()->getColumnListing('shipments');
        $handoff = array_values(array_filter($cols, fn (string $c) => str_contains($c, 'handoff')));
        fwrite(STDERR, '[M043 handoff] shipment handoff columns='.json_encode($handoff)
            .' delivery handoff columns='
            .json_encode(array_values(array_filter(
                DB::getSchemaBuilder()->getColumnListing('deliveries'),
                fn (string $c) => str_contains($c, 'handoff'),
            )))."\n");

        $this->assertSame([], $handoff,
            'MEASURED: no shipment→GRN handoff state, while deliveries carry invoice_handoff_status');
    }

    // ───────────────────────── status machine ──────────────────────────────

    /** INVARIANT: the FULL 7x7 = 49-cell transition matrix, not the happy path. */
    public function test_probe_full_transition_matrix_49_cells(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $svc = app(ShipmentService::class);

        $legal = [];
        $refused = [];
        $cells = 0;

        foreach (ShipmentStatus::cases() as $from) {
            foreach (ShipmentStatus::cases() as $to) {
                $cells++;
                $shipment = $this->seedShipment($user, lineCount: 1, lineTotal: '100.00');
                $shipment->forceFill(['status' => $from->value])->save();
                try {
                    $svc->updateStatus($shipment->fresh(), $to);
                    $legal[] = "{$from->value}->{$to->value}";
                } catch (BusinessRuleException) {
                    $refused[] = "{$from->value}->{$to->value}";
                }
            }
        }

        fwrite(STDERR, "[M043 matrix] cells={$cells} accepted=".count($legal).' refused='.count($refused)
            .' accepted='.json_encode($legal)."\n");

        $this->assertSame(49, $cells);
        // ordered/shipped/in_transit/customs/cleared each advance one step and
        // may cancel; received and cancelled are closed.
        sort($legal);
        $this->assertSame([
            'cleared->cancelled', 'cleared->received',
            'customs->cancelled', 'customs->cleared',
            'in_transit->cancelled', 'in_transit->customs',
            'ordered->cancelled', 'ordered->shipped',
            'shipped->cancelled', 'shipped->in_transit',
        ], $legal, 'exactly 10 of 49 cells are legal; the other 39 are refused');
        $this->assertCount(39, $refused);
    }

    /** INVARIANT: clear customs twice. MEASURED: refused. */
    public function test_probe_clearing_customs_twice_is_refused(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user, lineCount: 1, lineTotal: '100.00');
        $shipment->forceFill(['status' => ShipmentStatus::Customs->value])->save();

        $first = $this->actingAs($user)->patchJson(
            "/api/v1/supply-chain/shipments/{$shipment->hash_id}/status", ['status' => 'cleared']);
        $second = $this->actingAs($user)->patchJson(
            "/api/v1/supply-chain/shipments/{$shipment->hash_id}/status", ['status' => 'cleared']);

        fwrite(STDERR, "[M043 clear twice] first={$first->getStatusCode()} second={$second->getStatusCode()}\n");
        $first->assertOk();
        $this->assertSame(422, $second->getStatusCode(), 'second clearance refused');
    }

    /** INVARIANT: receive an uncleared shipment. MEASURED: refused at every non-cleared state. */
    public function test_probe_receiving_an_uncleared_shipment_is_refused(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        foreach (['ordered', 'shipped', 'in_transit', 'customs'] as $from) {
            $shipment = $this->seedShipment($user, lineCount: 1, lineTotal: '100.00');
            $shipment->forceFill(['status' => $from])->save();
            $r = $this->actingAs($user)->patchJson(
                "/api/v1/supply-chain/shipments/{$shipment->hash_id}/status", ['status' => 'received']);
            fwrite(STDERR, "[M043 receive uncleared] {$from}->received = {$r->getStatusCode()}\n");
            $this->assertSame(422, $r->getStatusCode(), "{$from} must not jump to received");
        }
    }

    /** INVARIANT: cancel a received shipment. MEASURED: refused. */
    public function test_probe_cancelling_a_received_shipment_is_refused(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user, lineCount: 1, lineTotal: '100.00');
        $shipment->forceFill(['status' => ShipmentStatus::Received->value])->save();

        $r = $this->actingAs($user)->patchJson(
            "/api/v1/supply-chain/shipments/{$shipment->hash_id}/status", ['status' => 'cancelled']);
        fwrite(STDERR, "[M043 cancel received] = {$r->getStatusCode()}\n");
        $this->assertSame(422, $r->getStatusCode());
    }

    /**
     * INVARIANT: cost / quantity / trade metadata cannot be edited after
     * clearance and receipt. MEASURED: `PATCH /shipments/{id}` rewrites the
     * B/L number, carrier, vessel, container number, ETD and ETA of a RECEIVED
     * shipment, and accepts an ETA before its ETD (the create-time rule is not
     * carried over). Containers are editable too.
     *
     * PASS-EITHER-WAY LOCK ON A KNOWN DEFECT.
     */
    public function test_probe_a_received_shipment_is_still_fully_editable(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user, lineCount: 1, lineTotal: '100.00');
        $container = Container::create([
            'shipment_id' => $shipment->id, 'container_number' => 'TCLU1111111',
            'size' => '40ft', 'type' => 'dry', 'gross_weight_kg' => '20000.00',
        ]);
        $this->advance($shipment, ShipmentStatus::Received);

        $meta = $this->actingAs($user)->patchJson("/api/v1/supply-chain/shipments/{$shipment->hash_id}", [
            'bl_number'        => 'REWRITTEN',
            'carrier'          => 'REWRITTEN CARRIER',
            'container_number' => 'REWRITTEN',
            'etd'              => '2026-12-31',
            'eta'              => '2026-01-01',   // ETA before ETD
        ]);
        $cont = $this->actingAs($user)->putJson("/api/v1/supply-chain/containers/{$container->hash_id}", [
            'container_number' => 'REWRITTEN2', 'gross_weight_kg' => '1.00',
        ]);

        $shipment->refresh();
        fwrite(STDERR, "[M043 post-receipt edit] meta={$meta->getStatusCode()} container={$cont->getStatusCode()}"
            ." bl={$shipment->bl_number} etd={$shipment->etd?->toDateString()}"
            ." eta={$shipment->eta?->toDateString()}\n");

        $this->assertSame(200, $meta->getStatusCode(), 'MEASURED: no terminal-state guard on updateMeta');
        $this->assertSame('REWRITTEN', $shipment->bl_number);
        $this->assertTrue($shipment->eta->lessThan($shipment->etd),
            'MEASURED: ETA-after-ETD is enforced on create but not on update');
        $this->assertSame(200, $cont->getStatusCode(), 'MEASURED: containers of a received shipment are editable');
    }

    /**
     * INVARIANT: the record is immutable after receipt (Eloquent / raw SQL /
     * delete / pg_trigger). MEASURED: fully mutable, zero triggers. Only a
     * service-level guard blocks `DELETE /shipments/{id}` for `received`;
     * Eloquent and SQL bypass it.
     *
     * PASS-EITHER-WAY LOCK ON A KNOWN DEFECT.
     */
    public function test_probe_a_received_shipment_is_not_immutable(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user, lineCount: 1, lineTotal: '100.00');
        $this->advance($shipment, ShipmentStatus::Received);
        $id = $shipment->id;

        // 1. Eloquent update of the identity column.
        $shipment->forceFill(['shipment_number' => 'SHP-HACKED'])->save();
        $this->assertSame('SHP-HACKED', (string) Shipment::query()->find($id)->shipment_number,
            'MEASURED: Eloquent rewrote the shipment number of a received shipment');

        // 2. Raw SQL rewrite of the customs record.
        DB::table('shipments')->where('id', $id)->update([
            'shipment_number'        => 'HACKED',
            'customs_clearance_date' => '1999-01-01',
            'status'                 => 'cleared',
        ]);
        $row = DB::table('shipments')->where('id', $id)->first();
        $this->assertSame('HACKED', $row->shipment_number, 'MEASURED: raw SQL rewrote the compliance record');
        $this->assertSame('cleared', $row->status, 'MEASURED: raw SQL walked the status backwards');

        // 3. Hard delete.
        $deleted = DB::table('shipments')->where('id', $id)->delete();
        $this->assertSame(1, $deleted, 'MEASURED: the row can be hard-deleted');

        // 4. pg_trigger count.
        $triggers = DB::selectOne("
            select count(*) as n from pg_trigger t
            join pg_class c on c.oid = t.tgrelid
            where not t.tgisinternal
              and c.relname in ('shipments','shipment_documents','containers','shipment_landed_costs')
        ");
        fwrite(STDERR, "[M043 immutability] pg_trigger count on shipment tables={$triggers->n}\n");
        $this->assertSame(0, (int) $triggers->n, 'MEASURED: no database-level protection at all');
    }

    /** MEASURED: the ONE guard that does exist — the service refuses to archive a received shipment. */
    public function test_probe_archiving_a_received_shipment_is_refused(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user, lineCount: 1, lineTotal: '100.00');
        $this->advance($shipment, ShipmentStatus::Received);

        $r = $this->actingAs($user)->deleteJson("/api/v1/supply-chain/shipments/{$shipment->hash_id}");
        fwrite(STDERR, "[M043 archive received] = {$r->getStatusCode()}\n");
        $this->assertSame(422, $r->getStatusCode());

        // But a CLEARED shipment — a completed customs record — can be archived.
        $cleared = $this->seedShipment($user, lineCount: 1, lineTotal: '100.00');
        $this->advance($cleared, ShipmentStatus::Cleared);
        $r2 = $this->actingAs($user)->deleteJson("/api/v1/supply-chain/shipments/{$cleared->hash_id}");
        fwrite(STDERR, "[M043 archive cleared] = {$r2->getStatusCode()}\n");
        $this->assertSame(204, $r2->getStatusCode(),
            'MEASURED: a cleared customs record can be archived; only `received` is protected');
    }

    // ─────────────────────────── attachments ───────────────────────────────

    /**
     * INVARIANT: MIME validated server-side from real bytes, not the filename.
     * Uses a real UploadedFile with real bytes (UploadedFile::fake() sniffs the
     * NAME, which produces a false-positive bypass).
     */
    public function test_probe_document_mime_is_validated_from_real_bytes(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user, lineCount: 1, lineTotal: '100.00');

        // (a) real PHP bytes wearing a .pdf name → must be refused.
        $evil = $this->realFile('exploit.pdf', "<?php echo 'pwned'; ?>\n");
        $a = $this->actingAs($user)->postJson(
            "/api/v1/supply-chain/shipments/{$shipment->hash_id}/documents",
            ['document_type' => 'bill_of_lading', 'file' => $evil]);

        // (b) real PDF bytes wearing a .png name → mimes: uses the guessed
        //     extension from content, so this is accepted; record which.
        $b = $this->actingAs($user)->postJson(
            "/api/v1/supply-chain/shipments/{$shipment->hash_id}/documents",
            ['document_type' => 'commercial_invoice', 'file' => $this->realFile('mislabelled.png', "%PDF-1.4\n%%EOF\n")]);

        // (c) a genuine PDF → accepted.
        $c = $this->actingAs($user)->postJson(
            "/api/v1/supply-chain/shipments/{$shipment->hash_id}/documents",
            ['document_type' => 'packing_list', 'file' => $this->realPdf('good.pdf')]);

        fwrite(STDERR, "[M043 mime] php-bytes-as-pdf={$a->getStatusCode()}"
            ." pdf-bytes-as-png={$b->getStatusCode()} real-pdf={$c->getStatusCode()}\n");

        $this->assertSame(422, $a->getStatusCode(), 'PHP bytes must be refused whatever the extension');
        $this->assertSame(201, $c->getStatusCode(), 'a real PDF is accepted');
    }

    /** INVARIANT: random stored filename, outside the web root, traversal refused. */
    public function test_probe_stored_filename_is_random_outside_web_root_and_traversal_safe(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user, lineCount: 1, lineTotal: '100.00');

        $this->actingAs($user)->postJson(
            "/api/v1/supply-chain/shipments/{$shipment->hash_id}/documents",
            ['document_type' => 'bill_of_lading',
                'file' => $this->realFile('../../../../etc/passwd.pdf', "%PDF-1.4\n%%EOF\n")],
        )->assertCreated();

        $doc = ShipmentDocument::query()->latest('id')->firstOrFail();
        fwrite(STDERR, "[M043 storage] path={$doc->file_path} original={$doc->original_filename}\n");

        $this->assertStringStartsWith("shipments/{$shipment->id}/", $doc->file_path);
        $this->assertStringNotContainsString('..', $doc->file_path, 'traversal neutralised in the stored path');
        $this->assertStringNotContainsString('passwd', $doc->file_path, 'stored name is random, not client-supplied');
        $this->assertMatchesRegularExpression('#^shipments/\d+/[A-Za-z0-9]{20,}\.pdf$#', $doc->file_path);
        Storage::disk('local')->assertExists($doc->file_path);

        // 'local' disk root is storage/app — never under public/.
        $root = config('filesystems.disks.local.root');
        fwrite(STDERR, "[M043 storage] local disk root={$root}\n");
        $this->assertStringNotContainsString('/public', (string) $root);
    }

    /**
     * INVARIANT: a filename longer than the column width.
     * MEASURED: 500. `original_filename` is varchar(255), the upload validator
     * has no length rule on the client filename, and `uploadDocument()` writes
     * `$file->getClientOriginalName()` verbatim — so a 304-character name
     * reaches Postgres as SQLSTATE 22001. The file is stored first, so the
     * catch/cleanup path fires and the blob is removed, but the caller gets an
     * unhandled 500 rather than a validation error.
     *
     * PASS-EITHER-WAY LOCK ON A KNOWN DEFECT.
     */
    public function test_probe_overlength_original_filename_is_a_500(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user, lineCount: 1, lineTotal: '100.00');

        $long = str_repeat('b', 300).'.pdf';   // 304 chars into varchar(255)
        $r = $this->actingAs($user)->postJson(
            "/api/v1/supply-chain/shipments/{$shipment->hash_id}/documents",
            ['document_type' => 'bill_of_lading', 'file' => $this->realFile($long, "%PDF-1.4\n%%EOF\n")]);

        fwrite(STDERR, '[M043 long filename] len='.strlen($long)." status={$r->getStatusCode()}\n");

        $this->assertSame(500, $r->getStatusCode(),
            'MEASURED: an over-length client filename reaches Postgres as an unhandled 500');
        $this->assertSame(0, $shipment->documents()->count(), 'no row was written');
    }

    /**
     * INVARIANT: a document survives its shipment being archived.
     * MEASURED: it does NOT. `ShipmentService::delete()` soft-deletes the
     * shipment and then destroys every document FILE after commit, while the
     * document ROWS stay live — the exact inverse of the delivery-proof defect,
     * and unrecoverable because restore cannot bind anyway.
     *
     * PASS-EITHER-WAY LOCK ON A KNOWN DEFECT.
     */
    public function test_probe_archiving_a_shipment_orphans_its_document_rows(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user, lineCount: 1, lineTotal: '100.00');
        $this->actingAs($user)->postJson(
            "/api/v1/supply-chain/shipments/{$shipment->hash_id}/documents",
            ['document_type' => 'bill_of_lading', 'file' => $this->realPdf('bl.pdf')])->assertCreated();
        $doc = ShipmentDocument::query()->latest('id')->firstOrFail();
        Storage::disk('local')->assertExists($doc->file_path);

        $this->actingAs($user)
            ->deleteJson("/api/v1/supply-chain/shipments/{$shipment->hash_id}")
            ->assertStatus(204);

        $doc->refresh();
        $fileGone = ! Storage::disk('local')->exists($doc->file_path);
        fwrite(STDERR, '[M043 orphan] shipment trashed='.(Shipment::withTrashed()->find($shipment->id)->trashed() ? 'yes' : 'no')
            .' document row trashed='.($doc->trashed() ? 'yes' : 'no')
            .' file deleted='.($fileGone ? 'yes' : 'no')."\n");

        $this->assertTrue(Shipment::withTrashed()->find($shipment->id)->trashed());
        $this->assertFalse($doc->trashed(), 'MEASURED: document metadata stays ACTIVE');
        $this->assertTrue($fileGone, 'MEASURED: but its file was destroyed — live row, dead file');
    }

    /**
     * INVARIANT: restore binds `withTrashed()`.
     * MEASURED: 404 for every one of shipment / document / container. Vehicles
     * is the only restore route in this module that declares `withTrashed()`.
     *
     * PASS-EITHER-WAY LOCK ON A KNOWN DEFECT.
     */
    public function test_probe_restore_routes_cannot_bind_an_archived_row(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user, lineCount: 1, lineTotal: '100.00');
        $doc = ShipmentDocument::create([
            'shipment_id' => $shipment->id, 'document_type' => 'bill_of_lading',
            'file_path' => "shipments/{$shipment->id}/x.pdf", 'original_filename' => 'x.pdf',
            'uploaded_by' => $user->id, 'uploaded_at' => now(),
        ]);
        $container = Container::create([
            'shipment_id' => $shipment->id, 'container_number' => 'TCLU9999999',
            'size' => '40ft', 'type' => 'dry',
        ]);

        $doc->delete();
        $container->delete();
        $shipment->delete();

        $codes = [
            'shipment'  => $this->actingAs($user)->patchJson("/api/v1/supply-chain/shipments/{$shipment->hash_id}/restore")->getStatusCode(),
            'document'  => $this->actingAs($user)->patchJson("/api/v1/supply-chain/shipment-documents/{$doc->hash_id}/restore")->getStatusCode(),
            'container' => $this->actingAs($user)->patchJson("/api/v1/supply-chain/containers/{$container->hash_id}/restore")->getStatusCode(),
        ];
        fwrite(STDERR, '[M043 restore binding] '.json_encode($codes)."\n");

        $this->assertSame(['shipment' => 404, 'document' => 404, 'container' => 404], $codes,
            'MEASURED: 3 of the 3 import restore routes are unreachable for their only valid target');
        $this->assertTrue(Shipment::withTrashed()->find($shipment->id)->trashed(), 'still archived');
    }

    /**
     * INVARIANT: the document download's Content-Disposition cannot be forged
     * from the client's filename. `original_filename` is stored verbatim and
     * interpolated with `sprintf('inline; filename="%s"')`, so a name containing
     * a double quote closes the parameter early and injects the rest of the
     * header value — the identical defect `DeliveryProofController` was repaired
     * for hours earlier in this same module directory (commit 38663a81).
     */
    public function test_probe_document_download_content_disposition_injection(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user, lineCount: 1, lineTotal: '100.00');

        $this->actingAs($user)->postJson(
            "/api/v1/supply-chain/shipments/{$shipment->hash_id}/documents",
            ['document_type' => 'bill_of_lading',
                'file' => $this->realFile('bl".pdf', "%PDF-1.4\n%%EOF\n")],
        )->assertCreated();
        $doc = ShipmentDocument::query()->latest('id')->firstOrFail();

        $r = $this->actingAs($user)
            ->get("/api/v1/supply-chain/shipment-documents/{$doc->hash_id}/download");
        $header = (string) $r->headers->get('Content-Disposition');
        fwrite(STDERR, "[M043 content-disposition] stored_name={$doc->original_filename}"
            ." header={$header}\n");

        $this->assertSame(200, $r->getStatusCode());
        // A well-formed disposition has exactly two quotes around one filename.
        $this->assertSame(2, substr_count($header, '"'),
            'MEASURED: the client filename injects an extra quote pair into the header');
    }

    // ─────────────────────── archived / soft-deleted rows ──────────────────

    /**
     * INVARIANT: a soft-deleted PO / vendor / item / shipment behaves
     * consistently across create, list, show, PDF and landed cost.
     */
    public function test_probe_soft_deleted_rows_across_every_surface(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);

        // (a) archived PO: `exists:purchase_orders,id` does NOT exclude trashed
        //     rows, so validation passes and the service's findOrFail 404s.
        $po = $this->seedPo($user, 1, '1000.00');
        $po->delete();
        $createOnTrashedPo = $this->actingAs($user)->postJson('/api/v1/supply-chain/shipments', [
            'purchase_order_id' => $po->hash_id,
        ]);

        // (b) archived shipment across list / show / PDFs / landed cost.
        $shipment = $this->seedShipment($user, lineCount: 2, lineTotal: '500.00');
        $shipment->forceFill(['freight_cost' => '10.00'])->save();
        $shipment->delete();

        $list = $this->actingAs($user)->getJson('/api/v1/supply-chain/shipments');
        $listIds = collect($list->json('data'))->pluck('id')->all();
        $codes = [
            'create_on_trashed_po' => $createOnTrashedPo->getStatusCode(),
            'show'                 => $this->actingAs($user)->getJson("/api/v1/supply-chain/shipments/{$shipment->hash_id}")->getStatusCode(),
            'packing_list'         => $this->actingAs($user)->get("/api/v1/supply-chain/shipments/{$shipment->hash_id}/packing-list")->getStatusCode(),
            'commercial_invoice'   => $this->actingAs($user)->get("/api/v1/supply-chain/shipments/{$shipment->hash_id}/commercial-invoice")->getStatusCode(),
            'landed_cost'          => $this->actingAs($user)->postJson("/api/v1/supply-chain/shipments/{$shipment->hash_id}/calculate-landed-cost", [])->getStatusCode(),
            'status'               => $this->actingAs($user)->patchJson("/api/v1/supply-chain/shipments/{$shipment->hash_id}/status", ['status' => 'shipped'])->getStatusCode(),
            'documents'            => $this->actingAs($user)->getJson("/api/v1/supply-chain/shipments/{$shipment->hash_id}/containers")->getStatusCode(),
        ];
        fwrite(STDERR, '[M043 soft-deleted] '.json_encode($codes)
            .' in_list='.(in_array($shipment->hash_id, $listIds, true) ? 'YES' : 'no')."\n");

        $this->assertNotContains($shipment->hash_id, $listIds, 'archived shipment is out of the default list');
        foreach ($codes as $surface => $code) {
            $this->assertSame(404, $code, "archived row must 404 on {$surface}");
        }

        // (c) archived vendor / item behind a LIVE shipment: the PDFs must not 500.
        $live = $this->seedShipment($user, lineCount: 1, lineTotal: '500.00');
        $live->purchaseOrder->vendor->delete();
        Item::query()->firstOrFail()->delete();
        $pl = $this->actingAs($user)->get("/api/v1/supply-chain/shipments/{$live->hash_id}/packing-list");
        $ci = $this->actingAs($user)->get("/api/v1/supply-chain/shipments/{$live->hash_id}/commercial-invoice");
        fwrite(STDERR, "[M043 soft-deleted vendor/item] packing_list={$pl->getStatusCode()}"
            ." commercial_invoice={$ci->getStatusCode()}\n");
        $this->assertNotSame(500, $pl->getStatusCode(), 'archived vendor must not 500 the packing list');
        $this->assertNotSame(500, $ci->getStatusCode(), 'archived item must not 500 the commercial invoice');
    }

    // ───────────────── PO state gate / incoterm / validation ───────────────

    /**
     * INVARIANT: a shipment cannot be created against a terminal or invalid PO.
     * MEASURED: it can — draft, pending approval, rejected, cancelled, received
     * and closed POs all produce a live `ordered` shipment, while GRN receiving
     * accepts only approved / sent / partially_received.
     *
     * PASS-EITHER-WAY LOCK ON A KNOWN DEFECT.
     */
    public function test_probe_shipments_can_be_created_against_terminal_pos(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);

        $accepted = [];
        foreach (PurchaseOrderStatus::cases() as $status) {
            $po = $this->seedPo($user, 1, '1000.00');
            $po->forceFill(['status' => $status->value])->save();
            $r = $this->actingAs($user)->postJson('/api/v1/supply-chain/shipments', [
                'purchase_order_id' => $po->hash_id,
            ]);
            if ($r->getStatusCode() === 201) {
                $accepted[] = $status->value;
            }
        }
        fwrite(STDERR, '[M043 PO gate] PO states accepted for a new shipment='.json_encode($accepted)."\n");

        $this->assertSame(
            array_map(fn (PurchaseOrderStatus $s) => $s->value, PurchaseOrderStatus::cases()),
            $accepted,
            'MEASURED: every PO status is accepted, including cancelled/closed/received',
        );
    }

    /**
     * INVARIANT: the submitted Incoterm is persisted.
     * MEASURED: validated, echoed as null, and silently dropped by
     * `ShipmentService::create()`. The generated customs PDFs then render the
     * PO's Incoterm instead.
     *
     * PASS-EITHER-WAY LOCK ON A KNOWN DEFECT.
     */
    public function test_probe_submitted_incoterm_is_silently_discarded(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $po = $this->seedPo($user, 1, '1000.00');
        $po->forceFill(['incoterm' => 'FOB'])->save();

        $r = $this->actingAs($user)->postJson('/api/v1/supply-chain/shipments', [
            'purchase_order_id' => $po->hash_id,
            'incoterm'          => 'DDP',
        ])->assertCreated();

        $stored = Shipment::query()->latest('id')->firstOrFail();
        fwrite(STDERR, "[M043 incoterm] submitted=DDP response=".json_encode($r->json('data.incoterm'))
            .' stored='.json_encode($stored->incoterm?->value)." po=FOB\n");

        $this->assertNull($r->json('data.incoterm'), 'MEASURED: 201 with the value silently gone');
        $this->assertNull($stored->incoterm, 'MEASURED: the column stays null');

        // The metadata patch cannot set it either.
        $this->actingAs($user)->patchJson("/api/v1/supply-chain/shipments/{$stored->hash_id}",
            ['incoterm' => 'DDP'])->assertOk();
        $this->assertNull($stored->fresh()->incoterm, 'MEASURED: updateMeta drops it too');
    }

    /**
     * INVARIANT: money / quantity FormRequests reject the seven poison values.
     * `containers.gross_weight_kg` is numeric(10,2), `volume_cbm` numeric(8,3),
     * both validated with the bare `numeric|min:0` shape found in eight sibling
     * modules.
     *
     * ONE VALUE PER TEST ON PURPOSE. A 22003 overflow aborts the surrounding
     * `RefreshDatabase` transaction, so every request after the first 500 in a
     * shared test returns a cascade 500 that is a harness artifact, not a
     * measurement. The first version of this probe reported `-1 => 500` and
     * `0 => 500` for exactly that reason.
     *
     * @dataProvider containerNumericProvider
     */
    public function test_probe_container_numeric_edge_value(string $field, string $value, string $expectation): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user, lineCount: 1, lineTotal: '100.00');

        $r = $this->actingAs($user)->postJson(
            "/api/v1/supply-chain/shipments/{$shipment->hash_id}/containers",
            ['container_number' => 'TCLU0000001', $field => $value],
        );
        $code = $r->getStatusCode();
        $stored = $code === 201
            ? (string) Container::query()->latest('id')->first()->{$field}
            : null;

        fwrite(STDERR, "[M043 numeric edge] {$field}={$value} => {$code}"
            .($stored !== null ? " stored={$stored}" : '')
            .' expectation='.$expectation."\n");

        $this->assertSame($expectation, $code.($stored !== null ? ':'.$stored : ''),
            "{$field}={$value}");
    }

    /** @return array<string, array{string, string, string}> */
    public static function containerNumericProvider(): array
    {
        return [
            // MEASURED-GOOD
            'gross -1 refused'        => ['gross_weight_kg', '-1', '422'],
            'gross 0 accepted'        => ['gross_weight_kg', '0', '201:0.00'],
            'vol -1 refused'          => ['volume_cbm', '-1', '422'],
            // MEASURED-DEFECTIVE — silent precision loss
            'gross 1.999 -> 2.00'     => ['gross_weight_kg', '1.999', '201:2.00'],
            'gross 10.00005 -> 10.00' => ['gross_weight_kg', '10.00005', '201:10.00'],
            'vol 1.9999 -> 2.000'     => ['volume_cbm', '1.9999', '201:2.000'],
            // MEASURED-DEFECTIVE — scientific notation silently accepted
            'gross 1e3 -> 1000.00'    => ['gross_weight_kg', '1e3', '201:1000.00'],
            // MEASURED-DEFECTIVE — 22003 numeric overflow escapes as a 500
            'gross 1e17 overflows'    => ['gross_weight_kg', '1e17', '500'],
            'gross 1e20 overflows'    => ['gross_weight_kg', '1e20', '500'],
            'vol 1e17 overflows'      => ['volume_cbm', '1e17', '500'],
            'vol 1e20 overflows'      => ['volume_cbm', '1e20', '500'],
        ];
    }

    // ───────────────────────── access control ──────────────────────────────

    /** INVARIANT: every endpoint is permission-gated, including list and options. */
    public function test_probe_permission_gate_on_every_import_endpoint(): void
    {
        $owner = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($owner, lineCount: 1, lineTotal: '100.00');
        $container = Container::create([
            'shipment_id' => $shipment->id, 'container_number' => 'TCLU5555555',
            'size' => '40ft', 'type' => 'dry',
        ]);
        $doc = ShipmentDocument::create([
            'shipment_id' => $shipment->id, 'document_type' => 'bill_of_lading',
            'file_path' => "shipments/{$shipment->id}/y.pdf", 'original_filename' => 'y.pdf',
            'uploaded_by' => $owner->id, 'uploaded_at' => now(),
        ]);

        $s = $shipment->hash_id;
        $c = $container->hash_id;
        $d = $doc->hash_id;
        $endpoints = [
            ['get', "/api/v1/supply-chain/shipments/options"],
            ['get', "/api/v1/supply-chain/shipments"],
            ['get', "/api/v1/supply-chain/shipments/{$s}"],
            ['post', "/api/v1/supply-chain/shipments"],
            ['patch', "/api/v1/supply-chain/shipments/{$s}/status"],
            ['patch', "/api/v1/supply-chain/shipments/{$s}"],
            ['delete', "/api/v1/supply-chain/shipments/{$s}"],
            ['patch', "/api/v1/supply-chain/shipments/{$s}/restore"],
            ['post', "/api/v1/supply-chain/shipments/{$s}/calculate-landed-cost"],
            ['get', "/api/v1/supply-chain/shipments/{$s}/packing-list"],
            ['get', "/api/v1/supply-chain/shipments/{$s}/commercial-invoice"],
            ['post', "/api/v1/supply-chain/shipments/{$s}/documents"],
            ['get', "/api/v1/supply-chain/shipment-documents/{$d}/download"],
            ['delete', "/api/v1/supply-chain/shipment-documents/{$d}"],
            ['patch', "/api/v1/supply-chain/shipment-documents/{$d}/restore"],
            ['get', "/api/v1/supply-chain/shipments/{$s}/containers"],
            ['post', "/api/v1/supply-chain/shipments/{$s}/containers"],
            ['get', "/api/v1/supply-chain/containers/{$c}"],
            ['put', "/api/v1/supply-chain/containers/{$c}"],
            ['delete', "/api/v1/supply-chain/containers/{$c}"],
            ['patch', "/api/v1/supply-chain/containers/{$c}/restore"],
        ];

        $nobody = $this->userWith([]);
        $leaks = [];
        foreach ($endpoints as [$verb, $url]) {
            $code = $this->actingAs($nobody)->{$verb.'Json'}($url)->getStatusCode();
            if ($code !== 403) {
                $leaks[] = "{$verb} {$url} => {$code}";
            }
        }
        fwrite(STDERR, '[M043 permission gate] endpoints='.count($endpoints)
            .' not-403='.json_encode($leaks)."\n");
        $this->assertSame([], $leaks, 'every import endpoint must 403 a permissionless user');
    }

    /**
     * INVARIANT: every endpoint 401s an unauthenticated caller. Kept in its own
     * test because `actingAs()` persists for the remainder of a test method —
     * reusing the permission-gate test's session made every request here read
     * 403, which looked like a missing auth guard and was not.
     */
    public function test_probe_auth_gate_on_every_import_endpoint(): void
    {
        $owner = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($owner, lineCount: 1, lineTotal: '100.00');
        $container = Container::create([
            'shipment_id' => $shipment->id, 'container_number' => 'TCLU4444444',
            'size' => '40ft', 'type' => 'dry',
        ]);
        $doc = ShipmentDocument::create([
            'shipment_id' => $shipment->id, 'document_type' => 'bill_of_lading',
            'file_path' => "shipments/{$shipment->id}/z.pdf", 'original_filename' => 'z.pdf',
            'uploaded_by' => $owner->id, 'uploaded_at' => now(),
        ]);

        $unauth = [];
        foreach ($this->importEndpoints($shipment->hash_id, $container->hash_id, $doc->hash_id) as [$verb, $url]) {
            $code = $this->{$verb.'Json'}($url)->getStatusCode();
            if ($code !== 401) {
                $unauth[] = "{$verb} {$url} => {$code}";
            }
        }
        fwrite(STDERR, '[M043 auth gate] not-401='.json_encode($unauth)."\n");
        $this->assertSame([], $unauth);
    }

    /** @return array<int, array{string, string}> */
    private function importEndpoints(string $s, string $c, string $d): array
    {
        return [
            ['get', '/api/v1/supply-chain/shipments/options'],
            ['get', '/api/v1/supply-chain/shipments'],
            ['get', "/api/v1/supply-chain/shipments/{$s}"],
            ['post', '/api/v1/supply-chain/shipments'],
            ['patch', "/api/v1/supply-chain/shipments/{$s}/status"],
            ['patch', "/api/v1/supply-chain/shipments/{$s}"],
            ['delete', "/api/v1/supply-chain/shipments/{$s}"],
            ['patch', "/api/v1/supply-chain/shipments/{$s}/restore"],
            ['post', "/api/v1/supply-chain/shipments/{$s}/calculate-landed-cost"],
            ['get', "/api/v1/supply-chain/shipments/{$s}/packing-list"],
            ['get', "/api/v1/supply-chain/shipments/{$s}/commercial-invoice"],
            ['post', "/api/v1/supply-chain/shipments/{$s}/documents"],
            ['get', "/api/v1/supply-chain/shipment-documents/{$d}/download"],
            ['delete', "/api/v1/supply-chain/shipment-documents/{$d}"],
            ['patch', "/api/v1/supply-chain/shipment-documents/{$d}/restore"],
            ['get', "/api/v1/supply-chain/shipments/{$s}/containers"],
            ['post', "/api/v1/supply-chain/shipments/{$s}/containers"],
            ['get', "/api/v1/supply-chain/containers/{$c}"],
            ['put', "/api/v1/supply-chain/containers/{$c}"],
            ['delete', "/api/v1/supply-chain/containers/{$c}"],
            ['patch', "/api/v1/supply-chain/containers/{$c}/restore"],
        ];
    }

    /**
     * INVARIANT: `impex_officer` — the seeded role that exists for exactly this
     * module — can complete an import end to end. Three sibling modules gated a
     * route on a permission no relevant role held.
     */
    public function test_probe_impex_officer_completes_an_import_end_to_end(): void
    {
        $impex = User::factory()->withRole('impex_officer')->create();
        $this->assertTrue($impex->hasPermission('supply_chain.shipments.manage'),
            'the seeded impex_officer must hold the manage permission');

        $po = $this->seedPo($impex, 2, '1000.00');
        $po->forceFill(['status' => 'sent'])->save();

        $steps = [];

        $steps['options'] = $this->actingAs($impex)
            ->getJson('/api/v1/supply-chain/shipments/options')->getStatusCode();

        $create = $this->actingAs($impex)->postJson('/api/v1/supply-chain/shipments', [
            'purchase_order_id' => $po->hash_id,
            'carrier'           => 'ONE',
            'vessel'            => 'MV OGAMI',
            'bl_number'         => 'OOLU12345678',
            'etd'               => '2026-09-05',
            'eta'               => '2026-09-20',
            'incoterm'          => 'CIF',
        ]);
        $steps['create'] = $create->getStatusCode();
        $sid = $create->json('data.id');

        foreach (['bill_of_lading', 'commercial_invoice', 'packing_list'] as $type) {
            $steps['upload_'.$type] = $this->actingAs($impex)->postJson(
                "/api/v1/supply-chain/shipments/{$sid}/documents",
                ['document_type' => $type, 'file' => $this->realPdf($type.'.pdf')],
            )->getStatusCode();
        }

        $steps['add_container'] = $this->actingAs($impex)->postJson(
            "/api/v1/supply-chain/shipments/{$sid}/containers",
            ['container_number' => 'TCLU7654321', 'size' => '40ft', 'type' => 'dry',
                'gross_weight_kg' => '25400.00', 'net_weight_kg' => '24000.00', 'volume_cbm' => '67.500'],
        )->getStatusCode();

        $steps['list_containers'] = $this->actingAs($impex)
            ->getJson("/api/v1/supply-chain/shipments/{$sid}/containers")->getStatusCode();

        foreach (['shipped', 'in_transit', 'customs', 'cleared', 'received'] as $next) {
            $steps['status_'.$next] = $this->actingAs($impex)->patchJson(
                "/api/v1/supply-chain/shipments/{$sid}/status", ['status' => $next])->getStatusCode();
        }

        $steps['landed_cost'] = $this->actingAs($impex)->postJson(
            "/api/v1/supply-chain/shipments/{$sid}/calculate-landed-cost",
            ['allocation_method' => 'by_value'])->getStatusCode();
        $steps['packing_list_pdf'] = $this->actingAs($impex)
            ->get("/api/v1/supply-chain/shipments/{$sid}/packing-list")->getStatusCode();
        $steps['commercial_invoice_pdf'] = $this->actingAs($impex)
            ->get("/api/v1/supply-chain/shipments/{$sid}/commercial-invoice")->getStatusCode();

        fwrite(STDERR, '[M043 impex e2e] '.json_encode($steps)."\n");

        // Every documented step succeeds EXCEPT the landed-cost calculation,
        // which 500s (see test_probe_calculate_landed_cost_endpoint_500s_*).
        // PASS-EITHER-WAY LOCK ON A KNOWN DEFECT for that one key.
        $bad = array_filter($steps, fn (int $c) => $c >= 400);
        $this->assertSame(['landed_cost' => 500], $bad,
            'MEASURED: impex_officer completes 14 of 15 documented steps; step 5 "calculate landed cost" 500s');

        // The documented next step is a GRN — which impex_officer cannot create.
        $this->assertFalse($impex->hasPermission('inventory.grn.create'),
            'RECORDED: the documented "proceed to GRN" step needs a different role');
    }

    /**
     * INVARIANT: internal endpoints leak no other supplier's shipment, and a
     * supplier-portal user cannot reach the internal surface at all.
     */
    public function test_probe_supplier_portal_user_cannot_reach_internal_shipments(): void
    {
        $owner = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($owner, lineCount: 1, lineTotal: '100.00');

        // No session at all — the internal surface is web/sanctum guarded.
        $this->getJson("/api/v1/supply-chain/shipments/{$shipment->hash_id}")->assertStatus(401);

        // A portal user is not an App\Modules\Auth\Models\User, so it cannot be
        // acted-as on the sanctum web guard. Assert the guard separation holds:
        // the portal's own routes are under a different prefix + guard.
        $portalRoutes = collect(app('router')->getRoutes())
            ->filter(fn ($r) => str_contains((string) $r->uri(), 'b2b/supplier'))
            ->map(fn ($r) => implode('|', $r->gatherMiddleware()))
            ->unique()->values()->all();
        $internal = collect(app('router')->getRoutes())
            ->filter(fn ($r) => str_contains((string) $r->uri(), 'supply-chain/shipments'))
            ->map(fn ($r) => implode('|', $r->gatherMiddleware()))
            ->unique()->values()->all();

        fwrite(STDERR, '[M043 guard separation] internal shipment middleware='.json_encode($internal)."\n");
        foreach ($internal as $mw) {
            $this->assertStringContainsString('auth:sanctum', $mw);
            $this->assertStringNotContainsString('supplier_portal', $mw);
        }
        $this->assertNotEmpty($portalRoutes);
    }

    /** INVARIANT: no raw integer primary key in any error body. */
    public function test_probe_error_bodies_carry_no_raw_ids(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user, lineCount: 1, lineTotal: '100.00');
        $shipment->forceFill(['status' => ShipmentStatus::Received->value])->save();

        $bodies = [];
        $bodies['illegal_transition'] = (string) $this->actingAs($user)->patchJson(
            "/api/v1/supply-chain/shipments/{$shipment->hash_id}/status", ['status' => 'shipped'])->getContent();
        $bodies['archive_received'] = (string) $this->actingAs($user)
            ->deleteJson("/api/v1/supply-chain/shipments/{$shipment->hash_id}")->getContent();
        $bodies['bad_container'] = (string) $this->actingAs($user)->postJson(
            "/api/v1/supply-chain/shipments/{$shipment->hash_id}/containers", ['gross_weight_kg' => 'x'])->getContent();
        $bodies['not_found'] = (string) $this->actingAs($user)
            ->getJson('/api/v1/supply-chain/shipments/zzzznope')->getContent();

        foreach ($bodies as $label => $body) {
            fwrite(STDERR, "[M043 error body] {$label}: ".substr($body, 0, 200)."\n");
            $this->assertStringNotContainsString('"id":'.$shipment->id, $body, "raw pk leaked in {$label}");
            $this->assertStringNotContainsString('shipment_id', $body, "internal column named in {$label}");
        }
        // The BusinessRuleException message names the shipment NUMBER, not the id.
        $this->assertStringContainsString($shipment->shipment_number, $bodies['illegal_transition']);
    }

    /** INVARIANT: the `shipment` document sequence is configured and produces a number. */
    public function test_probe_shipment_document_sequence_exists(): void
    {
        $user = $this->userWith(['supply_chain.view', 'supply_chain.shipments.manage']);
        $po = $this->seedPo($user, 1, '100.00');
        $r = $this->actingAs($user)->postJson('/api/v1/supply-chain/shipments', [
            'purchase_order_id' => $po->hash_id,
        ])->assertCreated();

        $number = $r->json('data.shipment_number');
        $row = DB::table('document_sequences')->where('document_type', 'shipment')->first();
        fwrite(STDERR, "[M043 sequence] number={$number} row=".json_encode($row)."\n");

        $this->assertMatchesRegularExpression('/^SHP-\d{6}-\d{4}$/', (string) $number);
        $this->assertNotNull($row, 'a document_sequences row is created on first use');
    }

    // ─────────────────────────────── helpers ───────────────────────────────

    private function userWith(array $permSlugs): User
    {
        $role = Role::create([
            'name' => 'M043 Probe '.uniqid(),
            'slug' => 'm43_'.substr(uniqid(), -6),
            'description' => 'probe',
        ]);
        foreach ($permSlugs as $slug) {
            $perm = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'module' => explode('.', $slug)[0]],
            );
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }

        return User::factory()->create([
            'role_id' => $role->id,
            'email'   => 'm43_'.substr(uniqid(), -6).'@t.test',
        ]);
    }

    private function seedPo(User $user, int $lineCount, string $lineTotal, string $lineQty = '100.00'): PurchaseOrder
    {
        $vendor = Vendor::create([
            'name' => 'JP-V-'.substr(uniqid(), -5),
            'is_active' => true,
            'payment_terms_days' => 30,
            'address' => 'Osaka, Japan',
            'contact_person' => 'Sato',
        ]);
        $subtotal = bcmul($lineTotal, (string) $lineCount, 2);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-T-'.substr(uniqid(), -5),
            'vendor_id' => $vendor->id,
            'date' => '2026-09-01',
            'subtotal' => $subtotal,
            'vat_amount' => '0.00',
            'total_amount' => $subtotal,
            'created_by' => $user->id,
        ]);
        $po->forceFill(['status' => 'sent'])->save();

        $category = ItemCategory::firstOrCreate(['name' => 'Raw Materials']);
        for ($i = 0; $i < $lineCount; $i++) {
            $item = Item::create([
                'code' => 'RM-'.substr(uniqid(), -5).$i,
                'name' => 'PP Resin '.$i,
                'unit_of_measure' => 'kg',
                'item_type' => 'raw_material',
                'category_id' => $category->id,
            ]);
            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'item_id' => $item->id,
                'description' => 'Resin line '.$i,
                'quantity' => $lineQty,
                'unit' => 'kg',
                'unit_price' => bccomp($lineQty, '0', 2) > 0 ? bcdiv($lineTotal, $lineQty, 2) : '0.00',
                'total' => $lineTotal,
            ]);
        }

        return $po->fresh();
    }

    private function seedShipment(User $user, int $lineCount, string $lineTotal, string $lineQty = '100.00'): Shipment
    {
        $po = $this->seedPo($user, $lineCount, $lineTotal, $lineQty);

        return Shipment::create([
            'shipment_number' => 'SHP-T-'.substr(uniqid(), -5),
            'purchase_order_id' => $po->id,
            'carrier' => 'ONE',
            'bl_number' => 'BL'.substr(uniqid(), -8),
            'etd' => '2026-09-05',
            'eta' => '2026-09-20',
            'created_by' => $user->id,
        ])->fresh();
    }

    /** Walk a shipment to a target status through the service, bypassing HTTP. */
    private function advance(Shipment $shipment, ShipmentStatus $target): void
    {
        $svc = app(ShipmentService::class);
        $path = [ShipmentStatus::Shipped, ShipmentStatus::InTransit, ShipmentStatus::Customs,
            ShipmentStatus::Cleared, ShipmentStatus::Received];
        foreach ($path as $step) {
            $svc->updateStatus($shipment->fresh(), $step);
            if ($step === $target) {
                break;
            }
        }
        $shipment->refresh();
    }

    /** A real UploadedFile with real bytes on disk (NOT UploadedFile::fake()). */
    private function realFile(string $clientName, string $bytes): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'm43');
        file_put_contents($tmp, $bytes);

        return new UploadedFile($tmp, $clientName, null, null, true);
    }

    private function realPdf(string $clientName): UploadedFile
    {
        return $this->realFile(
            $clientName,
            "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
        );
    }
}
