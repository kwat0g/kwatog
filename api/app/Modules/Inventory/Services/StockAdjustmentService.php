<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\ApprovalDelegation;
use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockAdjustmentReason;
use App\Modules\Inventory\Enums\StockAdjustmentStatus;
use App\Modules\Inventory\Enums\StockCountItemStatus;
use App\Modules\Inventory\Enums\StockCountSessionStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
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

            $lots = app(StockLocationSummaryService::class)->lots((int) $item->item_id, (int) $item->location_id);
            $allocations = $item->variance_allocations;
            if (! $allocations) {
                if ($item->lot_number) {
                    $allocations = [['lot_number' => $item->lot_number, 'quantity' => $qty]];
                } elseif (count($lots) <= 1) {
                    $allocations = [['lot_number' => $lots[0]['lot_number'] ?? null, 'quantity' => $qty]];
                } else {
                    throw new BusinessRuleException('Allocate the count difference to its actual lots before completing this count.');
                }
            }
            $allocated = '0.000';
            $seen = [];
            foreach ($allocations as $allocation) {
                $lotNumber = $allocation['lot_number'] ?? null;
                $amount = (string) ($allocation['quantity'] ?? '0');
                if (! preg_match('/^\d{1,12}(\.\d{1,3})?$/', $amount) || bccomp($amount, '0', 3) <= 0) {
                    throw new BusinessRuleException('Every count variance allocation requires a positive quantity.');
                }
                $key = $lotNumber ?? '';
                if (isset($seen[$key])) {
                    throw new BusinessRuleException('Allocate each lot only once.');
                }
                $seen[$key] = true;
                if ($lotNumber === null && $lots !== []) {
                    throw new BusinessRuleException('Untracked count variance requires lot reconciliation before completion.');
                }
                if ($lotNumber !== null && ! collect($lots)->contains('lot_number', $lotNumber)) {
                    throw new BusinessRuleException('Choose an existing source lot for this count difference.');
                }
                $allocated = bcadd($allocated, $amount, 3);
            }
            if (bccomp($allocated, $qty, 3) !== 0) {
                throw new BusinessRuleException('Allocated lot quantities must equal the absolute count difference.');
            }
            $mvmt = null;
            foreach ($allocations as $allocation) {
                $lot = collect($lots)->firstWhere('lot_number', $allocation['lot_number'] ?? null);
                $mvmt = $this->applyMovement(
                    $direction, (int) $item->item_id, (int) $item->location_id,
                    (string) $allocation['quantity'], $direction === 'in' ? $unitCost : null,
                    $reason, $by, true, 'stock_count_session', (int) $session->id,
                    $allocation['lot_number'] ?? null, $lot['expiry_date'] ?? null,
                );
                $this->recordAdjustment(
                    $direction, (int) $item->item_id, (int) $item->location_id,
                    (string) $allocation['quantity'], (string) $mvmt->unit_cost,
                    $reason, null, $by, $mvmt, StockAdjustmentStatus::Approved,
                );
            }
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
        ?string $idempotencyKey = null,
    ): StockAdjustment {
        if (! in_array($direction, ['in', 'out'], true)) {
            throw new BusinessRuleException("Invalid adjustment direction '{$direction}'.");
        }
        $reasonCode = $this->normalizeReason($reasonCode);
        $fingerprint = $idempotencyKey === null ? null : hash('sha256', json_encode([
            $itemId, $locationId, $direction, bcadd($qty, '0', 3),
            $direction === 'in' && $unitCost !== null ? bcadd($unitCost, '0', 4) : null,
            trim($reason), $reasonCode?->value,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($itemId, $locationId, $direction, $qty, $unitCost, $reason, $by, $reasonCode, $idempotencyKey, $fingerprint) {
            if ($idempotencyKey !== null) {
                // Serialize this requester's retries before checking stock or posting a movement.
                User::query()->whereKey($by->id)->lockForUpdate()->firstOrFail();
                $existing = StockAdjustment::query()->where('requested_by', $by->id)->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    if ($existing->idempotency_fingerprint !== $fingerprint) {
                        throw new BusinessRuleException('This request key was already used for a different stock adjustment.');
                    }
                    return $existing;
                }
            }
            $item = Item::query()->lockForUpdate()->find($itemId);
            if ($item === null) {
                throw new BusinessRuleException('Cannot adjust a missing or deleted item.');
            }
            if (! $item->is_active) {
                throw new BusinessRuleException('Cannot adjust an inactive item.');
            }
            app(UomConversionService::class)->assertBaseQuantity($item, $qty);
            $this->movements->assertActiveLocation($locationId, lockForUpdate: true);
            if ($direction === 'in' && WarehouseLocation::findOrFail($locationId)->is_blocked) {
                throw new BusinessRuleException('The selected warehouse location is blocked for receiving.');
            }
            if ($direction === 'out') {
                $this->movements->assertCustomerReturnQuarantineCanBeAdjusted($itemId, $locationId);
                $this->movements->assertAdjustmentOutCapacity($itemId, $locationId, $qty);
            }

            $cost = $direction === 'out'
                ? $this->currentWac($itemId, $locationId)
                : $this->requiredInboundCost($unitCost);

            $value = $this->absValue($qty, $cost);
            $gated = $this->exceedsThreshold($value);

            $adj = new StockAdjustment([
                'item_id' => $itemId,
                'location_id' => $locationId,
                'direction' => $direction,
                'quantity' => $qty,
                'unit_cost' => $cost,
                'value' => $value,
                'reason_code' => $reasonCode,
                'reason' => $reason,
                'requested_by' => $by->id,
            ]);
            $adj->idempotency_key = $idempotencyKey;
            $adj->idempotency_fingerprint = $fingerprint;

            if ($gated) {
                // Above threshold — hold for approval; no ledger movement yet.
                $adj->status = StockAdjustmentStatus::Pending;
                $adj->save();

                $audience = ApprovalDelegation::approvalAudience('inventory.adjust.approve')
                    ->reject(fn (User $user): bool => (int) $user->id === (int) $by->id);
                app(NotificationService::class)->sendForSubject($adj, $audience, 'approval.action_required', [
                    'title' => 'Stock adjustment needs approval',
                    'message' => 'A high-value stock adjustment is waiting for review.',
                    'link_to' => '/inventory/stock-adjustments?id='.$adj->hash_id,
                    'entity_type' => 'stock_adjustment',
                    'entity_id' => $adj->hash_id,
                ], "stock-adjustment:approval-required:{$adj->id}");

                return $adj;
            }

            // The source must exist before its stock-movement reference is
            // written. Insert it quietly inside this transaction, then let the
            // final save below emit one audit row with the committed outcome.
            $adj->status = StockAdjustmentStatus::Approved;
            $adj->saveQuietly();

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
            $adj->fill([
                'unit_cost' => (string) $mvmt->unit_cost,
                'stock_movement_id' => $mvmt->id,
                'approved_by' => $by->id,
                'approved_at' => now(),
            ]);
            $adj->status = StockAdjustmentStatus::Approved;
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
        return DB::transaction(function () use ($adj, $by) {
            // Lock-then-guard: re-read the authoritative row under a row lock
            // so a concurrent approval holding a stale model cannot slip past
            // the status check and post the stock movement twice (P37).
            $locked = StockAdjustment::query()->lockForUpdate()->findOrFail($adj->getKey());
            if ($locked->status === StockAdjustmentStatus::Approved) {
                throw new BusinessRuleException('Adjustment is already approved.');
            }
            if ($locked->status !== StockAdjustmentStatus::Pending || $locked->stock_movement_id) {
                throw new BusinessRuleException('Adjustment is no longer pending.');
            }
            if (! ApprovalDelegation::canActForPermission($by, 'inventory.adjust.approve', [(int) $locked->requested_by])) {
                throw new BusinessRuleException('You are not authorized to approve stock adjustments.');
            }
            if ((int) $locked->requested_by === (int) $by->id) {
                throw new BusinessRuleException('The requester cannot approve their own adjustment.');
            }
            if (Item::query()->lockForUpdate()->whereKey($locked->item_id)->first() === null) {
                throw new BusinessRuleException('Cannot approve an adjustment for a deleted item. Restore the item first.');
            }

            $mvmt = $this->applyMovement(
                $locked->direction,
                (int) $locked->item_id,
                (int) $locked->location_id,
                (string) $locked->quantity,
                $locked->direction === 'out' ? null : (string) $locked->unit_cost,
                (string) $locked->reason,
                $by,
                false,
                'stock_adjustment',
                (int) $locked->id,
            );
            // Outbound approvals use the WAC held by StockMovementService under
            // the source row lock. Replace the creation snapshot with the
            // authoritative approval-time cost and value for the audit trail.
            $locked->unit_cost = (string) $mvmt->unit_cost;
            $locked->value = $this->absValue((string) $locked->quantity, (string) $mvmt->unit_cost);
            $locked->stock_movement_id = $mvmt->id;
            $locked->approved_by = $by->id;
            $locked->approved_at = now();
            $locked->forceFill(['status' => StockAdjustmentStatus::Approved->value]);
            $locked->save();

            $this->notifyRequester($locked, 'approval.completed', 'Stock adjustment approved');

            return $locked->fresh();
        });
    }

    public function reject(StockAdjustment $adj, User $by, string $reason): StockAdjustment
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new BusinessRuleException('Enter a rejection reason of 1 to 500 characters.');
        }

        return DB::transaction(function () use ($adj, $by, $reason) {
            $locked = StockAdjustment::query()->lockForUpdate()->findOrFail($adj->getKey());
            if ($locked->status !== StockAdjustmentStatus::Pending || $locked->stock_movement_id) {
                throw new BusinessRuleException('Adjustment is no longer pending.');
            }
            if (! ApprovalDelegation::canActForPermission($by, 'inventory.adjust.approve', [(int) $locked->requested_by])) {
                throw new BusinessRuleException('You are not authorized to reject stock adjustments.');
            }
            if ((int) $locked->requested_by === (int) $by->id) {
                throw new BusinessRuleException('The requester cannot reject their own adjustment.');
            }

            $locked->forceFill([
                'status' => StockAdjustmentStatus::Rejected->value,
                'resolved_by' => $by->id,
                'resolved_at' => now(),
                'resolution_reason' => $reason,
            ])->save();

            $this->notifyRequester($locked, 'approval.rejected', 'Stock adjustment rejected');

            return $locked->fresh();
        });
    }

    public function withdraw(StockAdjustment $adj, User $by): StockAdjustment
    {
        if (! $by->hasPermission('inventory.adjust')) {
            throw new BusinessRuleException('You are not authorized to withdraw stock adjustments.');
        }

        return DB::transaction(function () use ($adj, $by) {
            $locked = StockAdjustment::query()->lockForUpdate()->findOrFail($adj->getKey());
            if ($locked->status !== StockAdjustmentStatus::Pending || $locked->stock_movement_id) {
                throw new BusinessRuleException('Adjustment is no longer pending.');
            }
            if ((int) $locked->requested_by !== (int) $by->id) {
                throw new BusinessRuleException('Only the requester may withdraw this adjustment.');
            }

            $locked->forceFill([
                'status' => StockAdjustmentStatus::Withdrawn->value,
                'resolved_by' => $by->id,
                'resolved_at' => now(),
                'resolution_reason' => null,
            ])->save();

            return $locked->fresh();
        });
    }

    private function notifyRequester(StockAdjustment $adjustment, string $type, string $title): void
    {
        $requester = User::query()->whereKey($adjustment->requested_by)->where('is_active', true)->first();
        if (! $requester
            || (! $requester->hasPermission('inventory.adjust')
                && ! $requester->hasPermission('inventory.adjust.approve'))) {
            return;
        }

        app(NotificationService::class)->sendForSubject($adjustment, $requester, $type, [
            'title' => $title,
            'message' => $type === 'approval.rejected' ? 'Your stock adjustment was rejected.' : 'Your stock adjustment was approved.',
            'link_to' => '/inventory/stock-adjustments?id='.$adjustment->hash_id,
            'entity_type' => 'stock_adjustment',
            'entity_id' => $adjustment->hash_id,
        ], "stock-adjustment:decision:{$adjustment->id}:{$type}");
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** Post the ledger movement for an adjustment direction. */
    private function applyMovement(
        string $direction,
        int $itemId,
        int $locationId,
        string $qty,
        ?string $unitCost,
        string $reason,
        User $by,
        bool $bypassCountFreeze = false,
        string $referenceType = 'stock_adjustment',
        ?int $referenceId = null,
        ?string $lotNumber = null,
        ?string $expiryDate = null,
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
            lotNumber: $lotNumber,
            expiryDate: $expiryDate,
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
            'item_id' => $itemId,
            'location_id' => $locationId,
            'direction' => $direction,
            'quantity' => $qty,
            'unit_cost' => $unitCost,
            'value' => $this->absValue($qty, $unitCost),
            'reason_code' => $this->normalizeReason($reasonCode),
            'reason' => $reason,
            'stock_movement_id' => $mvmt->id,
            'requested_by' => $by->id,
            'approved_by' => $by->id,
            'approved_at' => now(),
        ]);
        $adj->forceFill(['status' => $status->value])->save();

        return $adj;
    }

    private function currentWac(int $itemId, int $locationId): string
    {
        $level = StockLevel::query()
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
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
