<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\MRP\Services\BomCostingService;
use App\Modules\MRP\Services\BomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BomRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_bom_create_and_costing_stage_one_refresh_signal_after_transaction(): void
    {
        $product = Product::factory()->create();
        $item = Item::factory()->create();

        DB::transaction(function () use ($product, $item): void {
            app(BomService::class)->create($product->id, [[
                'item_id' => $item->id,
                'quantity_per_unit' => '1',
                'unit' => $item->unit_of_measure,
            ]]);
        });

        $messages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\MRP\\Events\\BomChanged')
            ->get();

        $this->assertCount(1, $messages, 'A BOM write and its cost recalculation must coalesce to one refresh per record and transaction.');
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('updated', $payload['action']);
        $this->assertNotEmpty($payload['bomHashId']);
        $this->assertNotEmpty($payload['version']);
        $this->assertSame('pending', $messages->first()->status);
    }

    public function test_component_cost_invalidation_stages_bom_refresh(): void
    {
        $product = Product::factory()->create();
        $item = Item::factory()->create();
        app(BomService::class)->create($product->id, [[
            'item_id' => $item->id,
            'quantity_per_unit' => '1',
            'unit' => $item->unit_of_measure,
        ]]);
        DB::table('event_outbox')->delete();

        app(BomCostingService::class)->invalidateForItems([$item->id]);

        $messages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\MRP\\Events\\BomChanged')
            ->get();
        $this->assertCount(1, $messages, 'Bulk invalidation must use the same observed BOM write path.');
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('updated', $payload['action']);
    }

    public function test_bom_archive_and_restore_stage_one_signal_each(): void
    {
        $product = Product::factory()->create();
        $item = Item::factory()->create();
        $service = app(BomService::class);
        $old = $service->create($product->id, [[
            'item_id' => $item->id,
            'quantity_per_unit' => '1',
            'unit' => $item->unit_of_measure,
        ]]);
        $service->create($product->id, [[
            'item_id' => $item->id,
            'quantity_per_unit' => '2',
            'unit' => $item->unit_of_measure,
        ]]);
        DB::table('event_outbox')->delete();

        $service->delete($old);
        $deleted = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\MRP\\Events\\BomChanged')
            ->get();
        $this->assertCount(1, $deleted);
        $this->assertSame('deleted', json_decode((string) $deleted->first()->payload, true, flags: JSON_THROW_ON_ERROR)['action']);

        DB::table('event_outbox')->delete();
        $service->restore($old);
        $restored = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\MRP\\Events\\BomChanged')
            ->get();
        $this->assertCount(1, $restored, 'Restore fires both updated and restored model callbacks.');
        $this->assertSame('restored', json_decode((string) $restored->first()->payload, true, flags: JSON_THROW_ON_ERROR)['action']);
    }
}
