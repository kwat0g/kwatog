<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemCategoryClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, UomSeeder::class]);
        $this->actingAs(User::factory()->create([
            'role_id' => Role::where('slug', 'warehouse_staff')->value('id'),
        ]));
    }

    private function category(string $type = 'raw_material'): ItemCategory
    {
        $category = ItemCategory::factory()->create();
        $category->forceFill(['item_type' => $type])->save();
        return $category;
    }

    private function payload(ItemCategory $category): array
    {
        return [
            'code' => 'CAT-TEST', 'name' => 'Classification test', 'category_id' => $category->hash_id,
            'unit_of_measure' => 'PCS', 'standard_cost' => '2', 'reorder_method' => 'fixed_quantity',
            'reorder_point' => '0', 'safety_stock' => '0', 'lead_time_days' => 0,
        ];
    }

    public function test_item_creation_derives_type_and_rejects_explicit_mismatch(): void
    {
        $category = $this->category('finished_good');
        $this->postJson('/api/v1/inventory/items', $this->payload($category) + ['item_type' => 'raw_material'])
            ->assertUnprocessable();
        $this->postJson('/api/v1/inventory/items', $this->payload($category))
            ->assertCreated()->assertJsonPath('data.item_type', 'finished_good');
    }

    public function test_unconfigured_and_deleted_categories_cannot_create_items(): void
    {
        $category = ItemCategory::factory()->create();
        $this->postJson('/api/v1/inventory/items', $this->payload($category))->assertUnprocessable();
        $category->delete();
        $this->postJson('/api/v1/inventory/items', $this->payload($category))->assertUnprocessable();
    }

    public function test_child_categories_inherit_type_and_hierarchy_cycles_are_rejected(): void
    {
        $root = $this->category();
        $child = $this->postJson('/api/v1/inventory/item-categories', ['name' => 'Resins', 'parent_id' => $root->hash_id])
            ->assertCreated()->assertJsonPath('data.item_type', 'raw_material')->json('data.id');
        $this->putJson('/api/v1/inventory/item-categories/'.$root->hash_id, ['parent_id' => $child])
            ->assertUnprocessable();
        $this->postJson('/api/v1/inventory/item-categories', [
            'name' => 'Conflicting child', 'parent_id' => $root->hash_id, 'item_type' => 'finished_good',
        ])->assertUnprocessable();
        $tree = $this->getJson('/api/v1/inventory/item-categories/tree')->assertOk()->json('data');
        $this->assertSame($root->hash_id, collect($tree)->firstWhere('id', $root->hash_id)['children'][0]['parent_id']);
    }

    public function test_used_item_cannot_be_reclassified_even_by_changing_category(): void
    {
        $raw = $this->category();
        $finished = $this->category('finished_good');
        $item = Item::factory()->create(['category_id' => $raw->id]);
        StockLevel::factory()->create(['item_id' => $item->id, 'location_id' => WarehouseLocation::factory()->create()->id, 'quantity' => '10']);
        $this->putJson('/api/v1/inventory/items/'.$item->hash_id, ['category_id' => $finished->hash_id])
            ->assertUnprocessable();
        $this->assertSame($raw->id, $item->fresh()->category_id);
        $this->putJson('/api/v1/inventory/items/'.$item->hash_id, ['item_type' => 'finished_good'])
            ->assertUnprocessable();
    }

    public function test_category_type_changes_propagate_but_never_rewrite_existing_items(): void
    {
        $root = $this->category();
        $childId = $this->postJson('/api/v1/inventory/item-categories', ['name' => 'Child', 'parent_id' => $root->hash_id])->json('data.id');
        $this->putJson('/api/v1/inventory/item-categories/'.$root->hash_id, ['item_type' => 'packaging'])
            ->assertOk()->assertJsonPath('data.item_type', 'packaging');
        $child = ItemCategory::query()->where('parent_id', $root->id)->firstOrFail();
        $this->assertSame('packaging', $child->item_type);
        $item = Item::factory()->create(['category_id' => $child->id, 'item_type' => 'packaging']);
        $item->delete();
        $this->putJson('/api/v1/inventory/item-categories/'.$root->hash_id, ['item_type' => 'spare_part'])
            ->assertUnprocessable();
        $this->assertSame('packaging', Item::withTrashed()->find($item->id)->item_type->value);
        $this->putJson('/api/v1/inventory/item-categories/'.$childId, ['item_type' => 'finished_good'])
            ->assertUnprocessable();
    }

    public function test_legacy_conflict_is_flagged_and_unrelated_edits_preserve_item_type(): void
    {
        $raw = $this->category();
        $item = Item::factory()->create(['category_id' => $raw->id, 'item_type' => 'finished_good']);
        $this->getJson('/api/v1/inventory/items/'.$item->hash_id)->assertOk()
            ->assertJsonPath('data.classification_needs_review', true);
        $this->putJson('/api/v1/inventory/items/'.$item->hash_id, ['name' => 'Updated name', 'category_id' => $raw->hash_id])
            ->assertOk()->assertJsonPath('data.item_type', 'finished_good');
        $finished = $this->category('finished_good');
        $this->putJson('/api/v1/inventory/items/'.$item->hash_id, ['category_id' => $finished->hash_id])
            ->assertOk()->assertJsonPath('data.classification_needs_review', false);
    }
    public function test_unused_item_can_move_to_another_type_and_migration_preserves_history(): void
    {
        $raw = $this->category();
        $finished = $this->category('finished_good');
        $item = Item::factory()->create(['category_id' => $raw->id]);
        $this->putJson('/api/v1/inventory/items/'.$item->hash_id, ['category_id' => $finished->hash_id])
            ->assertOk()->assertJsonPath('data.item_type', 'finished_good');

        $migration = require database_path('migrations/2026_10_04_000004_add_item_type_to_item_categories.php');
        $migration->down();
        $mixed = ItemCategory::factory()->create(['name' => 'Mixed legacy']);
        Item::factory()->create(['category_id' => $mixed->id]);
        Item::factory()->create(['category_id' => $mixed->id, 'item_type' => 'finished_good']);
        $known = ItemCategory::factory()->create(['name' => 'Raw Materials']);
        $child = ItemCategory::factory()->create(['name' => 'Legacy child', 'parent_id' => $known->id]);
        $conflict = Item::factory()->create(['category_id' => $child->id, 'item_type' => 'finished_good']);
        $before = \Illuminate\Support\Facades\DB::table('items')->orderBy('id')->get()->toJson();
        $migration->up();
        $this->assertNull($mixed->fresh()->item_type);
        $this->assertSame('raw_material', $child->fresh()->item_type);
        $this->assertSame('finished_good', $conflict->fresh()->item_type->value);
        $this->assertSame($before, \Illuminate\Support\Facades\DB::table('items')->orderBy('id')->get()->toJson());
    }

}
