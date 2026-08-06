<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockCountItemStatus;
use App\Modules\Inventory\Enums\StockCountSessionStatus;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\SettingsService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class StockCountService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly StockAdjustmentService $adjustments,
        private readonly SettingsService $settings,
    ) {}

    public function listSessions(): Collection
    {
        return StockCountSession::query()
            ->with(['warehouse', 'zone', 'creator', 'approver'])
            ->orderByDesc('created_at')
            ->get();
    }

    public function getSession(int $id): StockCountSession
    {
        return StockCountSession::with([
            'items.location.zone.warehouse',
            'items.item',
            'items.counter',
            'warehouse',
            'zone',
            'creator',
            'approver',
        ])->findOrFail($id);
    }

    public function createSession(array $data, User $user): StockCountSession
    {
        return DB::transaction(function () use ($data, $user) {
            $session = StockCountSession::create([
                'session_number'  => $this->sequences->generate('stock_count'),
                'title'           => $data['title'],
                'scope'           => $data['scope'] ?? (string) $this->settings->get('inventory.stock_count.default_scope', ''),
                'warehouse_id'    => $data['warehouse_id'] ?? null,
                'zone_id'         => $data['zone_id'] ?? null,
                'status'          => StockCountSessionStatus::Draft->value,
                'total_locations' => 0,
                'created_by'      => $user->id,
            ]);

            // Auto-populate locations based on scope
            $query = WarehouseLocation::query()->where('is_active', true);
            if ($data['scope'] === 'zone' && !empty($data['zone_id'])) {
                $query->where('zone_id', $data['zone_id']);
            } elseif ($data['scope'] === 'warehouse' && !empty($data['warehouse_id'])) {
                $query->whereIn('zone_id', function ($q) use ($data) {
                    $q->select('id')->from('warehouse_zones')
                      ->where('warehouse_id', $data['warehouse_id']);
                });
            }

            $locations = $query->get();
            $items = [];
            foreach ($locations as $loc) {
                $stockLevels = StockLevel::query()
                    ->where('location_id', $loc->id)
                    ->where('quantity', '>', 0)
                    ->get();

                if ($stockLevels->isNotEmpty()) {
                    foreach ($stockLevels as $sl) {
                        $items[] = [
                            'session_id'      => $session->id,
                            'location_id'     => $loc->id,
                            'item_id'         => $sl->item_id,
                            'system_quantity' => $sl->quantity,
                            'counted_quantity' => null,
                            'variance'        => 0,
                            'variance_percent' => 0,
                            'lot_number'      => null,
                            'status'          => StockCountItemStatus::Pending->value,
                            'created_at'      => now(),
                            'updated_at'      => now(),
                        ];
                    }
                } elseif ($loc->current_item_id) {
                    $items[] = [
                        'session_id'      => $session->id,
                        'location_id'     => $loc->id,
                        'item_id'         => $loc->current_item_id,
                        'system_quantity' => $loc->current_quantity ?? 0,
                        'counted_quantity' => null,
                        'variance'        => 0,
                        'variance_percent' => 0,
                        'lot_number'      => $loc->current_lot_number,
                        'status'          => StockCountItemStatus::Pending->value,
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ];
                }
            }

            StockCountItem::insert($items);
            $session->update(['total_locations' => count($items)]);

            return $session->fresh();
        });
    }

    public function startSession(int $id, User $user): StockCountSession
    {
        return DB::transaction(function () use ($id) {
            $session = StockCountSession::query()->lockForUpdate()->findOrFail($id);
            if ($session->status !== StockCountSessionStatus::Draft) {
                throw new BusinessRuleException('Session must be in draft status to start.');
            }

            $locationIds = $session->items()->pluck('location_id');
            $overlap = StockCountSession::query()
                ->where('status', StockCountSessionStatus::InProgress->value)
                ->whereHas('items', fn ($items) => $items->whereIn('location_id', $locationIds))
                ->lockForUpdate()
                ->first();
            if ($overlap) {
                throw new BusinessRuleException("Locations are already frozen by stock count {$overlap->session_number}.");
            }

            $session->update([
                'status'    => StockCountSessionStatus::InProgress->value,
                'frozen_at' => now(),
            ]);
            return $session->fresh()->load(['warehouse', 'zone', 'items.location', 'items.item']);
        });
    }

    public function recordCount(int $itemId, array $data, User $user): StockCountItem
    {
        $item = StockCountItem::findOrFail($itemId);
        if ($item->session->status !== StockCountSessionStatus::InProgress) {
            throw new BusinessRuleException('Session is not in progress.');
        }

        $item->update([
            'counted_quantity'  => $data['counted_quantity'],
            'variance'          => bcsub((string) $data['counted_quantity'], (string) $item->system_quantity, 3),
            'variance_percent'  => $item->system_quantity > 0
                ? round(abs((float) $data['counted_quantity'] - (float) $item->system_quantity) / (float) $item->system_quantity * 100, 2)
                : ($data['counted_quantity'] > 0 ? 100 : 0),
            'lot_number'        => $data['lot_number'] ?? $item->lot_number,
            'status'            => StockCountItemStatus::Counted->value,
            'counted_by'        => $user->id,
            'counted_at'        => now(),
            'notes'             => $data['notes'] ?? $item->notes,
        ]);

        // Update session progress
        $session = $item->session;
        $counted = $session->items()->whereIn('status', [StockCountItemStatus::Counted->value, StockCountItemStatus::Verified->value, StockCountItemStatus::Adjusted->value])->count();
        $session->update(['counted_locations' => $counted]);

        return $item->fresh()->load(['location', 'item', 'counter']);
    }

    public function approveVariance(int $itemId, User $user): StockCountItem
    {
        $item = StockCountItem::with('session')->findOrFail($itemId);
        if ($item->session->status !== StockCountSessionStatus::InProgress) {
            throw new BusinessRuleException('Session is not in progress.');
        }
        if ($item->status !== StockCountItemStatus::Counted) {
            throw new BusinessRuleException('Item must be counted first.');
        }

        $item->update([
            'status' => StockCountItemStatus::Verified->value,
        ]);

        return $item->fresh()->load(['location', 'item']);
    }

    public function completeSession(int $id, User $user): StockCountSession
    {
        return DB::transaction(function () use ($id, $user) {
            $session = StockCountSession::with('items')->lockForUpdate()->findOrFail($id);
            if ($session->status !== StockCountSessionStatus::InProgress) {
                throw new BusinessRuleException('Session must be in progress to complete.');
            }

            $varianceCount = 0;
            $varianceValue = 0;
            $varianceTolerance = $this->settings->requiredFloat('inventory.stock_count.variance_tolerance_pct', 0);

            foreach ($session->items as $item) {
                if ($item->status !== StockCountItemStatus::Counted && $item->status !== StockCountItemStatus::Verified) continue;

                $variance = (float) $item->variance;
                if (abs($variance) > 0.001) {
                    $varianceCount++;
                    $varianceValue += abs($variance);
                }

                // If variance exceeds the configured tolerance and is not verified, require approval.
                if (abs((float) $item->variance_percent) > $varianceTolerance && $item->status !== StockCountItemStatus::Verified) {
                    throw new BusinessRuleException(
                        "Item #{$item->id} has a variance of {$item->variance_percent}% — requires supervisor sign-off."
                    );
                }

                // Auto-create stock adjustment for variances
                if (abs($variance) > 0.001 && $item->item_id && $item->counted_quantity !== null) {
                    $diff = bcsub((string) $item->counted_quantity, (string) $item->system_quantity, 3);
                    if (bccomp($diff, '0', 3) > 0) {
                        // Stock increase — value the overage at the location's
                        // current WAC (locked so the read cannot race a
                        // concurrent adjust), never at zero: a '0' cost drags
                        // the blended average toward zero with every cycle.
                        $unitCost = (string) (StockLevel::query()
                            ->where('item_id', $item->item_id)
                            ->where('location_id', $item->location_id)
                            ->lockForUpdate()
                            ->value('weighted_avg_cost') ?? '0.00');
                        $this->adjustments->adjustIn(
                            $item->item_id,
                            $item->location_id,
                            $diff,
                            $unitCost,
                            'Cycle count adjustment — session ' . $session->session_number,
                            $user,
                            bypassCountFreeze: true,
                        );
                    } elseif (bccomp($diff, '0', 3) < 0) {
                        // Stock decrease
                        $this->adjustments->adjustOut(
                            $item->item_id,
                            $item->location_id,
                            substr($diff, 1), // remove minus sign
                            'Cycle count adjustment — session ' . $session->session_number,
                            $user,
                            bypassCountFreeze: true,
                        );
                    }

                    $item->update(['status' => StockCountItemStatus::Adjusted->value]);
                }
            }

            $session->update([
                'status'           => StockCountSessionStatus::Completed->value,
                'completed_at'     => now(),
                'approved_by'      => $user->id,
                'variance_count'   => $varianceCount,
                'variance_value'   => $varianceValue,
            ]);

            return $session->fresh()->load(['warehouse', 'zone', 'creator', 'approver', 'items.location', 'items.item']);
        });
    }

    public function cancelSession(int $id): StockCountSession
    {
        $session = StockCountSession::findOrFail($id);
        if (in_array($session->status, [StockCountSessionStatus::Completed, StockCountSessionStatus::Cancelled], true)) {
            throw new BusinessRuleException('Session already completed or cancelled.');
        }
        $session->update(['status' => StockCountSessionStatus::Cancelled->value]);
        return $session->fresh();
    }
}
