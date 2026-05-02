<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Support\SearchOperator;
use App\Modules\CRM\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = Product::query();

        // Subquery: does this product have an active BOM? (Task 49 — gracefully
        // handles the case where bill_of_materials table doesn't yet exist.)
        $hasBomSubquery = "(SELECT 1 FROM bill_of_materials b
                           WHERE b.product_id = products.id AND b.is_active = true LIMIT 1)";
        $q->selectRaw("products.*, COALESCE(({$hasBomSubquery}), 0) as has_bom_flag");

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('part_number', SearchOperator::like(), "%{$term}%")
                   ->orWhere('name', SearchOperator::like(), "%{$term}%");
            });
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($filters['has_bom']) && $filters['has_bom'] !== '') {
            $wantsBom = filter_var($filters['has_bom'], FILTER_VALIDATE_BOOLEAN);
            $q->whereRaw(($wantsBom ? '' : 'NOT ') . "EXISTS {$hasBomSubquery}");
        }

        return $q->orderBy('part_number')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(Product $product): Product
    {
        return $product->fresh();
    }

    public function create(array $data): Product
    {
        return DB::transaction(fn () => Product::create($data));
    }

    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $product->update($data);
            return $product->fresh();
        });
    }

    public function delete(Product $product): void
    {
        // Block deletion if any sales orders or BOMs reference this product.
        if ($product->salesOrderItems()->exists()) {
            throw new RuntimeException('Cannot delete a product that appears on sales orders. Deactivate it instead.');
        }
        $product->delete();
    }
}
