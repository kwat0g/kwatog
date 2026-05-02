<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\ItemCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ItemCategoryService
{
    public function tree(): Collection
    {
        return ItemCategory::query()
            ->with(['children.children.children'])
            ->whereNull('parent_id')
            ->orderBy('name')
            ->get();
    }

    public function list(): Collection
    {
        return ItemCategory::query()->orderBy('name')->get();
    }

    public function create(array $data): ItemCategory
    {
        return DB::transaction(fn () => ItemCategory::create($data));
    }

    public function update(ItemCategory $cat, array $data): ItemCategory
    {
        return DB::transaction(function () use ($cat, $data) {
            $cat->update($data);
            return $cat->fresh();
        });
    }

    public function delete(ItemCategory $cat): void
    {
        if ($cat->children()->exists()) {
            throw new RuntimeException('Cannot delete a category with sub-categories.');
        }
        if ($cat->items()->exists()) {
            throw new RuntimeException('Cannot delete a category with items.');
        }
        $cat->delete();
    }
}
