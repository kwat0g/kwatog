<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\SearchOperator;
use App\Common\Support\TrashedFilter;
use App\Modules\CRM\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = Product::query();

        TrashedFilter::apply($q, $filters);

        // The MRP and Quality tables are optional from CRM's point of view.
        // Enrich the response when their schemas are present, but keep product
        // CRUD usable during an install or migration window where they are not.
        $hasBomSchema = $this->hasBomSchema();
        if ($hasBomSchema) {
            $hasBomSubquery = "(SELECT 1 FROM bill_of_materials b
                               WHERE b.product_id = products.id
                                 AND b.is_active = true
                                 AND b.deleted_at IS NULL
                               LIMIT 1)";
            $q->selectRaw("products.*, COALESCE(({$hasBomSubquery}), 0) as has_bom_flag");
        } else {
            $q->select('products.*');
        }

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
            if ($hasBomSchema) {
                $q->whereRaw(($wantsBom ? '' : 'NOT ') . "EXISTS {$hasBomSubquery}");
            } elseif ($wantsBom) {
                $q->whereRaw('1 = 0');
            }
        }

        return $q->orderBy('part_number')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(Product $product): Product
    {
        $relations = [];
        if ($this->hasBomSchema()) {
            $relations[] = 'activeBom:id,product_id,version,is_active';
        }
        if ($this->hasInspectionSpecSchema()) {
            $relations[] = 'inspectionSpec:id,product_id,version,is_active,updated_at';
        }

        return $relations === [] ? $product : $product->load($relations);
    }

    public function create(array $data): Product
    {
        return DB::transaction(fn () => Product::create($data));
    }

    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $locked->update($data);
            return $locked->fresh();
        });
    }

    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $dependencies = [];

            if ($locked->salesOrderItems()->exists()) {
                $dependencies[] = 'sales orders';
            }
            if ($this->hasBomSchema() && \App\Modules\MRP\Models\Bom::query()
                ->where('product_id', $locked->id)
                ->active()
                ->exists()) {
                $dependencies[] = 'an active BOM';
            }
            if ($locked->priceAgreements()->exists()) {
                $dependencies[] = 'price agreements';
            }

            if ($dependencies !== []) {
                throw new BusinessRuleException(sprintf(
                    'Cannot archive this product while it has %s. Archive or retire the dependent records first.',
                    $this->joinDependencies($dependencies),
                ));
            }

            $locked->delete();
        });
    }

    public function restore(Product $product): Product
    {
        return DB::transaction(function () use ($product): Product {
            $locked = Product::withTrashed()->lockForUpdate()->findOrFail($product->id);
            if ($locked->trashed()) {
                $locked->restore();
            }

            return $locked->fresh();
        });
    }

    private function hasBomSchema(): bool
    {
        return class_exists(\App\Modules\MRP\Models\Bom::class)
            && Schema::hasTable('bill_of_materials')
            && Schema::hasColumn('bill_of_materials', 'deleted_at');
    }

    private function hasInspectionSpecSchema(): bool
    {
        return class_exists(\App\Modules\Quality\Models\InspectionSpec::class)
            && Schema::hasTable('inspection_specs');
    }

    /** @param list<string> $dependencies */
    private function joinDependencies(array $dependencies): string
    {
        if (count($dependencies) === 1) {
            return $dependencies[0];
        }

        $last = array_pop($dependencies);
        return implode(', ', $dependencies) . ' and ' . $last;
    }
}
