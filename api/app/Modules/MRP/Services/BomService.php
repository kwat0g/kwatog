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

    /** @var int OGAMI-015 — hard cap on BOM nesting depth (cycle / runaway guard). */
    private const MAX_EXPLODE_DEPTH = 10;

    /**
     * Public method used by MRP engine (Task 52): expand a finished-good qty
     * into the required raw-material quantities (gross, including waste).
     *
     * OGAMI-015 — multi-level explosion. When a BOM line's component item is
     * itself a manufactured product (i.e. a CRM Product whose part_number
     * equals the item code AND which carries its own active BOM), the line is
     * recursively exploded down to raw materials. Each level multiplies the
     * running quantity and applies that level's waste factor (already folded
     * into BomItem::effective_quantity). Leaf raw materials are aggregated so
     * the same raw material reached through different sub-assemblies collapses
     * into a single requirement row. Single-level BOMs behave identically to
     * the previous implementation.
     *
     * @return Collection<int, array{item_id: int, item_code: string, item_name: string, gross_quantity: string}>
     */
    public function explode(int $productId, float $finishedQuantity): Collection
    {
        $bom = $this->activeForProduct($productId);
        if (! $bom) {
            throw new RuntimeException('No active BOM exists for the requested product.');
        }

        // [item_id => ['item_id' => int, 'item_code' => string, 'item_name' => string, 'qty' => float]]
        $accumulator = [];
        $this->explodeInto($bom, $finishedQuantity, $accumulator, [$productId], 0);

        return collect(array_values($accumulator))->map(fn (array $row) => [
            'item_id'        => $row['item_id'],
            'item_code'      => $row['item_code'],
            'item_name'      => $row['item_name'],
            'gross_quantity' => number_format($row['qty'], 3, '.', ''),
        ]);
    }

    /**
     * Recursive worker. Walks every line of $bom, multiplying $multiplier by
     * each line's effective (waste-inclusive, unit-converted) quantity. A line
     * whose component item maps to a manufactured product with its own active
     * BOM recurses; otherwise it is treated as a raw-material leaf and added to
     * $accumulator.
     *
     * @param array<int, array{item_id:int,item_code:string,item_name:string,qty:float}> $accumulator (by reference)
     * @param list<int> $productPath chain of product ids currently being expanded (cycle detection)
     */
    private function explodeInto(Bom $bom, float $multiplier, array &$accumulator, array $productPath, int $depth): void
    {
        if ($depth > self::MAX_EXPLODE_DEPTH) {
            throw new RuntimeException(
                'BOM explosion exceeded the maximum nesting depth of '
                . self::MAX_EXPLODE_DEPTH . ' — check for a circular bill of materials.'
            );
        }

        foreach ($bom->items as $row) {
            $effective = (float) $row->effective_quantity;
            $gross = $effective * $multiplier;

            // OGAMI-004 — convert the line quantity to the item base uom when a
            // conversion row is configured. Identity otherwise (missing
            // conversion falls back to the authored quantity rather than
            // throwing, preserving legacy-BOM behaviour).
            $grossStr = number_format($gross, 6, '.', '');
            if ($row->item && ! empty($row->unit)) {
                try {
                    $grossStr = $row->item->convertToBase($grossStr, (string) $row->unit);
                } catch (RuntimeException) {
                    // No conversion configured — leave authored quantity as-is.
                }
            }
            $grossFloat = (float) $grossStr;

            // OGAMI-015 — does this component item resolve to a manufactured
            // sub-assembly with its own active BOM? Convention: the CRM Product
            // whose part_number matches the item code is the manufactured form.
            $subBom = $this->subAssemblyBomFor($row->item?->code);

            if ($subBom !== null) {
                if (in_array($subBom->product_id, $productPath, true)) {
                    throw new RuntimeException(
                        'Circular bill of materials detected while exploding product '
                        . $subBom->product_id . ' (item ' . ($row->item?->code ?? '?') . ').'
                    );
                }
                $this->explodeInto(
                    $subBom,
                    $grossFloat,
                    $accumulator,
                    array_merge($productPath, [$subBom->product_id]),
                    $depth + 1,
                );
                continue;
            }

            // Raw-material leaf — aggregate by item_id.
            $iid = (int) $row->item_id;
            if (! isset($accumulator[$iid])) {
                $accumulator[$iid] = [
                    'item_id'   => $iid,
                    'item_code' => (string) $row->item?->code,
                    'item_name' => (string) $row->item?->name,
                    'qty'       => 0.0,
                ];
            }
            $accumulator[$iid]['qty'] += $grossFloat;
        }
    }

    /**
     * OGAMI-015 — resolve the active BOM for a sub-assembly identified by the
     * component item code. Returns null when no manufactured product matches
     * the code or the matched product has no active BOM (i.e. a pure raw
     * material). Results memoised per request to avoid repeated lookups when
     * the same sub-assembly appears across many lines.
     *
     * @var array<string, Bom|null>
     */
    private array $subAssemblyCache = [];

    private function subAssemblyBomFor(?string $itemCode): ?Bom
    {
        if ($itemCode === null || $itemCode === '') {
            return null;
        }
        if (array_key_exists($itemCode, $this->subAssemblyCache)) {
            return $this->subAssemblyCache[$itemCode];
        }

        $product = Product::where('part_number', $itemCode)->first();
        $bom = $product
            ? Bom::with(['items.item:id,code,name,unit_of_measure,item_type'])
                ->where('product_id', $product->id)
                ->active()
                ->first()
            : null;

        return $this->subAssemblyCache[$itemCode] = $bom;
    }
}
