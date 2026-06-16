<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;
use App\Modules\MRP\Services\BomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use RuntimeException;
use Tests\TestCase;

/**
 * OGAMI-015 — recursive multi-level BOM explosion.
 *
 * Convention: a BOM line's component Item is a manufactured sub-assembly when a
 * CRM Product whose part_number equals the item code carries its own active
 * BOM. BomService::explode() recurses through such sub-assemblies down to raw
 * materials, multiplying quantities and applying each level's waste factor, and
 * aggregating duplicate raw materials reached via different sub-assemblies.
 */
class MultiLevelBomTest extends TestCase
{
    use RefreshDatabase;

    private BomService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BomService::class);
    }

    /** Create an Item with the given code (uom pcs, no conversions). */
    private function item(string $code): Item
    {
        return Item::factory()->create([
            'code'            => $code,
            'unit_of_measure' => 'pcs',
        ]);
    }

    private function product(string $partNumber): Product
    {
        return Product::create([
            'part_number'     => $partNumber,
            'name'            => "Product {$partNumber}",
            'unit_of_measure' => 'pcs',
            'standard_cost'   => 10.00,
            'is_active'       => true,
        ]);
    }

    /** Build an active BOM for $product from [itemCode => [qty, waste]] rows. */
    private function bom(Product $product, array $rows): Bom
    {
        $bom = Bom::create([
            'product_id' => $product->id,
            'version'    => 1,
            'is_active'  => true,
        ]);
        $sort = 0;
        foreach ($rows as $code => [$qty, $waste]) {
            $item = Item::where('code', $code)->firstOrFail();
            BomItem::create([
                'bom_id'            => $bom->id,
                'item_id'           => $item->id,
                'quantity_per_unit' => $qty,
                'unit'              => 'pcs',
                'waste_factor'      => $waste,
                'sort_order'        => $sort++,
            ]);
        }
        return $bom;
    }

    private function byCode(Collection $rows): array
    {
        return $rows->keyBy('item_code')->map(fn ($r) => $r['gross_quantity'])->all();
    }

    // ────────────────────────────────────────────────────────────────────────

    /**
     * Single-level BOM still returns the raw materials directly — no
     * regression vs the pre-OGAMI-015 behaviour.
     */
    public function test_single_level_bom_explodes_to_raw_materials(): void
    {
        $fg = $this->product('FG-1');
        $this->item('RM-1');
        $this->item('RM-2');
        $this->bom($fg, [
            'RM-1' => ['2.0000', '0.00'],
            'RM-2' => ['1.0000', '0.00'],
        ]);

        $rows = $this->service->explode($fg->id, 10.0);
        $byCode = $this->byCode($rows);

        $this->assertSame('20.000', $byCode['RM-1']);
        $this->assertSame('10.000', $byCode['RM-2']);
        $this->assertCount(2, $rows);
    }

    /**
     * Two-level BOM: FG contains sub-assembly SA (a manufactured item with its
     * own BOM) plus a direct raw material. Explosion recurses into SA.
     *
     *   FG BOM: SA-1 x2, RM-2 x1
     *   SA-1 BOM (Product SA-1): RM-1 x3
     *   explode(FG, 10):
     *     SA-1: 2 * 10 = 20 sub-assemblies → RM-1 = 3 * 20 = 60
     *     RM-2: 1 * 10 = 10
     */
    public function test_two_level_bom_recurses_into_sub_assembly(): void
    {
        $fg = $this->product('FG-1');

        // SA-1 exists both as a component Item and as a manufactured Product.
        $this->item('SA-1');
        $this->item('RM-1');
        $this->item('RM-2');

        $saProduct = $this->product('SA-1');
        $this->bom($saProduct, ['RM-1' => ['3.0000', '0.00']]);

        $this->bom($fg, [
            'SA-1' => ['2.0000', '0.00'],
            'RM-2' => ['1.0000', '0.00'],
        ]);

        $rows = $this->service->explode($fg->id, 10.0);
        $byCode = $this->byCode($rows);

        // SA-1 must NOT appear — it is exploded away into raw materials.
        $this->assertArrayNotHasKey('SA-1', $byCode);
        $this->assertSame('60.000', $byCode['RM-1']);
        $this->assertSame('10.000', $byCode['RM-2']);
    }

    /**
     * Waste factor compounds across levels.
     *
     *   FG BOM: SA-1 x2 @10% waste → effective 2.2
     *   SA-1 BOM: RM-1 x3 @10% waste → effective 3.3
     *   explode(FG, 10): RM-1 = 3.3 * (2.2 * 10) = 3.3 * 22 = 72.6
     */
    public function test_waste_factor_compounds_across_levels(): void
    {
        $fg = $this->product('FG-1');
        $this->item('SA-1');
        $this->item('RM-1');

        $saProduct = $this->product('SA-1');
        $this->bom($saProduct, ['RM-1' => ['3.0000', '10.00']]);
        $this->bom($fg, ['SA-1' => ['2.0000', '10.00']]);

        $rows = $this->service->explode($fg->id, 10.0);
        $byCode = $this->byCode($rows);

        $this->assertSame('72.600', $byCode['RM-1']);
    }

    /**
     * A raw material reached through two different sub-assemblies aggregates
     * into a single requirement row.
     *
     *   FG BOM: SA-1 x1, SA-2 x1
     *   SA-1 BOM: RM-1 x2
     *   SA-2 BOM: RM-1 x5
     *   explode(FG, 10): RM-1 = (2*10) + (5*10) = 70, ONE row.
     */
    public function test_shared_raw_material_is_aggregated(): void
    {
        $fg = $this->product('FG-1');
        $this->item('SA-1');
        $this->item('SA-2');
        $this->item('RM-1');

        $this->bom($this->product('SA-1'), ['RM-1' => ['2.0000', '0.00']]);
        $this->bom($this->product('SA-2'), ['RM-1' => ['5.0000', '0.00']]);
        $this->bom($fg, [
            'SA-1' => ['1.0000', '0.00'],
            'SA-2' => ['1.0000', '0.00'],
        ]);

        $rows = $this->service->explode($fg->id, 10.0);

        $rm1 = $rows->where('item_code', 'RM-1');
        $this->assertCount(1, $rm1, 'RM-1 must collapse into a single aggregated row');
        $this->assertSame('70.000', $rm1->first()['gross_quantity']);
    }

    /**
     * A circular BOM (FG → SA-1 → FG) throws a clear exception rather than
     * recursing forever.
     */
    public function test_circular_bom_throws(): void
    {
        $fg = $this->product('FG-1');
        $this->item('SA-1');
        // The finished good is itself usable as a component item.
        $this->item('FG-1');

        // SA-1's BOM references FG-1 → cycle.
        $this->bom($this->product('SA-1'), ['FG-1' => ['1.0000', '0.00']]);
        $this->bom($fg, ['SA-1' => ['1.0000', '0.00']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular bill of materials');

        $this->service->explode($fg->id, 1.0);
    }
}
