<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Events\ChainStepAdvanced;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Models\Inspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GoodsReceiptNoteRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_linking_incoming_inspection_stages_one_content_free_grn_refresh(): void
    {
        $grn = GoodsReceiptNote::factory()->create(['qc_inspection_id' => null]);
        $inspection = Inspection::create([
            'inspection_number' => 'QC-GRN-LIVE-'.substr(uniqid(), -6),
            'stage' => 'incoming',
            'status' => 'draft',
            'product_id' => Product::factory()->create()->id,
            'entity_type' => 'grn',
            'entity_id' => $grn->id,
            'batch_quantity' => 10,
            'sample_size' => 5,
        ]);

        DB::transaction(function () use ($grn, $inspection): void {
            $grn->forceFill(['qc_inspection_id' => $inspection->id])->save();
            $grn->forceFill(['remarks' => 'internal note'])->save();
        });

        $messages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\Inventory\\Events\\GoodsReceiptNoteChanged')
            ->get();

        $this->assertCount(1, $messages);
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($grn->hash_id, $payload['grnHashId']);
        $this->assertSame('updated', $payload['action']);
        $this->assertArrayNotHasKey('remarks', $payload);
        $this->assertArrayNotHasKey('qc_inspection_id', $payload);
    }

    public function test_incoming_qc_handoff_changes_stage_a_content_free_grn_refresh(): void
    {
        $grn = GoodsReceiptNote::factory()->create();

        app(GrnService::class)->markIncomingQcHandoffManual($grn->id, 'Needs review');

        $messages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\Inventory\\Events\\GoodsReceiptNoteChanged')
            ->get();

        $this->assertCount(1, $messages);
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($grn->hash_id, $payload['grnHashId']);
        $this->assertSame('updated', $payload['action']);
        $this->assertArrayNotHasKey('incoming_qc_handoff_message', $payload);
        $this->assertArrayNotHasKey('qc_inspection_id', $payload);
    }

    public function test_grn_line_changes_refresh_the_grn_and_linked_purchase_order_once_per_transaction(): void
    {
        $grn = GoodsReceiptNote::factory()->create();
        $item = Item::factory()->create();
        $purchaseOrderItem = PurchaseOrderItem::create([
            'purchase_order_id' => $grn->purchase_order_id,
            'item_id' => $item->id,
            'description' => 'Receipt line',
            'quantity' => '20.000',
            'unit' => $item->unit_of_measure,
            'unit_price' => '10.00',
            'total' => '200.00',
            'quantity_received' => '0.000',
        ]);

        $lines = collect(range(1, 2))->map(fn () => GrnItem::create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_item_id' => $purchaseOrderItem->id,
            'item_id' => $item->id,
            'location_id' => WarehouseLocation::factory()->create()->id,
            'quantity_received' => '10.000',
            'quantity_accepted' => '0.000',
            'unit_cost' => '10.0000',
        ]));

        DB::table('event_outbox')->delete();

        DB::transaction(function () use ($lines): void {
            $lines[0]->forceFill(['quantity_accepted' => '5.000'])->save();
            $lines[1]->forceFill(['coa_verified' => true])->save();
        });

        $grnRefreshes = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\Inventory\\Events\\GoodsReceiptNoteChanged')
            ->get();
        $this->assertCount(1, $grnRefreshes);
        $grnPayload = json_decode((string) $grnRefreshes->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($grn->hash_id, $grnPayload['grnHashId']);
        $this->assertSame('updated', $grnPayload['action']);
        $this->assertSame($lines[1]->fresh()->updated_at->toISOString(), $grnPayload['version']);
        $this->assertArrayNotHasKey('quantity_accepted', $grnPayload);
        $this->assertArrayNotHasKey('coa_verified', $grnPayload);

        $purchaseOrderRefreshes = DB::table('event_outbox')
            ->where('event_type', ChainStepAdvanced::class)
            ->get()
            ->filter(static function ($message) use ($grn): bool {
                $payload = json_decode((string) $message->payload, true, flags: JSON_THROW_ON_ERROR);

                return ($payload['entityType'] ?? null) === 'purchase_order'
                    && ($payload['entityHashId'] ?? null) === (string) $grn->purchaseOrder->hash_id;
            });
        $this->assertCount(1, $purchaseOrderRefreshes);
    }
}
