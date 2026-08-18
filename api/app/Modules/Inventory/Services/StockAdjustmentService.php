<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockAdjustmentReason;
use App\Modules\Inventory\Enums\StockAdjustmentStatus;
use App\Modules\Inventory\Enums\StockCountItemStatus;
use App\Modules\Inventory\Enums\StockCountSessionStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockMovementInput;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockAdjustmentService
{
    public function __construct(private readonly StockMovementService $movements, private readonly SettingsService $settings) {}

    /**
     * Reconcile one counted item through the stock-count finalization path.
     *
     * The caller supplies only the item context and actor. The authoritative
     * session/item rows are re-read under lock; direction, quantity, and WAC
     * are derived from those locked values. Movement, audit row, and Adjusted
     * transition commit together, so a replay cannot post twice.
     */
    public function reconcileStockCountItem(StockCountItem $countItem, User $by): ?StockMovement
    {
        return DB::transaction(function () use ($countItem, $by) {
            $session = StockCountSession::query()->lockForUpdate()->findOrFail($countItem->session_id);
            $item = StockCountItem::query()->lockForUpdate()->findOrFail($countItem->getKey());

            if ($session->status !== StockCountSessionStatus::InProgress) {
                throw new BusinessRuleException('Stock-count reconciliation requires an in-progress session.');
            }
            if (! in_array($item->status, [StockCountItemStatus::Counted, StockCountItemStatus::Verified], true)) {
                throw new BusinessRuleException('Stock-count reconciliation requires a counted item.');
            }
            if ($item->item_id === null || $item->counted_quantity === null) {
                throw new BusinessRuleException('Stock-count reconciliation requires an item with a recorded count.');
            }

            $diff = bcsub((string) $item->counted_quantity, (string) $item->system_quantity, 3);
            if (bccomp($diff, '0', 3) === 0) {
                return null;
            }

            $direction = bccomp($diff, '0', 3) > 0 ? 'in' : 'out';
            $qty = $direction === 'in' ? $diff : substr($diff, 1);
            $unitCost = (string) (StockLevel::query()
                ->where('item_id', $item->item_id)
                ->where('location_id', $item->location_id)
                ->lockForUpdate()
                ->value('weighted_avg_cost') ?? '0.00');
            $reason = 'Cycle count adjustment — session '.$session->session_number;

            $mvmt = $this->applyMovement(
                $direction,
                (int) $item->item_id,
                (int) $item->location_id,
                $qty,
                $direction === 'in' ? $unitCost : null,
                $reason,
                $by,
                true,
                'stock_count_session',
                (int) $session->id,
            );
            $this->recordAdjustment(
                $direction,
                (int) $item->item_id,
                (int) $item->location_id,
                $qty,
                (string) $mvmt->unit_cost,
                $reason,
                null,
                $by,
                $mvmt,
                StockAdjustmentStatus::Approved,
            );
            $item->update(['status' => StockCountItemStatus::Adjusted->value]);

            return $mvmt;
        });
    }

    /**
     * OGAMI-012 — primary adjustment entry point with structured reason code
     * + value-threshold approval gate.
     *
     * If the absolute adjustment value (|qty * unit_cost|) EXCEEDS
     * the configured threshold, the adjustment is created `pending` and NO
     * stock movement posts until approve() is called.
     * A threshold of '0' (default) disables the gate → immediate apply.
     *
     * @param  string  $direction  'in' | 'out'
     */
    public function create(
        int $itemId,
        int $locationId,
        string $direction,
        string $qty,
        ?string $unitCost,
        string $reason,
        User $by,
        StockAdjustmentReason|string|null $reasonCode = null,
    ): StockAdjustment {
        if (! in_array($direction, ['in', 'out'], true)) {
            throw new BusinessRuleException("Invalid adjustment direction '{$direction}'.");
        }
        $reasonCode = $this->normalizeReason($reasonCode);

        return DB::transaction(function () use ($itemId, $locationId, $direction, $qty, $unitCost, $reason, $by, $reasonCode) {
            $cost = $direction === 'out'
                ? $this->currentWac($itemId, $locationId)
                : $this->requiredInboundCost($unitCost);

            $value = $this->absValue($qty, $cost);
            $gated = $this->exceedsThreshold($value);

            $adj = new StockAdjustment([
                'item_id'      => $itemId,
                'location_id'  => $locationId,
                'direction'    => $direction,
                'quantity'     => $qty,
                'unit_cost'    => $cost,
                'value'        => $value,
                'reason_code'  => $reasonCode,
                'reason'       => $reason,
                'requested_by' => $by->id,
            ]);

            // Persist the source before writing the stock ledger so the
            // movement reference is resolvable at its canonical boundary.
            $adj->forceFill(['status' => StockAdjustmentStatus::Pending->value])->save();

            if ($gated) {
                // Above threshold — hold for approval; no ledger movement yet.
                return $adj;
            }

            // Sub-threshold — apply immediately and link the movement.
            $mvmt = $this->applyMovement(
                $direction,
                $itemId,
                $locationId,
                $qty,
                $direction === 'out' ? null : $cost,
                $reason,
                $by,
                false,
                'stock_adjustment',
                (int) $adj->id,
            );
            $adj->unit_cost = (string) $mvmt->unit_cost;
            $adj->stock_movement_id = $mvmt->id;
            $adj->approved_by = $by->id;
            $adj->approved_at = now();
            $adj->forceFill(['status' => StockAdjustmentStatus::Approved->value]);
            $adj->save();

            return $adj;
        });
    }

    /**
     * OGAMI-012 — approve a pending (above-threshold) adjustment, posting the
     * held stock movement. Permission-guarded; idempotent guard on status.
     */
    public function approve(StockAdjustment $adj, User $by): StockAdjustment
    {
        if (! ($by->hasPermission('inventory.adjust.approve'))) {
            throw new BusinessRuleException('You are not authorized to approve stock adjustments.');
        }

        return DB::transaction(function () use ($adj, $by) {
            // Lock-then-guard: re-read the authoritative row under a row lock
            // so a concurrent approval holding a stale model cannot slip past
            // the status check and post the stock movement twice (P37).
            $locked = StockAdjustment::query()->lockForUpdate()->findOrFail($adj->getKey());
            if ($locked->status === StockAdjustmentStatus::Approved || $locked->stock_movement_id) {
                throw new BusinessRuleException('Adjustment is already approved.');
            }

            $mvmt = $this->applyMovement(
                $locked->direction,
                (int) $locked->item_id,
                (int) $locked->location_id,
                (string) $locked->quantity,
                (string) $locked->unit_cost,
                (string) $locked->reason,
                $by,
                false,
                'stock_adjustment',
                (int) $locked->id,
            );
            $locked->stock_movement_id = $mvmt->id;
            $locked->approved_by = $by->id;
            $locked->approved_at = now();
            $locked->forceFill(['status' => StockAdjustmentStatus::Approved->value]);
            $locked->save();

            return $locked->fresh();
        });
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** Post the ledger movement for an adjustment direction. */
    private function applyMovement(
        string $direction,
        int $itemId,
        int $locationId,
        string $qty,
        string $unitCost,
        string $reason,
        User $by,
        bool $bypassCountFreeze = false,
        string $referenceType = 'stock_adjustment',
        ?int $referenceId = null,
    ): StockMovement {
        return $this->movements->move(new StockMovementInput(
            type: $direction === 'in' ? StockMovementType::AdjustmentIn : StockMovementType::AdjustmentOut,
            itemId: $itemId,
            fromLocationId: $direction === 'in' ? null : $locationId,
            toLocationId: $direction === 'in' ? $locationId : null,
            quantity: $qty,
            unitCost: $unitCost,
            referenceType: $referenceType,
            referenceId: $referenceId,
            remarks: $reason,
            createdBy: $by->id,
            bypassCountFreeze: $bypassCountFreeze,
        ));
    }

    /** Persist the adjustment record for a posted or approved movement. */
    private function recordAdjustment(
        string $direction,
        int $itemId,
        int $locationId,
        string $qty,
        string $unitCost,
        string $reason,
        StockAdjustmentReason|string|null $reasonCode,
        User $by,
        StockMovement $mvmt,
        StockAdjustmentStatus $status,
    ): StockAdjustment {
        $adj = new StockAdjustment([
            'item_id'           => $itemId,
            'location_id'       => $locationId,
            'direction'         => $direction,
            'quantity'          => $qty,
            'unit_cost'         => $unitCost,
            'value'             => $this->absValue($qty, $unitCost),
            'reason_code'       => $this->normalizeReason($reasonCode),
            'reason'            => $reason,
            'stock_movement_id' => $mvmt->id,
            'requested_by'      => $by->id,
            'approved_by'       => $by->id,
            'approved_at'       => now(),
        ]);
        $adj->forceFill(['status' => $status->value])->save();

        return $adj;
    }

    private function currentWac(int $itemId, int $locationId): string
    {
        $level = StockLevel::query()
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->first();
        if ($level === null || $level->weighted_avg_cost === null) {
            throw new BusinessRuleException('No authoritative weighted-average cost exists for this stock level.');
        }
        return (string) $level->weighted_avg_cost;
    }

    private function requiredInboundCost(?string $unitCost): string
    {
        if ($unitCost === null || trim($unitCost) === '') {
            throw new BusinessRuleException('A unit cost is required for an inbound stock adjustment.');
        }

        return $unitCost;
    }

    private function absValue(string $qty, string $unitCost): string
    {
        $v = bcmul($qty, $unitCost, 2);
        return ltrim($v, '-');
    }

    private function exceedsThreshold(string $value): bool
    {
        $threshold = (string) $this->settings->requiredFloat('inventory.adjustment_approval_threshold', 0);
        if (bccomp($threshold, '0', 2) <= 0) {
            return false; // gate disabled
        }
        return bccomp($value, $threshold, 2) > 0;
    }

    private function normalizeReason(StockAdjustmentReason|string|null $reasonCode): ?StockAdjustmentReason
    {
        if ($reasonCode === null || $reasonCode === '') {
            return null;
        }
        if ($reasonCode instanceof StockAdjustmentReason) {
            return $reasonCode;
        }
        $enum = StockAdjustmentReason::tryFrom($reasonCode);
        if (! $enum) {
            // Left unmapped: StoreStockAdjustmentRequest validates reason_code
            // with Rule::in(StockAdjustmentReason::values()), and the internal
            // callers (stock-count reconciliation) pass enum instances. An
            // unknown code therefore means a caller bug, not operator input.
            throw new RuntimeException("Invalid stock adjustment reason code '{$reasonCode}'.");
        }
        return $enum;
    }
}
