<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\ProductRouting;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Sprint P10 — Task 10. Product routing CRUD + duplicate-as-new-version.
 *
 * Routings define the sequence of operations required to manufacture a
 * product. Each routing has a version; duplicating creates the next
 * version and deactivates the previous one.
 */
class ProductionRoutingService
{
    /**
     * Paginated listing with optional filters.
     *
     * @param array{product_id?: string, is_active?: string, per_page?: int} $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $q = ProductRouting::query()->with(['operations', 'product:id,part_number,name']);

        if (! empty($filters['product_id'])) {
            $decoded = \App\Common\Support\HashIdFilter::decode(
                $filters['product_id'],
                \App\Modules\CRM\Models\Product::class,
            );
            if ($decoded) {
                $q->where('product_id', $decoded);
            }
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $q->orderByDesc('created_at')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    /**
     * Create a routing with its operations in a single transaction.
     *
     * @param array $data Validated payload with 'product_id', 'notes', 'operations'.
     */
    public function create(array $data): ProductRouting
    {
        return DB::transaction(function () use ($data) {
            // Determine the version number for this product.
            $maxVersion = ProductRouting::where('product_id', $data['product_id'])
                ->max('version') ?? 0;

            $routing = ProductRouting::create([
                'product_id'       => (int) $data['product_id'],
                'version'          => $maxVersion + 1,
                'is_active'        => true,
                'total_cycle_time' => '0.00',
                'notes'            => $data['notes'] ?? null,
            ]);

            $totalCycleTime = '0';
            foreach ($data['operations'] as $opData) {
                $routing->operations()->create([
                    'sequence'            => (int) $opData['sequence'],
                    'operation_name'      => $opData['operation_name'],
                    'work_center'         => $opData['work_center'] ?? null,
                    'machine_id'          => $opData['machine_id'] ?? null,
                    'mold_id'             => $opData['mold_id'] ?? null,
                    'setup_time_minutes'  => $opData['setup_time_minutes'] ?? null,
                    'cycle_time_minutes'  => $opData['cycle_time_minutes'],
                    'description'         => $opData['description'] ?? null,
                    'qc_required'         => $opData['qc_required'] ?? false,
                ]);
                $totalCycleTime = bcadd($totalCycleTime, (string) $opData['cycle_time_minutes'], 2);
            }

            $routing->update(['total_cycle_time' => $totalCycleTime]);

            return $this->show($routing->fresh());
        });
    }

    /**
     * Load a single routing with all relationships.
     */
    public function show(ProductRouting $routing): ProductRouting
    {
        return $routing->load(['operations.machine:id,machine_code,name', 'operations.mold:id,mold_code,name', 'product:id,part_number,name']);
    }

    /**
     * Update routing fields and sync operations (delete old, create new).
     *
     * @param array $data Validated payload.
     */
    public function update(ProductRouting $routing, array $data): ProductRouting
    {
        return DB::transaction(function () use ($routing, $data) {
            $routing->update([
                'notes' => $data['notes'] ?? $routing->notes,
            ]);

            // Sync operations: delete existing, create new.
            $routing->operations()->delete();

            $totalCycleTime = '0';
            foreach ($data['operations'] as $opData) {
                $routing->operations()->create([
                    'sequence'            => (int) $opData['sequence'],
                    'operation_name'      => $opData['operation_name'],
                    'work_center'         => $opData['work_center'] ?? null,
                    'machine_id'          => $opData['machine_id'] ?? null,
                    'mold_id'             => $opData['mold_id'] ?? null,
                    'setup_time_minutes'  => $opData['setup_time_minutes'] ?? null,
                    'cycle_time_minutes'  => $opData['cycle_time_minutes'],
                    'description'         => $opData['description'] ?? null,
                    'qc_required'         => $opData['qc_required'] ?? false,
                ]);
                $totalCycleTime = bcadd($totalCycleTime, (string) $opData['cycle_time_minutes'], 2);
            }

            $routing->update(['total_cycle_time' => $totalCycleTime]);

            return $this->show($routing->fresh());
        });
    }

    /**
     * Duplicate a routing as a new version.
     *
     * Creates a copy with version = max(version for product) + 1, copies all
     * operations, and deactivates the source routing.
     */
    public function duplicate(ProductRouting $routing): ProductRouting
    {
        return DB::transaction(function () use ($routing) {
            $routing->load('operations');

            $maxVersion = ProductRouting::where('product_id', $routing->product_id)
                ->max('version') ?? 0;

            $newRouting = ProductRouting::create([
                'product_id'       => $routing->product_id,
                'version'          => $maxVersion + 1,
                'is_active'        => true,
                'total_cycle_time' => $routing->total_cycle_time,
                'notes'            => $routing->notes,
            ]);

            foreach ($routing->operations as $op) {
                $newRouting->operations()->create([
                    'sequence'            => $op->sequence,
                    'operation_name'      => $op->operation_name,
                    'work_center'         => $op->work_center,
                    'machine_id'          => $op->machine_id,
                    'mold_id'             => $op->mold_id,
                    'setup_time_minutes'  => $op->setup_time_minutes,
                    'cycle_time_minutes'  => $op->cycle_time_minutes,
                    'description'         => $op->description,
                    'qc_required'         => $op->qc_required,
                ]);
            }

            // Deactivate the source routing.
            $routing->update(['is_active' => false]);

            return $this->show($newRouting->fresh());
        });
    }
}
