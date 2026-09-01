<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\TraceabilityService;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\ShipmentLot;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M058 AUDIT PROBE — build a real trace chain and attack every link.
 * Scratch file: delete before release.
 */
class ZzM058TraceProbeTest extends TestCase
{
    use RefreshDatabase;

    private User $qc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->qc = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
    }

    /**
     * Build: material lot (GRN item) -> work order (batch + lot refs)
     *        -> inspection -> shipment lot -> delivery -> customer
     *
     * @return array{grn: GoodsReceiptNote, grnItem: GrnItem, wo: WorkOrder,
     *   insp: Inspection, lot: ShipmentLot, delivery: Delivery, customer: Customer}
     */
    private function chain(string $suffix = 'A'): array
    {
        $item = Item::factory()->create();
        $product = Product::factory()->create();
        $customer = Customer::factory()->create(['name' => "Toyota-{$suffix}"]);
        $so = SalesOrder::factory()->create(['customer_id' => $customer->id]);

        $grn = GoodsReceiptNote::factory()->create();
        $matLot = "MAT-{$suffix}-".substr((string) uniqid(), -5);
        $grnItem = $this->grnLine($grn, $item, $matLot, "SUP-{$suffix}");

        $wo = WorkOrder::factory()->create([
            'product_id' => $product->id,
            'batch_number' => "BATCH-{$suffix}-".substr((string) uniqid(), -5),
            'quantity_good' => 500,
            'material_lot_references' => [[
                'item_id' => $item->id,
                'material_lot_number' => $matLot,
                'grn_number' => $grn->grn_number,
                'quantity' => '100',
            ]],
        ]);

        $insp = Inspection::create([
            'inspection_number' => 'QC-'.substr((string) uniqid(), -8),
            'stage' => 'outgoing',
            'status' => 'passed',
            'product_id' => $product->id,
            'entity_type' => 'work_order',
            'entity_id' => $wo->id,
            'batch_quantity' => 500,
            'sample_size' => 50,
            'accept_count' => 1,
            'reject_count' => 2,
            'defect_count' => 0,
            'accepted_quantity' => 500,
            'inspector_id' => $this->qc->id,
            'completed_at' => '2026-08-20 10:00:00',
        ]);

        $delivery = Delivery::create([
            'delivery_number' => 'DR-'.substr((string) uniqid(), -8),
            'sales_order_id' => $so->id,
            'status' => 'delivered',
            'scheduled_date' => '2026-08-20',
            'delivered_at' => '2026-08-21 09:00:00',
            'created_by' => $this->qc->id,
        ]);

        $lot = ShipmentLot::create([
            'lot_number' => 'LOT-'.substr((string) uniqid(), -8),
            'delivery_id' => $delivery->id,
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'work_order_ids' => [$wo->id],
            'quantity' => 500,
            'lot_date' => '2026-08-21',
            'created_by' => $this->qc->id,
        ]);

        return compact('grn', 'grnItem', 'wo', 'insp', 'lot', 'delivery', 'customer');
    }

    /** A GRN line without a factory: purchase_order_item_id is NOT NULL. */
    private function grnLine(
        GoodsReceiptNote $grn, Item $item, ?string $lot, ?string $supplierRef = null,
    ): GrnItem {
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $grn->purchase_order_id,
            'item_id' => $item->id,
            'description' => 'probe line',
            'quantity' => '1000.000',
            'unit' => 'kg',
            'unit_price' => '10.00',
            'total' => '10000.00',
            'quantity_received' => '0.000',
        ]);

        return GrnItem::create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id,
            'quantity_received' => '100.000',
            'quantity_accepted' => '100.000',
            'unit_cost' => '10.00',
            'material_lot_number' => $lot,
            'supplier_lot_reference' => $supplierRef,
            'coa_verified' => false,
        ]);
    }

    private function search(string $term): array
    {
        return $this->actingAs($this->qc)
            ->getJson('/api/v1/quality/traceability/search?term='.urlencode($term))
            ->json('data');
    }

    private function recall(string $lot): array
    {
        return $this->actingAs($this->qc)
            ->getJson('/api/v1/quality/traceability/recall-simulation?lot='.urlencode($lot))
            ->json('data');
    }

    /** PROBE T1 — forward and backward trace both resolve on an intact chain. */
    public function test_probe_intact_chain_resolves_both_directions(): void
    {
        $c = $this->chain();

        // FORWARD: material lot -> work orders -> (via batch) -> customers
        $fwd = $this->search($c['grnItem']->material_lot_number);
        fwrite(STDERR, "\n[T1-fwd] search(material lot) found=".var_export($fwd['found'], true)
            ." type={$fwd['type']} consuming_wos=".count($fwd['trace']['forward']['work_orders'] ?? [])."\n");
        fwrite(STDERR, '[T1-fwd] does the material-lot trace reach a CUSTOMER? '
            .(str_contains(json_encode($fwd), 'Toyota') ? 'YES' : 'NO — trace stops at the work order')."\n");

        // recall is the only forward-to-customer path
        $rc = $this->recall($c['grnItem']->material_lot_number);
        fwrite(STDERR, '[T1-fwd] recall(material lot) found='.var_export($rc['found'], true)
            .' customers='.count($rc['affected_customers']).' deliveries='.count($rc['affected_deliveries'])
            ." qty={$rc['total_affected_qty']}\n");

        // BACKWARD: shipment lot -> work orders -> materials
        $bwd = $this->search($c['lot']->lot_number);
        fwrite(STDERR, '[T1-bwd] search(shipment lot) found='.var_export($bwd['found'], true)
            .' wos='.count($bwd['trace']['backward']['work_orders'] ?? [])
            .' materials='.count($bwd['trace']['backward']['work_orders'][0]['materials'] ?? [])
            .' inspections='.count($bwd['trace']['backward']['work_orders'][0]['inspections'] ?? [])
            .' delivery='.($bwd['trace']['forward']['delivery'] ? 'present' : 'NULL')
            .' customer='.($bwd['trace']['forward']['customer']['name'] ?? 'NULL')."\n");

        $this->assertTrue($fwd['found']);
        $this->assertTrue($bwd['found']);
    }

    /** PROBE T2 — hard-delete each link: does the trace REFUSE or SILENTLY ERASE? */
    public function test_probe_hard_delete_each_link(): void
    {
        $report = [];

        // ---- link: CUSTOMER (nullOnDelete on shipment_lots.customer_id)
        $c = $this->chain('CUST');
        $lotNo = $c['lot']->lot_number;
        $before = $this->search($lotNo);
        $r = $this->tryDelete('delete from customers where id = ?', [$c['customer']->id]);
        if ($r['ok']) {
            $rows = $r['rows'];
            $after = $this->search($lotNo);
            $rc = $this->recall($lotNo);
            $report['customer hard-delete'] = sprintf(
                'DELETED (%d row) | trace still found=%s | customer before=%s after=%s | recall customers=%d qty=%d',
                $rows,
                var_export($after['found'], true),
                $before['trace']['forward']['customer']['name'] ?? 'null',
                $after['trace']['forward']['customer']['name'] ?? 'NULL',
                count($rc['affected_customers']),
                $rc['total_affected_qty'],
            );
        } else {
            $report['customer hard-delete'] = 'REFUSED: '.$r['err'];
        }

        // ---- link: DELIVERY hard delete (restrictOnDelete)
        $c = $this->chain('DELH');
        $r = $this->tryDelete('delete from deliveries where id = ?', [$c['delivery']->id]);
        $report['delivery hard-delete'] = $r['ok']
            ? 'DELETED — trace found='.var_export($this->search($c['lot']->lot_number)['found'], true)
            : 'REFUSED: '.$r['err'];

        // ---- link: DELIVERY soft delete (SoftDeletes bypasses the FK restrict)
        $c = $this->chain('DELS');
        $c['delivery']->delete();
        $s = $this->search($c['lot']->lot_number);
        $rc = $this->recall($c['lot']->lot_number);
        $report['delivery ARCHIVE (soft)'] = sprintf(
            'trace found=%s | delivery=%s | customer=%s | recall: found=%s deliveries=%d customers=%d qty=%d',
            var_export($s['found'], true),
            $s['trace']['forward']['delivery'] ? 'present' : 'NULL',
            $s['trace']['forward']['customer']['name'] ?? 'NULL',
            var_export($rc['found'], true),
            count($rc['affected_deliveries']),
            count($rc['affected_customers']),
            $rc['total_affected_qty'],
        );

        // ---- link: WORK ORDER hard delete (JSON work_order_ids, no FK)
        $c = $this->chain('WOH');
        $lotNo = $c['lot']->lot_number;
        $b = $this->search($lotNo);
        $this->tryDelete('delete from inspections where entity_type=\'work_order\' and entity_id = ?', [$c['wo']->id]);
        $r = $this->tryDelete('delete from work_orders where id = ?', [$c['wo']->id]);
        if ($r['ok']) {
            $a = $this->search($lotNo);
            $rc = $this->recall($lotNo);
            $report['work order hard-delete'] = sprintf(
                'DELETED | trace found=%s | backward work_orders %d -> %d | json still lists %s | recall qty=%d',
                var_export($a['found'], true),
                count($b['trace']['backward']['work_orders']),
                count($a['trace']['backward']['work_orders']),
                json_encode(ShipmentLot::find($c['lot']->id)->work_order_ids),
                $rc['total_affected_qty'],
            );
        } else {
            $report['work order hard-delete'] = 'REFUSED: '.$r['err'];
        }

        // ---- link: GRN ITEM (the material lot) hard delete
        $c = $this->chain('GRNH');
        $mat = $c['grnItem']->material_lot_number;
        $r = $this->tryDelete('delete from grn_items where id = ?', [$c['grnItem']->id]);
        if ($r['ok']) {
            $a = $this->search($mat);
            $rc = $this->recall($mat);
            $report['grn_item hard-delete'] = sprintf(
                'DELETED | search(material lot) found=%s | recall found=%s | WO json still names the lot=%s',
                var_export($a['found'], true),
                var_export($rc['found'], true),
                json_encode(WorkOrder::find($c['wo']->id)->material_lot_references),
            );
        } else {
            $report['grn_item hard-delete'] = 'REFUSED: '.$r['err'];
        }

        // ---- link: GRN hard delete
        $c = $this->chain('GRNP');
        $r = $this->tryDelete('delete from goods_receipt_notes where id = ?', [$c['grn']->id]);
        $report['grn parent hard-delete'] = $r['ok']
            ? 'DELETED — search(material lot) found='
                .var_export($this->search($c['grnItem']->material_lot_number)['found'], true)
            : 'REFUSED: '.$r['err'];

        // ---- link: INSPECTION hard delete (the QC evidence in the chain)
        $c = $this->chain('INSP');
        $b = count($this->search($c['lot']->lot_number)['trace']['backward']['work_orders'][0]['inspections']);
        $r = $this->tryDelete('delete from inspections where id = ?', [$c['insp']->id]);
        if ($r['ok']) {
            $a = count($this->search($c['lot']->lot_number)['trace']['backward']['work_orders'][0]['inspections']);
            $report['inspection hard-delete'] = "DELETED | inspections in trace {$b} -> {$a}, trace still found=true";
        } else {
            $report['inspection hard-delete'] = 'REFUSED: '.$r['err'];
        }

        // ---- link: SHIPMENT LOT itself
        $c = $this->chain('SLH');
        $r = $this->tryDelete('delete from shipment_lots where id = ?', [$c['lot']->id]);
        if ($r['ok']) {
            $viaBatch = $this->search($c['wo']->batch_number);
            $report['shipment_lot hard-delete'] = 'DELETED | search(lot) found='
                .var_export($this->search($c['lot']->lot_number)['found'], true)
                .' | batch trace forward lots='.count($viaBatch['trace']['forward']['lots']);
        } else {
            $report['shipment_lot hard-delete'] = 'REFUSED: '.$r['err'];
        }

        // ---- link: PRODUCT soft-delete
        $c = $this->chain('PROD');
        $c['wo']->product->delete();
        $s = $this->search($c['lot']->lot_number);
        $report['product ARCHIVE (soft)'] = 'trace found='.var_export($s['found'], true)
            .' | lot.product='.($s['trace']['lot']['product'] ? 'present' : 'NULL')
            .' | wo.product='.($s['trace']['backward']['work_orders'][0]['work_order']['product'] ? 'present' : 'NULL');

        // ---- link: ITEM soft-delete (raw material identity)
        $c = $this->chain('ITEM');
        Item::find($c['grnItem']->item_id)->delete();
        $s = $this->search($c['grnItem']->material_lot_number);
        $report['item ARCHIVE (soft)'] = 'search(material lot) found='.var_export($s['found'], true)
            .' | item_code='.var_export($s['trace']['material_lot']['item_code'] ?? null, true)
            .' | item_id='.var_export($s['trace']['material_lot']['item_id'] ?? null, true);

        // ---- link: CUSTOMER with NO sales order (nothing else holds an FK)
        $cust2 = Customer::factory()->create(['name' => 'Nissan-BARE']);
        $d5 = Delivery::create([
            'delivery_number' => 'DR-'.substr((string) uniqid(), -8),
            'sales_order_id' => $c['delivery']->sales_order_id,
            'status' => 'delivered', 'scheduled_date' => '2026-08-28', 'created_by' => $this->qc->id,
        ]);
        $lot5 = ShipmentLot::create([
            'lot_number' => 'LOT-'.substr((string) uniqid(), -8),
            'delivery_id' => $d5->id, 'customer_id' => $cust2->id,
            'work_order_ids' => [], 'quantity' => 77, 'lot_date' => '2026-08-28',
        ]);
        $r5 = $this->tryDelete('delete from customers where id = ?', [$cust2->id]);
        $report['customer (no SO) delete'] = $r5['ok']
            ? 'DELETED | shipment_lot survives, customer_id now '
                .var_export(DB::table('shipment_lots')->where('id', $lot5->id)->value('customer_id'), true)
                .' | trace customer='
                .var_export($this->search($lot5->lot_number)['trace']['forward']['customer'], true)
            : 'REFUSED: '.$r5['err'];

        fwrite(STDERR, "\n[T2] LINK-BY-LINK ATTACK\n");
        foreach ($report as $k => $v) {
            fwrite(STDERR, sprintf("  %-26s %s\n", $k, $v));
        }
        $this->assertNotEmpty($report);
    }

    /** Run a raw statement inside a SAVEPOINT so a refusal does not abort the outer txn. */
    private function tryDelete(string $sql, array $bind): array
    {
        try {
            $rows = 0;
            DB::transaction(function () use ($sql, $bind, &$rows) {
                $rows = DB::delete($sql, $bind);
            });

            return ['ok' => true, 'rows' => $rows];
        } catch (\Throwable $e) {
            return ['ok' => false, 'err' => $this->sqlState($e)];
        }
    }

    private function sqlState(\Throwable $e): string
    {
        if (preg_match('/SQLSTATE\[(\w+)\]/', $e->getMessage(), $m)) {
            return $m[1].' '.substr(strstr($e->getMessage(), 'ERROR') ?: $e->getMessage(), 0, 110);
        }
        return substr($e->getMessage(), 0, 110);
    }

    /** PROBE T3 — a partial trace that LOOKS complete. */
    public function test_probe_partial_trace_looks_complete(): void
    {
        // two work orders in one shipment lot; delete one
        $c = $this->chain('PART');
        $wo2 = WorkOrder::factory()->create([
            'product_id' => $c['wo']->product_id,
            'batch_number' => 'BATCH-PART2-'.substr((string) uniqid(), -5),
            'quantity_good' => 300,
        ]);
        $c['lot']->update(['work_order_ids' => [$c['wo']->id, $wo2->id], 'quantity' => 800]);

        $full = $this->search($c['lot']->lot_number);
        $this->tryDelete('delete from work_orders where id = ?', [$wo2->id]);
        $part = $this->search($c['lot']->lot_number);

        fwrite(STDERR, "\n[T3] lot claims quantity {$part['trace']['lot']['quantity']} from "
            .count($part['trace']['backward']['work_orders']).' work order(s) '
            .'(was '.count($full['trace']['backward']['work_orders']).")\n");
        fwrite(STDERR, '[T3] payload carries no warning / no partial flag: keys = '
            .implode(',', array_keys($part['trace']))."\n");
        fwrite(STDERR, '[T3] found still = '.var_export($part['found'], true)
            .' — indistinguishable from a complete trace: '
            .(count($part['trace']['backward']['work_orders']) < count($full['trace']['backward']['work_orders'])
                ? 'YES, one batch vanished with no signal' : 'no')."\n");
        fwrite(STDERR, '[T3] work_order_ids json still = '
            .json_encode(ShipmentLot::find($c['lot']->id)->work_order_ids)."\n");
        $this->assertTrue(true);
    }

    /** PROBE T4 — duplicate / ambiguous identifiers and first-match semantics. */
    public function test_probe_ambiguous_identifiers(): void
    {
        $shared = 'DUP-LOT-777';
        $a = $this->chain('D1');
        $b = $this->chain('D2');
        DB::table('grn_items')->where('id', $a['grnItem']->id)->update(['material_lot_number' => $shared]);
        DB::table('grn_items')->where('id', $b['grnItem']->id)->update(['material_lot_number' => $shared]);

        $s = $this->search($shared);
        fwrite(STDERR, "\n[T4] two grn_items share material_lot_number '{$shared}' (index is NOT unique)\n");
        fwrite(STDERR, '[T4] search returns supplier_lot_reference = '
            .var_export($s['trace']['material_lot']['supplier_lot_reference'], true)
            ." — only ONE of the two, silently\n");

        // batch_number colliding with a shipment lot_number: which wins?
        $c = $this->chain('COLL');
        $collide = 'COLLIDE-1';
        DB::table('work_orders')->where('id', $c['wo']->id)->update(['batch_number' => $collide]);
        DB::table('shipment_lots')->where('id', $c['lot']->id)->update(['lot_number' => $collide]);
        $s2 = $this->search($collide);
        fwrite(STDERR, "[T4] batch_number == lot_number == '{$collide}' -> type resolved = {$s2['type']}"
            ." (batch wins; the shipment-lot leg is unreachable)\n");

        // empty / whitespace / very long / SQL-ish
        foreach (['', '   ', str_repeat('X', 5000), "' or 1=1 --", '%', '_'] as $t) {
            $r = $this->actingAs($this->qc)
                ->getJson('/api/v1/quality/traceability/search?term='.urlencode($t));
            fwrite(STDERR, sprintf(
                "[T4] search(%-14s) => %d found=%s\n",
                "'".substr($t, 0, 12)."'", $r->status(), var_export($r->json('data.found'), true),
            ));
        }
        // array term (map payload)
        $r = $this->actingAs($this->qc)->getJson('/api/v1/quality/traceability/search?term[a]=b');
        fwrite(STDERR, '[T4] search(term[a]=b) => '.$r->status()."\n");
        $r = $this->actingAs($this->qc)->getJson('/api/v1/quality/traceability/recall-simulation?lot[a]=b');
        fwrite(STDERR, '[T4] recall(lot[a]=b) => '.$r->status()."\n");

        $this->assertTrue(true);
    }

    /** PROBE T5 — a KNOWN material lot with no downstream WO reports "not found". */
    public function test_probe_known_lot_reported_absent(): void
    {
        $item = Item::factory()->create();
        $grn = GoodsReceiptNote::factory()->create();
        $orphanLot = 'ORPHAN-'.substr((string) uniqid(), -5);
        $this->grnLine($grn, $item, $orphanLot);

        $s = $this->search($orphanLot);
        $rc = $this->recall($orphanLot);
        fwrite(STDERR, "\n[T5] a RECEIVED lot never issued to production:\n");
        fwrite(STDERR, '  search  found='.var_export($s['found'], true)." type={$s['type']}\n");
        fwrite(STDERR, '  recall  found='.var_export($rc['found'], true)
            ." — the lot demonstrably EXISTS in grn_items\n");
        fwrite(STDERR, "  a recall operator is told this lot is unknown, not 'received but unconsumed'\n");

        $this->assertTrue(true);
    }

    /** PROBE T6 — shipment-lot creation over HTTP: contract, idempotency, validation. */
    public function test_probe_shipment_lot_http(): void
    {
        $c = $this->chain('SLHTTP');
        $d2 = Delivery::create([
            'delivery_number' => 'DR-'.substr((string) uniqid(), -8),
            'sales_order_id' => $c['delivery']->sales_order_id,
            'status' => 'scheduled',
            'scheduled_date' => '2026-08-25',
            'created_by' => $this->qc->id,
        ]);

        $wo = WorkOrder::factory()->create([
            'product_id' => $c['wo']->product_id,
            'batch_number' => 'BATCH-SL-'.substr((string) uniqid(), -5),
            'quantity_good' => 200,
        ]);
        $unstarted = WorkOrder::factory()->create(['batch_number' => null]);

        $url = "/api/v1/quality/traceability/deliveries/{$d2->hash_id}/shipment-lot";

        $r1 = $this->actingAs($this->qc)->postJson($url, ['work_order_ids' => [$wo->hash_id]]);
        fwrite(STDERR, "\n[T6] POST shipment-lot (1st) => ".$r1->status()
            .' lot='.($r1->json('data.lot_number') ?? '-')."\n");

        // idempotency / re-post: unique(delivery_id) exists on the table
        $r2 = $this->actingAs($this->qc)->postJson($url, ['work_order_ids' => [$wo->hash_id]]);
        fwrite(STDERR, '[T6] POST shipment-lot (2nd, same delivery) => '.$r2->status()
            .' body='.substr((string) $r2->getContent(), 0, 200)."\n");

        // unstarted WO (no batch_number)
        $d3 = Delivery::create([
            'delivery_number' => 'DR-'.substr((string) uniqid(), -8),
            'sales_order_id' => $c['delivery']->sales_order_id,
            'status' => 'scheduled', 'scheduled_date' => '2026-08-26', 'created_by' => $this->qc->id,
        ]);
        $r3 = $this->actingAs($this->qc)->postJson(
            "/api/v1/quality/traceability/deliveries/{$d3->hash_id}/shipment-lot",
            ['work_order_ids' => [$unstarted->hash_id]],
        );
        fwrite(STDERR, '[T6] POST with an unstarted WO => '.$r3->status()."\n");

        // duplicate ids, garbage id, wrong-product WO, quantity family
        $d4 = Delivery::create([
            'delivery_number' => 'DR-'.substr((string) uniqid(), -8),
            'sales_order_id' => $c['delivery']->sales_order_id,
            'status' => 'scheduled', 'scheduled_date' => '2026-08-27', 'created_by' => $this->qc->id,
        ]);
        $u4 = "/api/v1/quality/traceability/deliveries/{$d4->hash_id}/shipment-lot";
        $cases = [
            'duplicate ids' => ['work_order_ids' => [$wo->hash_id, $wo->hash_id]],
            'garbage id' => ['work_order_ids' => ['NOPE']],
            'raw integer id' => ['work_order_ids' => [(string) $wo->id]],
            'qty 1.999' => ['work_order_ids' => [$wo->hash_id], 'quantity' => 1.999],
            'qty 1e3' => ['work_order_ids' => [$wo->hash_id], 'quantity' => '1e3'],
            'qty 1e17' => ['work_order_ids' => [$wo->hash_id], 'quantity' => '1e17'],
            'qty -1' => ['work_order_ids' => [$wo->hash_id], 'quantity' => -1],
            'qty 0' => ['work_order_ids' => [$wo->hash_id], 'quantity' => 0],
            'qty 99999999999' => ['work_order_ids' => [$wo->hash_id], 'quantity' => 99999999999],
            'qty map' => ['work_order_ids' => [$wo->hash_id], 'quantity' => ['a' => 1]],
            'ids map' => ['work_order_ids' => ['a' => $wo->hash_id]],
            'lot_date 2999' => ['work_order_ids' => [$wo->hash_id], 'lot_date' => '2999-01-01'],
        ];
        foreach ($cases as $label => $body) {
            DB::table('shipment_lots')->where('delivery_id', $d4->id)->delete();
            $r = $this->actingAs($this->qc)->postJson($u4, $body);
            $stored = DB::table('shipment_lots')->where('delivery_id', $d4->id)->value('quantity');
            fwrite(STDERR, sprintf(
                "[T6-val] %-16s => %-3d stored_qty=%s\n", $label, $r->status(), var_export($stored, true),
            ));
        }

        // over-allocation: claim a WO that is already in another lot
        DB::table('shipment_lots')->where('delivery_id', $d4->id)->delete();
        $r = $this->actingAs($this->qc)->postJson($u4, ['work_order_ids' => [$c['wo']->hash_id]]);
        fwrite(STDERR, '[T6] POST claiming a WO already inside another shipment lot => '.$r->status()
            .' | that WO is now in '
            .ShipmentLot::query()->whereJsonContains('work_order_ids', $c['wo']->id)->count()." lots\n");

        // WO for a product that has nothing to do with this delivery's sales order
        DB::table('shipment_lots')->where('delivery_id', $d4->id)->delete();
        $foreign = WorkOrder::factory()->create([
            'batch_number' => 'BATCH-FOREIGN-'.substr((string) uniqid(), -5), 'quantity_good' => 9,
        ]);
        $r = $this->actingAs($this->qc)->postJson($u4, ['work_order_ids' => [$foreign->hash_id]]);
        fwrite(STDERR, '[T6] POST with a WO whose product is NOT on the delivery sales order => '
            .$r->status()."\n");

        // quantity vs the batches it claims
        DB::table('shipment_lots')->where('delivery_id', $d4->id)->delete();
        $r = $this->actingAs($this->qc)->postJson($u4, [
            'work_order_ids' => [$foreign->hash_id], 'quantity' => 1000000,
        ]);
        fwrite(STDERR, '[T6] POST quantity 1,000,000 from a batch with quantity_good=9 => '.$r->status()
            .' stored='.var_export(DB::table('shipment_lots')->where('delivery_id', $d4->id)->value('quantity'), true)
            ."\n");

        $this->assertTrue(true);
    }

    /** PROBE T7 — unauthenticated access, isolated (no prior actingAs in this test). */
    public function test_probe_guest_is_refused(): void
    {
        Auth::guard('web')->logout();
        $urls = [
            ['GET', '/api/v1/quality/traceability/search?term=X'],
            ['GET', '/api/v1/quality/traceability/recall-simulation?lot=X'],
            ['GET', '/api/v1/quality/ppap'],
        ];
        foreach ($urls as [$m, $u]) {
            $r = $this->json($m, $u);
            fwrite(STDERR, "\n[T7] guest {$m} {$u} => ".$r->status());
        }
        fwrite(STDERR, "\n");
        $this->assertTrue(true);
    }

    /** PROBE T8 — pg_trigger census + raw-SQL rewrite of trace identifiers. */
    public function test_probe_trace_immutability(): void
    {
        $c = $this->chain('IMMUT');

        $trg = DB::select("select tgrelid::regclass::text tbl, tgname from pg_trigger
            where not tgisinternal and tgrelid::regclass::text in
            ('shipment_lots','work_orders','grn_items','deliveries','inspections','ppap_submissions','ppap_elements')");
        fwrite(STDERR, "\n[T8] pg_trigger on the 7 trace/PPAP tables = ".count($trg)."\n");

        DB::update('update shipment_lots set lot_number = ? where id = ?', ['HACKED-LOT', $c['lot']->id]);
        DB::update('update work_orders set batch_number = ? where id = ?', ['HACKED-BATCH', $c['wo']->id]);
        DB::update('update grn_items set material_lot_number = ? where id = ?', ['HACKED-MAT', $c['grnItem']->id]);
        DB::update('update shipment_lots set work_order_ids = ?::json, quantity = ? where id = ?',
            ['[]', 999999, $c['lot']->id]);

        fwrite(STDERR, '[T8] raw SQL rewrote lot_number -> '
            .DB::table('shipment_lots')->where('id', $c['lot']->id)->value('lot_number')."\n");
        fwrite(STDERR, '[T8] raw SQL rewrote batch_number -> '
            .DB::table('work_orders')->where('id', $c['wo']->id)->value('batch_number')."\n");
        fwrite(STDERR, '[T8] raw SQL rewrote material_lot_number -> '
            .DB::table('grn_items')->where('id', $c['grnItem']->id)->value('material_lot_number')."\n");
        fwrite(STDERR, '[T8] raw SQL emptied work_order_ids and set qty=999999; search(HACKED-LOT) backward wos = '
            .count($this->search('HACKED-LOT')['trace']['backward']['work_orders'])."\n");

        // Eloquent: are ShipmentLot / WorkOrder mutable after delivery confirmation?
        $c2 = $this->chain('IMMUT2');
        $c2['delivery']->forceFill(['status' => 'confirmed', 'confirmed_at' => now()])->save();
        $ok = $c2['lot']->update(['quantity' => 1, 'work_order_ids' => []]);
        fwrite(STDERR, '[T8] Eloquent update on a lot whose delivery is CONFIRMED => '
            .var_export($ok, true).' qty now='.$c2['lot']->fresh()->quantity."\n");

        // Is there a shipment-lot audit trail?
        $al = DB::table('audit_logs')->where('model_type', 'like', '%ShipmentLot%')->count();
        fwrite(STDERR, "[T8] audit_logs rows for ShipmentLot = {$al}\n");

        $this->assertTrue(true);
    }
}
