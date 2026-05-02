<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Common\Support\HashIdFilter;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\MRP\Models\Bom;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BomService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = Bom::query()
            ->with(['product:id,part_number,name,unit_of_measure'])
            ->withCount('items');

        if (! empty($filters['product_id'])) {
            $pid = HashIdFilter::decode($filters['product_id'], Product::class);
            if ($pid) $q->where('product_id', $pid);
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $q->orderByDesc('is_active')
            ->orderByDesc('version')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(Bom $bom): Bom
    {
        return $bom->load(['product', 'items.item:id,code,name,unit_of_measure,item_type']);
    }

    public function activeForProduct(int $productId): ?Bom
    {
        return Bom::with(['items.item:id,code,name,unit_of_measure,item_type'])
            ->where('product_id', $productId)
            ->active()
            ->first();
    }

    /**
     * Create a new BOM version. Deactivates the previous active BOM for the
     * same product (preserved for history). Wrapped in a transaction.
     */
    public function create(int $productId, array $itemRows): Bom
    {
        return DB::transaction(function () use ($productId, $itemRows) {
            $previous = Bom::where('product_id', $productId)->lockForUpdate()->orderByDesc('version')->first();

            if ($previous && $previous->is_active) {
                $previous->update(['is_active' => false]);
            }

            $bom = Bom::create([
                'product_id' => $productId,
                'version'    => $previous ? $previous->version + 1 : 1,
                'is_active'  => true,
            ]);

            foreach (array_values($itemRows) as $idx => $row) {
                $bom->items()->create([
                    'item_id'           => (int) $row['item_id'],
                    'quantity_per_unit' => $row['quantity_per_unit'],
                    'unit'              => $row['unit'],
                    'waste_factor'      => $row['waste_factor'] ?? 0,
                    'sort_order'        => $row['sort_order'] ?? $idx,
                ]);
            }

            return $this->show($bom->fresh());
        });
    }

    /** "Edit" creates a new version. Old version stays archived. */
    public function update(Bom $bom, array $itemRows): Bom
    {
        return $this->create($bom->product_id, $itemRows);
    }

    public function delete(Bom $bom): void
    {
        // Preserve audit trail — only allow deleting inactive (historical) versions.
        if ($bom->is_active) {
            throw new RuntimeException('Cannot delete the active BOM. Archive it by creating a new version instead.');
        }
        $bom->delete();
    }

    /**
     * Public method used by MRP engine (Task 52): expand a finished-good qty
     * into the required raw-material quantities (gross, including waste).
     *
     * @return Collection<int, array{item_id: int, item_code: string, item_name: string, gross_quantity: string}>
     */
    public function explode(int $productId, float $finishedQuantity): Collection
    {
        $bom = $this->activeForProduct($productId);
        if (! $bom) {
            throw new RuntimeException('No active BOM exists for the requested product.');
        }
        return $bom->items->map(function ($row) use ($finishedQuantity) {
            $effective = (float) $row->effective_quantity;
            return [
                'item_id'        => (int) $row->item_id,
                'item_code'      => (string) $row->item?->code,
                'item_name'      => (string) $row->item?->name,
                'gross_quantity' => number_format($effective * $finishedQuantity, 3, '.', ''),
            ];
        });
    }
}
