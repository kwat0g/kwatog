<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Imports\ItemImporter;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Inventory\Models\Uom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class FinishedGoodItemImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Uom::create(['code' => 'PCS', 'name' => 'Pieces']);
    }

    public function test_finished_good_import_does_not_require_supplier_reorder_fields(): void
    {
        $importer = new ItemImporter;

        $this->assertNotContains('reorder_method', $importer->requiredColumns());
        $this->assertNotContains('minimum_order_quantity', $importer->requiredColumns());

        $item = $importer->importRow([
            'code' => 'FG-IMPORT-01',
            'name' => 'Imported finished item',
            'item_type' => 'finished_good',
            'unit_of_measure' => 'PCS',
            'category' => 'Finished goods',
            'standard_cost' => '25.0000',
            'reorder_point' => '10.000',
            'safety_stock' => '2.000',
            'lead_time_days' => '7',
        ]);

        $this->assertSame('finished_good', $item->item_type->value);
        $this->assertSame('finished_good', $item->category->item_type);
        $this->assertSame('fixed_quantity', $item->reorder_method->value);
        $this->assertNull($item->minimum_order_quantity);
    }

    public function test_import_rejects_item_type_that_conflicts_with_category_type(): void
    {
        ItemCategory::create(['name' => 'Raw inputs', 'item_type' => 'raw_material']);

        try {
            (new ItemImporter)->importRow([
                'code' => 'FG-IMPORT-02',
                'name' => 'Misclassified finished item',
                'item_type' => 'finished_good',
                'unit_of_measure' => 'PCS',
                'category' => 'Raw inputs',
                'standard_cost' => '25.0000',
                'reorder_point' => '10.000',
                'safety_stock' => '2.000',
                'lead_time_days' => '7',
            ]);
            $this->fail('An import must not bypass the category classification.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('category', strtolower($error->getMessage()));
        }
    }
}
