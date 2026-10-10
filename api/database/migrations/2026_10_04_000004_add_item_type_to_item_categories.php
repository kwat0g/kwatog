<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_categories', fn (Blueprint $table) => $table->string('item_type', 30)->nullable());

        // Preserve item classification. Only infer unambiguous category mappings.
        $categories = DB::table('item_categories')->get()->keyBy('id');
        $children = $categories->groupBy('parent_id');
        $types = DB::table('items')->select('category_id', 'item_type')->distinct()->get()->groupBy('category_id');
        $standard = ['Raw Materials' => 'raw_material', 'Finished Goods' => 'finished_good', 'Packaging' => 'packaging', 'Spare Parts' => 'spare_part'];
        foreach ($children->get(null, collect()) as $root) {
            $ids = [$root->id];
            for ($index = 0; $index < count($ids); $index++) {
                foreach ($children->get($ids[$index], collect()) as $child) {
                    if (! in_array($child->id, $ids, true)) $ids[] = $child->id;
                }
            }
            $usedTypes = collect($ids)->flatMap(fn ($id) => $types->get($id, collect())->pluck('item_type'))->unique()->values();
            $type = $standard[$root->name] ?? ($usedTypes->count() === 1 ? $usedTypes->first() : null);
            if ($type !== null) DB::table('item_categories')->whereIn('id', $ids)->update(['item_type' => $type]);
        }
    }

    public function down(): void
    {
        Schema::table('item_categories', fn (Blueprint $table) => $table->dropColumn('item_type'));
    }
};
