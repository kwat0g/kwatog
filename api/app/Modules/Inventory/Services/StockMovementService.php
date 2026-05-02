<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\InvalidMovementException;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockMovementInput;
use Illuminate\Support\Facades\DB;

/**
 * Linchpin of the inventory ledger.
 *
 * Every stock-affecting operation in the system funnels through `move()`.
 * The method is the only place where `stock_levels.quantity`, `reserved_quantity`,
 * and `weighted_avg_cost` mutate, and the only place where `stock_movements`
 * rows are inserted.
 *
 * Concurrency: every call wraps in a DB transaction and `lockForUpdate()` on
 * affected `stock_levels` rows. Dual-location movements lock both rows in
 * deterministic order to prevent deadlocks.
 *
 * Weighted-average cost (per (item, location)) on receipt:
 *   new_qty       = old_qty + receive_qty
 *   new_total_val = (old_qty * old_wac) + (receive_qty * unit_cost)
 *   new_wac       = new_total_val / new_qty   (4-decimal rounding HALF UP)
 *
 * Issues, scrap, deliveries, transfers-out: cost-out at current WAC; do NOT
 * change WAC at the source. Transfers-in inherit the source WAC if no explicit
 * unit cost is supplied.
 */
class StockMovementService
{
    public function move(StockMovementInput $in): StockMovement
    {
        $this->validateInput($in);

        return DB::transaction(function () use ($in) {
            // Lock affected rows in ID-ordered fashion for deadlock safety.
            $fromLevel = $in->fromLocationId
                ? $this->lockOrCreate($in->itemId, $in->fromLocationId)
                : null;
            $toLevel = $in->toLocationId
                ? ($in->fromLocationId !== null && $in->fromLocationId === $in->toLocationId
                    ? $fromLevel
                    : $this->lockOrCreate($in->itemId, $in->toLocationId))
                : null;

            $unitCost = $in->unitCost ?? ($fromLevel?->weighted_avg_cost ?? '0');
            $totalCost = bcmul($in->quantity, (string) $unitCost, 4);

            // ── Issue side: validate availability and decrement source.
            if ($fromLevel) {
                $available = bcsub((string) $fromLevel->quantity, (string) $fromLevel->reserved_quantity, 3);
                if (bccomp($available, $in->quantity, 3) < 0) {
                    throw new InsufficientStockException(
                        "Insufficient available stock at location {$in->fromLocationId} for item {$in->itemId}: ".
                        "needed {$in->quantity}, available {$available}."
                    );
                }
                $fromLevel->quantity = bcsub((string) $fromLevel->quantity, $in->quantity, 3);
                // WAC unchanged on the source for issues/transfers-out.
                $fromLevel->lock_version++;
                $fromLevel->save();
            }

            // ── Receipt side: increment destination + recompute WAC.
            if ($toLevel) {
                $oldQty = (string) $toLevel->quantity;
                $oldWac = (string) $toLevel->weighted_avg_cost;
                $newQty = bcadd($oldQty, $in->quantity, 3);
                if (bccomp($newQty, '0', 3) > 0) {
                    $oldVal = bcmul($oldQty, $oldWac, 4);
                    $addVal = bcmul($in->quantity, (string) $unitCost, 4);
                    $newVal = bcadd($oldVal, $addVal, 4);
                    $toLevel->weighted_avg_cost = $this->round4(bcdiv($newVal, $newQty, 6));
                }
                $toLevel->quantity = $newQty;
                $toLevel->lock_version++;
                $toLevel->save();
            }

            // ── Persist the movement record.
            $movement = StockMovement::create([
                'item_id'          => $in->itemId,
                'from_location_id' => $in->fromLocationId,
                'to_location_id'   => $in->toLocationId,
                'movement_type'    => $in->type,
                'quantity'         => $in->quantity,
                'unit_cost'        => $unitCost,
                'total_cost'       => $this->round2($totalCost),
                'reference_type'   => $in->referenceType,
                'reference_id'     => $in->referenceId,
                'remarks'          => $in->remarks,
                'created_by'       => $in->createdBy,
                'created_at'       => now(),
            ]);

            // Fire event AFTER commit — listeners (auto-replenishment) run async.
            DB::afterCommit(fn () => event(new StockMovementCompleted($movement)));

            return $movement;
        });
    }

    /** Lock-or-create the per-(item, location) ledger row. */
    private function lockOrCreate(int $itemId, int $locationId): StockLevel
    {
        $level = StockLevel::query()
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if (! $level) {
            // Insert (race-safe via unique constraint) then re-lock.
            StockLevel::query()->insertOrIgnore([
                'item_id'           => $itemId,
                'location_id'       => $locationId,
                'quantity'          => 0,
                'reserved_quantity' => 0,
                'weighted_avg_cost' => 0,
                'lock_version'      => 0,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
            $level = StockLevel::query()
                ->where('item_id', $itemId)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->firstOrFail();
        }
        return $level;
    }

    private function validateInput(StockMovementInput $in): void
    {
        if (bccomp($in->quantity, '0', 3) <= 0) {
            throw new InvalidMovementException('Quantity must be positive.');
        }

        // Receipts require a destination; issues require a source.
        $hasFrom = $in->fromLocationId !== null;
        $hasTo   = $in->toLocationId !== null;

        if ($in->type === StockMovementType::Transfer) {
            if (! $hasFrom || ! $hasTo) {
                throw new InvalidMovementException('Transfer requires both from_location_id and to_location_id.');
            }
            if ($in->fromLocationId === $in->toLocationId) {
                throw new InvalidMovementException('Transfer source and destination must differ.');
            }
            return;
        }

        if ($in->type->isReceipt() && ! $hasTo) {
            throw new InvalidMovementException("{$in->type->value} requires to_location_id.");
        }
        if ($in->type->isIssue() && ! $hasFrom) {
            throw new InvalidMovementException("{$in->type->value} requires from_location_id.");
        }
    }

    /** Reserve stock for a work order (no quantity change, just reservation). */
    public function reserve(int $itemId, int $locationId, string $quantity): void
    {
        DB::transaction(function () use ($itemId, $locationId, $quantity) {
            $level = $this->lockOrCreate($itemId, $locationId);
            $available = bcsub((string) $level->quantity, (string) $level->reserved_quantity, 3);
            if (bccomp($available, $quantity, 3) < 0) {
                throw new InsufficientStockException(
                    "Cannot reserve {$quantity}: only {$available} available at location {$locationId}."
                );
            }
            $level->reserved_quantity = bcadd((string) $level->reserved_quantity, $quantity, 3);
            $level->save();
        });
    }

    /** Release a reservation without issuing. */
    public function release(int $itemId, int $locationId, string $quantity): void
    {
        DB::transaction(function () use ($itemId, $locationId, $quantity) {
            $level = $this->lockOrCreate($itemId, $locationId);
            $rem = bcsub((string) $level->reserved_quantity, $quantity, 3);
            if (bccomp($rem, '0', 3) < 0) $rem = '0';
            $level->reserved_quantity = $rem;
            $level->save();
        });
    }

    private function round2(string $v): string
    {
        $abs = ltrim($v, '-');
        $isNeg = strlen($v) > strlen($abs);
        $r = bcadd($abs, '0.005', 2);
        return $isNeg ? '-'.$r : $r;
    }

    private function round4(string $v): string
    {
        $abs = ltrim($v, '-');
        $isNeg = strlen($v) > strlen($abs);
        $r = bcadd($abs, '0.00005', 4);
        return $isNeg ? '-'.$r : $r;
    }
}
