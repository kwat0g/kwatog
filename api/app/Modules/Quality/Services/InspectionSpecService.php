<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Support\TrashedFilter;
use App\Common\Support\HashIdFilter;
use App\Common\Support\SearchOperator;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Models\InspectionSpecRevision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 7 — Task 59. CRUD for inspection specifications.
 *
 * One spec root per product (DB UNIQUE on inspection_specs.product_id). Every
 * upsert creates a durable revision and appends new item rows. Old item rows
 * are soft-deleted so existing measurements keep their governing definition.
 */
class InspectionSpecService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = InspectionSpec::query()
            // Eager-load constraints receive the Relation, not a Builder, so
            // this closure stays untyped; the row must render even when the
            // product behind it has been soft-deleted.
            ->with(['product' => fn ($product) => $product
                ->withTrashed()
                ->select(['id', 'part_number', 'name', 'is_active', 'deleted_at'])])
            ->withCount('items');

        if (isset($filters['is_active']) && $filters['is_active'] !== ''
            && ! filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN)) {
            $q->withTrashed();
        }
        TrashedFilter::apply($q, $filters);

        if (! empty($filters['product_id'])) {
            $pid = HashIdFilter::decode($filters['product_id'], Product::class);
            if ($pid) $q->where('product_id', $pid);
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $q->whereHas('product', fn (Builder $product): Builder => $product
                ->withTrashed()
                ->where('part_number', SearchOperator::like(), $term)
                ->orWhere('name', SearchOperator::like(), $term));
        }

        return $q->orderByDesc('updated_at')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(InspectionSpec $spec): InspectionSpec
    {
        return $spec->load([
            'product' => fn ($product) => $product
                ->withTrashed()
                ->select(['id', 'part_number', 'name', 'is_active', 'deleted_at']),
            'items',
            'creator:id,name,role_id',
            'currentRevision.creator:id,name,role_id',
        ]);
    }

    /**
     * Return every immutable revision, including its historical item rows.
     * Soft-deleted item rows are intentional here: they are the definition
     * that governed completed inspection evidence.
     */
    public function revisions(InspectionSpec $spec): Collection
    {
        return $spec->revisions()
            ->with([
                'spec:id,version',
                'creator:id,name,role_id',
                'items',
            ])
            ->orderByDesc('version')
            ->get();
    }

    public function revision(InspectionSpec $spec, InspectionSpecRevision $revision): InspectionSpecRevision
    {
        return InspectionSpecRevision::query()
            ->whereKey($revision->id)
            ->where('inspection_spec_id', $spec->id)
            ->with([
                'spec:id,version',
                'creator:id,name,role_id',
                'items',
            ])
            ->firstOrFail();
    }

    public function forProduct(int $productId): ?InspectionSpec
    {
        return InspectionSpec::with([
                'product' => fn ($product) => $product
                    ->withTrashed()
                    ->select(['id', 'part_number', 'name', 'is_active', 'deleted_at']),
                'items',
                'currentRevision.creator:id,name,role_id',
            ])
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Create-or-revise entry-point used by the spec editor.
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
            $spec = InspectionSpec::withTrashed()
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if ($spec) {
                if ($spec->trashed()) {
                    $spec->restore();
                }
                $spec->update([
                    'version'   => $spec->version + 1,
                    'is_active' => true,
                    'notes'     => $notes,
                ]);
            } else {
                $spec = InspectionSpec::create([
                    'product_id' => $productId,
                    'version'    => 1,
                    'is_active'  => true,
                    'notes'      => $notes,
                    'created_by' => $userId,
                ]);
            }

            $revision = InspectionSpecRevision::create([
                'inspection_spec_id' => $spec->id,
                'version'            => $spec->version,
                'created_by'         => $userId,
                'notes'              => $notes,
            ]);

            $currentItems = InspectionSpecItem::query()
                ->where('inspection_spec_id', $spec->id)
                ->lockForUpdate()
                ->get();
            $currentItems->each(static fn (InspectionSpecItem $item): bool => (bool) $item->delete());

            foreach ($items as $i => $row) {
                InspectionSpecItem::create([
                    'inspection_spec_id' => $spec->id,
                    'inspection_spec_revision_id' => $revision->id,
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
        return DB::transaction(function () use ($spec): InspectionSpec {
            $locked = InspectionSpec::withTrashed()->lockForUpdate()->findOrFail($spec->id);
            $locked->update(['is_active' => false]);
            $locked->delete();

            return $this->show($locked);
        });
    }

    public function restore(InspectionSpec $spec): InspectionSpec
    {
        return DB::transaction(function () use ($spec): InspectionSpec {
            $locked = InspectionSpec::withTrashed()->lockForUpdate()->findOrFail($spec->id);
            if ($locked->trashed()) {
                $locked->restore();
            }
            $locked->update(['is_active' => true]);

            return $this->show($locked);
        });
    }
}
