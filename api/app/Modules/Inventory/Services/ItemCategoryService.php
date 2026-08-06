<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\TrashedFilter;
use App\Modules\Inventory\Models\ItemCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ItemCategoryService
{
    public function tree(): Collection
    {
        // M-27 — load all rows in one query, then build any-depth tree in PHP.
        $all = ItemCategory::query()->orderBy('name')->get();
        $byParent = $all->groupBy('parent_id');

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
        return DB::transaction(fn () => ItemCategory::create($data)->load('parent'));
    }

    public function update(ItemCategory $cat, array $data): ItemCategory
    {
        return DB::transaction(function () use ($cat, $data) {
            $cat->update($data);
            return $cat->fresh(['parent']);
        });
    }

    public function delete(ItemCategory $cat): void
    {
        if ($cat->children()->exists()) {
            throw new BusinessRuleException('Cannot delete a category with sub-categories.');
        }
        if ($cat->items()->exists()) {
            throw new BusinessRuleException('Cannot delete a category with items.');
        }
        $cat->delete();
    }
}
