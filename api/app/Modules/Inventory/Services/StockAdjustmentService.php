<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockAdjustmentReason;
use App\Modules\Inventory\Enums\StockAdjustmentStatus;
use App\Modules\Inventory\Enums\StockMovementType;
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
     * Legacy entry point — applies an inbound adjustment IMMEDIATELY and
     * returns the ledger movement. Kept signature-compatible with existing
     * callers/tests; the optional $reasonCode is appended last so old calls
     * keep working. The value-threshold approval gate is intentionally NOT
     * applied here (immediate apply) — use create() for gated adjustments.
     */
    public function adjustIn(
        int $itemId,
        int $locationId,
        string $qty,
        string $unitCost,
        string $reason,
        User $by,
        StockAdjustmentReason|string|null $reasonCode = null,
        bool $bypassCountFreeze = false,
    ): StockMovement {
        return DB::transaction(function () use ($itemId, $locationId, $qty, $unitCost, $reason, $by, $reasonCode, $bypassCountFreeze) {
            $mvmt = $this->applyMovement('in', $itemId, $locationId, $qty, $unitCost, $reason, $by, $bypassCountFreeze);
            $this->recordAdjustment('in', $itemId, $locationId, $qty, $unitCost, $reason, $reasonCode, $by, $mvmt, StockAdjustmentStatus::Approved);
            return $mvmt;
        });
    }

    /**
     * Legacy entry point — applies an outbound adjustment IMMEDIATELY at the
     * current WAC and returns the ledger movement.
     */
    public function adjustOut(
        int $itemId,
        int $locationId,
        string $qty,
        string $reason,
        User $by,
        StockAdjustmentReason|string|null $reasonCode = null,
        bool $bypassCountFreeze = false,
    ): StockMovement {
        return DB::transaction(function () use ($itemId, $locationId, $qty, $reason, $by, $reasonCode, $bypassCountFreeze) {
            $mvmt = $this->applyMovement('out', $itemId, $locationId, $qty, null, $reason, $by, $bypassCountFreeze);
            $cost = (string) $mvmt->unit_cost;
            $this->recordAdjustment('out', $itemId, $locationId, $qty, $cost, $reason, $reasonCode, $by, $mvmt, StockAdjustmentStatus::Approved);
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

            if ($gated) {
                // Above threshold — hold for approval; no ledger movement yet.
                $adj->forceFill(['status' => StockAdjustmentStatus::Pending->value])->save();
                return $adj;
            }

            // Sub-threshold — apply immediately and link the movement.
            $mvmt = $this->applyMovement($direction, $itemId, $locationId, $qty, $direction === 'out' ? null : $cost, $reason, $by);
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
        if ($adj->status === StockAdjustmentStatus::Approved || $adj->stock_movement_id) {
            throw new BusinessRuleException('Adjustment is already approved.');
        }

        return DB::transaction(function () use ($adj, $by) {
            $mvmt = $this->applyMovement(
                $adj->direction,
                (int) $adj->item_id,
                (int) $adj->location_id,
                (string) $adj->quantity,
                (string) $adj->unit_cost,
                (string) $adj->reason,
                $by,
            );
            $adj->stock_movement_id = $mvmt->id;
            $adj->approved_by = $by->id;
            $adj->approved_at = now();
            $adj->forceFill(['status' => StockAdjustmentStatus::Approved->value]);
            $adj->save();

            return $adj->fresh();
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
    ): StockMovement {
        return $this->movements->move(new StockMovementInput(
            type: $direction === 'in' ? StockMovementType::AdjustmentIn : StockMovementType::AdjustmentOut,
            itemId: $itemId,
            fromLocationId: $direction === 'in' ? null : $locationId,
            toLocationId: $direction === 'in' ? $locationId : null,
            quantity: $qty,
            unitCost: $unitCost,
            referenceType: 'stock_adjustment',
            referenceId: null,
            remarks: $reason,
            createdBy: $by->id,
            bypassCountFreeze: $bypassCountFreeze,
        ));
    }

    /** Persist the adjustment record for the legacy adjustIn/adjustOut paths. */
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
            throw new RuntimeException("Invalid stock adjustment reason code '{$reasonCode}'.");
        }
        return $enum;
    }
}
