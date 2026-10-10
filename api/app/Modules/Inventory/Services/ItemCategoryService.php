<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\TrashedFilter;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Enums\ItemType;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ItemCategoryService
{
    public function tree(): Collection
    {
        // M-27 — load all rows in one query, then build any-depth tree in PHP.
        $all = ItemCategory::query()->orderBy('name')->get();
        $byParent = $all->groupBy('parent_id');
        $byId = $all->keyBy('id');
        foreach ($all as $category) $category->setRelation('parent', $byId->get($category->parent_id));

        $build = function (ItemCategory $node) use (&$build, $byParent) {
            $children = ($byParent[$node->id] ?? collect())
                ->map(fn (ItemCategory $child) => $build($child))
                ->values();
            $node->setRelation('children', $children);
            return $node;
        };

        $roots = ($byParent->get(null) ?? collect())
            ->map(fn (ItemCategory $root) => $build($root))
            ->values();

        return new Collection($roots->all());
    }

    public function list(array $filters = []): Collection
    {
        $q = ItemCategory::query()->with('parent');
        TrashedFilter::apply($q, $filters);
        return $q->orderBy('name')->get();
    }

    public function create(array $data): ItemCategory
    {
        return DB::transaction(function () use ($data) {
            // ponytail: lock the small category catalog; use subtree locks if classification writes become frequent.
            $categories = ItemCategory::withTrashed()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $data['item_type'] = $this->classification($data, null, $categories);
            return ItemCategory::create($data)->load('parent');
        });
    }

    public function update(ItemCategory $cat, array $data): ItemCategory
    {
        return DB::transaction(function () use ($cat, $data) {
            $categories = ItemCategory::withTrashed()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $cat = $categories->get($cat->id);
            $ids = [$cat->id];
            for ($index = 0; $index < count($ids); $index++) {
                foreach ($categories->where('parent_id', $ids[$index]) as $child) {
                    if (! in_array($child->id, $ids, true)) $ids[] = $child->id;
                }
            }
            if (isset($data['parent_id']) && in_array($data['parent_id'], $ids, true)) {
                throw ValidationException::withMessages(['parent_id' => 'A category cannot be moved inside its own descendants.']);
            }
            if (array_key_exists('parent_id', $data) || array_key_exists('item_type', $data)) {
                $type = $this->classification($data, $cat, $categories);
                if ($type !== $cat->item_type && Item::withTrashed()->whereIn('category_id', $ids)->where('item_type', '<>', $type)->exists()) {
                    throw ValidationException::withMessages(['item_type' => 'Existing items in this category or its children use another type. Move those items to a matching category first.']);
                }
                $data['item_type'] = $type;
                foreach ($categories->whereIn('id', $ids) as $descendant) {
                    if ($descendant->id !== $cat->id && $descendant->item_type !== $type) $descendant->update(['item_type' => $type]);
                }
            }
            $cat->update($data);
            return $cat->fresh(['parent']);
        });
    }

    public function delete(ItemCategory $cat): void
    {
        DB::transaction(function () use ($cat): void {
            ItemCategory::withTrashed()->orderBy('id')->lockForUpdate()->get();
            if ($cat->children()->withTrashed()->exists()) {
                throw new BusinessRuleException('Cannot delete a category with sub-categories.');
            }
            if ($cat->items()->withTrashed()->exists()) {
                throw new BusinessRuleException('Cannot delete a category with items.');
            }
            $cat->delete();
        });
    }

    private function classification(array $data, ?ItemCategory $category, Collection $categories): string
    {
        $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $category?->parent_id;
        $parent = $parentId ? $categories->get($parentId) : null;
        if ($parentId && (! $parent || $parent->trashed())) {
            throw ValidationException::withMessages(['parent_id' => 'Selected parent category is unavailable.']);
        }
        $type = $parent ? $parent->item_type : ($data['item_type'] ?? $category?->item_type);
        if (! in_array($type, ItemType::values(), true)) {
            throw ValidationException::withMessages(['item_type' => 'Set an item type for the top-level category first.']);
        }
        if ($parent && isset($data['item_type']) && $data['item_type'] !== $type) {
            throw ValidationException::withMessages(['item_type' => 'Child categories inherit their parent item type.']);
        }
        return $type;
    }
}
