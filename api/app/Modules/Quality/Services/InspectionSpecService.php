<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Support\HashIdFilter;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 7 — Task 59. CRUD for inspection specifications.
 *
 * One spec per product (DB UNIQUE on inspection_specs.product_id). The
 * upsertForProduct() entry-point handles "create OR replace items" in a
 * single transaction so the page can be saved with a single POST.
 *
 * Items are wholesale-replaced on each upsert (delete + insert ordered by
 * sort_order). The version counter increments on every upsert so QC can
 * audit how a product's tolerances drifted over time. Older versions are
 * not retained — Sprint 8 audit programme has a separate ticket for full
 * spec lineage if/when ISO/IATF surveillance demands it.
 */
class InspectionSpecService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = InspectionSpec::query()
            ->with(['product:id,part_number,name'])
            ->withCount('items');

        if (! empty($filters['product_id'])) {
            $pid = HashIdFilter::decode($filters['product_id'], Product::class);
            if ($pid) $q->where('product_id', $pid);
        }
        if (isset($filters['is_active'])) {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $q->orderByDesc('updated_at')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(InspectionSpec $spec): InspectionSpec
    {
        return $spec->load(['product:id,part_number,name', 'items', 'creator:id,name']);
    }

    public function forProduct(int $productId): ?InspectionSpec
    {
        return InspectionSpec::with(['product:id,part_number,name', 'items'])
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Idempotent "create or replace" entry-point used by the spec editor.
     * Bumps version on every save so the audit trail shows when tolerances
     * changed.
     *
     * @param array<int, array{
     *   parameter_name: string, parameter_type: string,
     *   unit_of_measure?: ?string, nominal_value?: ?string,
     *   tolerance_min?: ?string, tolerance_max?: ?string,
     *   is_critical?: bool, sort_order?: int, notes?: ?string
     * }> $items
     */
    public function upsertForProduct(int $productId, array $items, int $userId, ?string $notes = null): InspectionSpec
    {
        return DB::transaction(function () use ($productId, $items, $userId, $notes) {
            $spec = InspectionSpec::where('product_id', $productId)->lockForUpdate()->first();

            if ($spec) {
                $spec->update([
                    'version'   => $spec->version + 1,
                    'is_active' => true,
                    'notes'     => $notes,
                ]);
                $spec->items()->delete();
            } else {
                $spec = InspectionSpec::create([
                    'product_id' => $productId,
                    'version'    => 1,
                    'is_active'  => true,
                    'notes'      => $notes,
                    'created_by' => $userId,
                ]);
            }

            foreach ($items as $i => $row) {
                InspectionSpecItem::create([
                    'inspection_spec_id' => $spec->id,
                    'parameter_name'     => $row['parameter_name'],
                    'parameter_type'     => $row['parameter_type'],
                    'unit_of_measure'    => $row['unit_of_measure'] ?? null,
                    'nominal_value'      => $row['nominal_value'] ?? null,
                    'tolerance_min'      => $row['tolerance_min'] ?? null,
                    'tolerance_max'      => $row['tolerance_max'] ?? null,
                    'is_critical'        => $row['is_critical'] ?? false,
                    'sort_order'         => (int) ($row['sort_order'] ?? $i),
                    'notes'              => $row['notes'] ?? null,
                ]);
            }

            return $this->show($spec->fresh());
        });
    }

    public function deactivate(InspectionSpec $spec): InspectionSpec
    {
        $spec->update(['is_active' => false]);
        return $spec->fresh();
    }
}
