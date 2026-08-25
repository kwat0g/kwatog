<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\OutboxService;
use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Enums\MoldStatus;
use App\Modules\MRP\Events\MrpReplanRequested;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Services\BomCostingService;
use App\Modules\MRP\Services\MrpScopeResolver;
use App\Modules\Production\Exceptions\RoutingVersionConflictException;
use App\Modules\Production\Models\ProductRouting;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Sprint P10 — Task 10. Product routing CRUD + duplicate-as-new-version.
 *
 * Routings define the sequence of operations required to manufacture a
 * product. Each published routing is immutable; creating, editing, or
 * duplicating publishes the next version and leaves one active version.
 *
 * Lifecycle decision (M052 item 5). A routing is a traceability record: it is
 * the source definition for work-order operations and for the conversion cost
 * on a BOM. There is therefore NO delete/archive endpoint. History is kept by
 * publishing immutable versions, and a superseded version is put back into
 * service with `activate()`, which is audited like every other write via
 * `HasAuditLog` on the routing and its operations. Soft deletes are
 * deliberately not enabled on these models even though migration 0444 added
 * the columns: a soft-deleted row still occupies the
 * `product_routings_one_active_per_product_unique` partial index, so
 * "archiving" the active version would block the next publish.
 */
class ProductionRoutingService
{
    /**
     * Paginated listing with optional filters.
     *
     * @param array{product_id?: string, search?: string, is_active?: string|bool, per_page?: int} $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $q = ProductRouting::query()->with(['operations', 'product:id,part_number,name']);

        if (! empty($filters['product_id'])) {
            $decoded = \App\Common\Support\HashIdFilter::decode(
                $filters['product_id'],
                Product::class,
            );
            if ($decoded) {
                $q->where('product_id', $decoded);
            }
        }

        if (! empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            if ($term !== '') {
                $q->whereHas('product', fn ($product) => $product
                    ->where('part_number', 'ilike', "%{$term}%")
                    ->orWhere('name', 'ilike', "%{$term}%"));
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
        try {
            return DB::transaction(function () use ($data) {
                $product = $this->lockActiveProduct((int) ($data['product_id'] ?? 0));
                $this->assertRoutingDefinitionValid($product, $data['operations'] ?? []);
                $routing = $this->createVersion($product, $data);

                $this->propagateRoutingChange($routing, 'routing_created');

                return $this->show($routing->fresh());
            });
        } catch (QueryException $e) {
            $this->throwIfRoutingVersionConflict($e);
            throw $e;
        }
    }

    /**
     * Load a single routing with all relationships.
     */
    public function show(ProductRouting $routing): ProductRouting
    {
        return $routing->load(['operations.machine:id,machine_code,name', 'operations.mold:id,mold_code,name', 'product:id,part_number,name']);
    }

    /**
     * Publish an edited routing as a new immutable version.
     *
     * The version being edited is never rewritten. Work orders hold a foreign
     * key to its operation rows, so mutating them in place would sever the
     * provenance of work that has already been generated.
     *
     * @param array $data Validated payload.
     */
    public function update(ProductRouting $routing, array $data): ProductRouting
    {
        try {
            return DB::transaction(function () use ($routing, $data) {
                $product = $this->lockActiveProduct((int) $routing->product_id);

                // Re-read under the product lock. Checking the caller's
                // in-memory copy would let two editors who both loaded the
                // same active version each publish, and the loser would
                // silently overwrite a change it never saw.
                $row = ProductRouting::query()
                    ->whereKey($routing->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $row->is_active) {
                    throw new BusinessRuleException(
                        'Only the active routing can be edited. This version was superseded — reload it, then duplicate the current version to publish your change.'
                    );
                }

                $this->assertRoutingDefinitionValid($product, $data['operations'] ?? []);

                $newRouting = $this->createVersion($product, [
                    'notes' => $data['notes'] ?? $row->notes,
                    'operations' => $data['operations'],
                ]);

                $this->propagateRoutingChange($newRouting, 'routing_updated');

                return $this->show($newRouting->fresh());
            });
        } catch (QueryException $e) {
            $this->throwIfRoutingVersionConflict($e);
            throw $e;
        }
    }

    /**
     * Duplicate a routing as a new version.
     *
     * The source remains available as an immutable historical version while
     * the new copy becomes the only active version for the product.
     */
    public function duplicate(ProductRouting $routing): ProductRouting
    {
        try {
            return DB::transaction(function () use ($routing) {
                $product = $this->lockActiveProduct((int) $routing->product_id);
                $source = ProductRouting::query()
                    ->whereKey($routing->getKey())
                    ->with('operations')
                    ->lockForUpdate()
                    ->firstOrFail();
                $operations = $this->snapshotOperations($source);

                $this->assertRoutingDefinitionValid($product, $operations);
                $newRouting = $this->createVersion($product, [
                    'notes' => $source->notes,
                    'operations' => $operations,
                ]);

                $this->propagateRoutingChange($newRouting, 'routing_duplicated');

                return $this->show($newRouting->fresh());
            });
        } catch (QueryException $e) {
            $this->throwIfRoutingVersionConflict($e);
            throw $e;
        }
    }

    /**
     * Put a superseded version back into service.
     *
     * This is the supported way to roll back a routing change: the version
     * number is history, so it is not renumbered, and the currently active
     * version is deactivated under the same product lock that guards a
     * publish. The stored definition is re-validated first — a version whose
     * mold was since decommissioned must not silently become the definition
     * that work orders and BOM costing read.
     */
    public function activate(ProductRouting $routing): ProductRouting
    {
        try {
            return DB::transaction(function () use ($routing) {
                $product = $this->lockActiveProduct((int) $routing->product_id);

                $row = ProductRouting::query()
                    ->whereKey($routing->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($row->is_active) {
                    return $this->show($row);
                }

                $row->load('operations');
                $this->assertRoutingDefinitionValid($product, $this->snapshotOperations($row));

                ProductRouting::query()
                    ->where('product_id', $product->id)
                    ->where('is_active', true)
                    ->whereKeyNot($row->getKey())
                    ->get()
                    ->each(function (ProductRouting $active): void {
                        $active->is_active = false;
                        $active->save();
                    });

                $row->is_active = true;
                $row->save();

                $this->propagateRoutingChange($row, 'routing_activated');

                return $this->show($row->fresh());
            });
        } catch (QueryException $e) {
            $this->throwIfRoutingVersionConflict($e);
            throw $e;
        }
    }

    /**
     * Plain-array snapshot of a routing's operations, in the shape the
     * validator and `createVersion()` consume.
     *
     * @return list<array<string, mixed>>
     */
    private function snapshotOperations(ProductRouting $routing): array
    {
        return $routing->operations->map(fn ($op) => [
            'sequence'               => $op->sequence,
            'operation_name'         => $op->operation_name,
            'work_center'            => $op->work_center,
            'machine_id'             => $op->machine_id,
            'mold_id'                => $op->mold_id,
            'setup_time_minutes'     => $op->setup_time_minutes,
            'cycle_time_minutes'     => $op->cycle_time_minutes,
            'labor_rate_per_hour'    => $op->labor_rate_per_hour,
            'machine_rate_per_hour'  => $op->machine_rate_per_hour,
            'overhead_rate_per_hour' => $op->overhead_rate_per_hour,
            'description'            => $op->description,
            'qc_required'            => $op->qc_required,
        ])->values()->all();
    }

    private function createVersion(Product $product, array $data): ProductRouting
    {
        // Product row locking serializes all routing writers for this product.
        // Deactivate before inserting because the partial unique index is
        // intentionally immediate, not deferred until transaction commit.
        $this->supersedeActiveVersions((int) $product->id);

        $maxVersion = ProductRouting::query()
            ->where('product_id', $product->id)
            ->max('version') ?? 0;

        // Total the operations before the insert so the version is written
        // once. A create followed by an update would log two audit rows for
        // one logical action.
        $totalCycleTime = '0';
        foreach ($data['operations'] as $opData) {
            $totalCycleTime = bcadd($totalCycleTime, (string) $opData['cycle_time_minutes'], 2);
        }

        $routing = ProductRouting::create([
            'product_id'       => $product->id,
            'version'          => $maxVersion + 1,
            'is_active'        => true,
            'total_cycle_time' => $totalCycleTime,
            'notes'            => $data['notes'] ?? null,
        ]);

        foreach ($data['operations'] as $opData) {
            $routing->operations()->create($this->operationAttributes($opData));
        }

        return $routing;
    }

    /**
     * Retire whichever version is currently in service.
     *
     * Saved row by row rather than with a mass `update()`: an Eloquent mass
     * update fires no model events, so `HasAuditLog` would record the version
     * that took over but not the one it displaced. The partial unique index
     * guarantees there is at most one row to walk.
     */
    private function supersedeActiveVersions(int $productId): void
    {
        ProductRouting::query()
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->get()
            ->each(function (ProductRouting $row): void {
                $row->is_active = false;
                $row->save();
            });
    }

    /** @return array<string, mixed> */
    private function operationAttributes(array $opData): array
    {
        return [
            'sequence'              => (int) $opData['sequence'],
            'operation_name'        => $opData['operation_name'],
            'work_center'          => $opData['work_center'] ?? null,
            'machine_id'            => $opData['machine_id'] ?? null,
            'mold_id'               => $opData['mold_id'] ?? null,
            'setup_time_minutes'   => $opData['setup_time_minutes'] ?? 0,
            'cycle_time_minutes'   => $opData['cycle_time_minutes'],
            'labor_rate_per_hour'  => $opData['labor_rate_per_hour'] ?? 0,
            'machine_rate_per_hour' => $opData['machine_rate_per_hour'] ?? 0,
            'overhead_rate_per_hour' => $opData['overhead_rate_per_hour'] ?? 0,
            'description'          => $opData['description'] ?? null,
            'qc_required'          => $opData['qc_required'] ?? false,
        ];
    }

    private function lockActiveProduct(int $productId): Product
    {
        if ($productId < 1) {
            throw new BusinessRuleException('A valid active product is required for a routing.');
        }

        $product = Product::query()
            ->whereKey($productId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if ($product === null) {
            throw new BusinessRuleException('The selected product does not exist, is inactive, or is archived.');
        }

        return $product;
    }

    /**
     * Apply the same definition rules to HTTP requests and direct service
     * callers. The request provides field-level feedback; this guard protects
     * imports, jobs, and other callers that bypass FormRequest validation.
     */
    private function assertRoutingDefinitionValid(Product $product, array $operations): void
    {
        if ($operations === []) {
            throw new BusinessRuleException('A routing requires at least one operation.');
        }

        $sequences = [];
        $machineIds = [];
        $moldIds = [];
        $totalCycleTime = '0';

        foreach ($operations as $index => $operation) {
            if (! is_array($operation)) {
                throw new BusinessRuleException("Operation row {$index} is invalid.");
            }

            $sequence = $operation['sequence'] ?? null;
            if (! is_int($sequence) && ! (is_string($sequence) && preg_match('/^\d+$/D', $sequence))) {
                throw new BusinessRuleException("Operation row {$index} must have a positive integer sequence.");
            }
            $sequence = (int) $sequence;
            if ($sequence < 1 || isset($sequences[$sequence])) {
                throw new BusinessRuleException("Operation row {$index} must have a unique positive sequence.");
            }
            $sequences[$sequence] = true;

            $name = trim((string) ($operation['operation_name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 100) {
                throw new BusinessRuleException("Operation row {$index} must have a name of 100 characters or fewer.");
            }
            if (mb_strlen((string) ($operation['work_center'] ?? '')) > 100) {
                throw new BusinessRuleException("Operation row {$index} has a work center longer than 100 characters.");
            }
            if (mb_strlen((string) ($operation['description'] ?? '')) > 500) {
                throw new BusinessRuleException("Operation row {$index} has a description longer than 500 characters.");
            }

            $this->assertDecimal($operation['setup_time_minutes'] ?? '0', 2, 'setup time', '999999.99', $index);
            $cycleTime = $this->assertDecimal($operation['cycle_time_minutes'] ?? null, 2, 'cycle time', '999999.99', $index);
            if (bccomp($cycleTime, '0', 2) <= 0) {
                throw new BusinessRuleException("Operation row {$index} must have a positive cycle time.");
            }
            $totalCycleTime = bcadd($totalCycleTime, $cycleTime, 2);
            if (bccomp($totalCycleTime, '99999999.99', 2) > 0) {
                throw new BusinessRuleException('The routing total cycle time exceeds the supported maximum.');
            }
            $this->assertDecimal($operation['labor_rate_per_hour'] ?? '0', 4, 'labor rate', '99999999999.9999', $index);
            $this->assertDecimal($operation['machine_rate_per_hour'] ?? '0', 4, 'machine rate', '99999999999.9999', $index);
            $this->assertDecimal($operation['overhead_rate_per_hour'] ?? '0', 4, 'overhead rate', '99999999999.9999', $index);

            $machineId = $this->nullablePositiveInteger($operation['machine_id'] ?? null, 'machine', $index);
            $moldId = $this->nullablePositiveInteger($operation['mold_id'] ?? null, 'mold', $index);
            if ($machineId !== null) $machineIds[$machineId] = true;
            if ($moldId !== null) $moldIds[$moldId] = true;
        }

        $machines = Machine::query()->whereIn('id', array_keys($machineIds))->get()->keyBy('id');
        $molds = Mold::query()->whereIn('id', array_keys($moldIds))->get()->keyBy('id');

        foreach ($operations as $index => $operation) {
            $machineId = $this->nullablePositiveInteger($operation['machine_id'] ?? null, 'machine', $index);
            $moldId = $this->nullablePositiveInteger($operation['mold_id'] ?? null, 'mold', $index);
            $machine = $machineId === null ? null : $machines->get($machineId);
            $mold = $moldId === null ? null : $molds->get($moldId);

            if ($machineId !== null && $machine === null) {
                throw new BusinessRuleException("Operation row {$index} references a missing or archived machine.");
            }
            if ($moldId !== null && $mold === null) {
                throw new BusinessRuleException("Operation row {$index} references a missing or archived mold.");
            }
            if ($machine !== null && ! in_array($machine->status, [MachineStatus::Idle, MachineStatus::Running], true)) {
                throw new BusinessRuleException("Operation row {$index} references a machine that is not usable for scheduling.");
            }
            if ($mold !== null) {
                if ((int) $mold->product_id !== (int) $product->id) {
                    throw new BusinessRuleException("Operation row {$index} references a mold configured for another product.");
                }
                if (! in_array($mold->status, [MoldStatus::Available, MoldStatus::InUse], true)) {
                    throw new BusinessRuleException("Operation row {$index} references a mold that is not usable for scheduling.");
                }
            }
            if ($machine !== null && $mold !== null && ! $mold->compatibleMachines()->whereKey($machine->id)->exists()) {
                throw new BusinessRuleException("Operation row {$index} assigns an incompatible machine and mold.");
            }
        }
    }

    private function nullablePositiveInteger(mixed $value, string $label, int $index): ?int
    {
        if ($value === null || $value === '') return null;
        if (! is_int($value) && ! (is_string($value) && preg_match('/^\d+$/D', $value))) {
            throw new BusinessRuleException("Operation row {$index} has an invalid {$label}.");
        }
        $id = (int) $value;
        if ($id < 1) {
            throw new BusinessRuleException("Operation row {$index} has an invalid {$label}.");
        }
        return $id;
    }

    private function assertDecimal(mixed $value, int $scale, string $label, string $maximum, int $index): string
    {
        if ($value === null || $value === '') $value = '0';
        $value = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d+)?$/D', $value)) {
            throw new BusinessRuleException("Operation row {$index} has an invalid {$label}.");
        }
        $fraction = str_contains($value, '.') ? substr(strrchr($value, '.'), 1) : '';
        if (strlen($fraction) > $scale) {
            throw new BusinessRuleException("Operation row {$index} {$label} supports at most {$scale} decimal places.");
        }
        if (bccomp($value, $maximum, $scale) > 0) {
            throw new BusinessRuleException("Operation row {$index} {$label} exceeds the supported maximum.");
        }
        return $value;
    }

    private function throwIfRoutingVersionConflict(QueryException $exception): void
    {
        if ($this->isRoutingVersionConflict($exception)) {
            throw new RoutingVersionConflictException(previous: $exception);
        }
    }

    private function isRoutingVersionConflict(QueryException $exception): bool
    {
        $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        if (! in_array($state, ['23000', '23505'], true)) return false;

        return str_contains($exception->getMessage(), 'product_routings');
    }

    /**
     * Downstream contract for a committed routing change (M052 item 4).
     *
     * A routing carries the cycle/setup time and the labor, machine and
     * overhead rates, so publishing one changes both the BOM's conversion
     * cost and the capacity that pending plans were built from. The BOM is
     * recalculated inline because costing reads the routing directly. The
     * replan is durable rather than inline: it is recorded in the same
     * transaction as the routing rows, keyed by the new routing id, so a
     * crashed queue worker cannot lose it and a retry cannot double-plan.
     */
    private function propagateRoutingChange(ProductRouting $routing, string $reason): void
    {
        $this->recalculateActiveBom((int) $routing->product_id);
        $this->requestAutomaticReplan($routing, $reason);
    }

    private function requestAutomaticReplan(ProductRouting $routing, string $reason): void
    {
        $salesOrderIds = app(MrpScopeResolver::class)->salesOrderIdsForProduct((int) $routing->product_id);
        if ($salesOrderIds === []) {
            return;
        }

        app(OutboxService::class)->record(
            new MrpReplanRequested($salesOrderIds, $reason, auth()->id()),
            'mrp:replan:routing:'.$routing->getKey().':'.$reason,
        );
    }

    private function recalculateActiveBom(int $productId): void
    {
        $bom = Bom::query()
            ->where('product_id', $productId)
            ->active()
            ->first();

        if ($bom !== null) {
            app(BomCostingService::class)->recalculate($bom);
        }
    }
}
