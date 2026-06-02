<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Common\Services\DocumentSequenceService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockCountService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly StockAdjustmentService $adjustments,
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
                'scope'           => $data['scope'] ?? 'full',
                'warehouse_id'    => $data['warehouse_id'] ?? null,
                'zone_id'         => $data['zone_id'] ?? null,
                'status'          => 'draft',
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
                // Get current stock at this location
                $stockLevel = StockLevel::query()
                    ->where('location_id', $loc->id)
                    ->where('quantity', '>', 0)
                    ->first();

                $items[] = [
                    'session_id'      => $session->id,
                    'location_id'     => $loc->id,
                    'item_id'         => $stockLevel?->item_id ?? $loc->current_item_id,
                    'system_quantity' => $stockLevel?->quantity ?? $loc->current_quantity ?? 0,
                    'counted_quantity' => null,
                    'variance'        => 0,
                    'variance_percent' => 0,
                    'lot_number'      => $stockLevel ? null : $loc->current_lot_number,
                    'status'          => 'pending',
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ];
            }

            StockCountItem::insert($items);
            $session->update(['total_locations' => count($items)]);

            return $session->fresh();
        });
    }

    public function startSession(int $id, User $user): StockCountSession
    {
        $session = StockCountSession::findOrFail($id);
        if ($session->status !== 'draft') {
            throw new RuntimeException('Session must be in draft status to start.');
        }
        $session->update(['status' => 'in_progress']);
        return $session->fresh()->load(['warehouse', 'zone', 'items.location', 'items.item']);
    }

    public function recordCount(int $itemId, array $data, User $user): StockCountItem
    {
        $item = StockCountItem::findOrFail($itemId);
        if ($item->session->status !== 'in_progress') {
            throw new RuntimeException('Session is not in progress.');
        }

        $item->update([
            'counted_quantity'  => $data['counted_quantity'],
            'variance'          => bcsub((string) $data['counted_quantity'], (string) $item->system_quantity, 3),
            'variance_percent'  => $item->system_quantity > 0
                ? round(abs((float) $data['counted_quantity'] - (float) $item->system_quantity) / (float) $item->system_quantity * 100, 2)
                : ($data['counted_quantity'] > 0 ? 100 : 0),
            'lot_number'        => $data['lot_number'] ?? $item->lot_number,
            'status'            => 'counted',
            'counted_by'        => $user->id,
            'counted_at'        => now(),
            'notes'             => $data['notes'] ?? $item->notes,
        ]);

        // Update session progress
        $session = $item->session;
        $counted = $session->items()->whereIn('status', ['counted', 'verified', 'adjusted'])->count();
        $session->update(['counted_locations' => $counted]);

        return $item->fresh()->load(['location', 'item', 'counter']);
    }

    public function approveVariance(int $itemId, User $user): StockCountItem
    {
        $item = StockCountItem::with('session')->findOrFail($itemId);
        if ($item->session->status !== 'in_progress') {
            throw new RuntimeException('Session is not in progress.');
        }
        if ($item->status !== 'counted') {
            throw new RuntimeException('Item must be counted first.');
        }

        $item->update([
            'status' => 'verified',
        ]);

        return $item->fresh()->load(['location', 'item']);
    }

    public function completeSession(int $id, User $user): StockCountSession
    {
        return DB::transaction(function () use ($id, $user) {
            $session = StockCountSession::with('items')->findOrFail($id);
            if ($session->status !== 'in_progress') {
                throw new RuntimeException('Session must be in progress to complete.');
            }

            $varianceCount = 0;
            $varianceValue = 0;

            foreach ($session->items as $item) {
                if ($item->status !== 'counted' && $item->status !== 'verified') continue;

                $variance = (float) $item->variance;
                if (abs($variance) > 0.001) {
                    $varianceCount++;
                    $varianceValue += abs($variance);
                }

                // If variance > 2% and not verified, require approval
                if (abs($item->variance_percent) > 2 && $item->status !== 'verified') {
                    throw new RuntimeException(
                        "Item #{$item->id} has a variance of {$item->variance_percent}% — requires supervisor sign-off."
                    );
                }

                // Auto-create stock adjustment for variances
                if (abs($variance) > 0.001 && $item->item_id && $item->counted_quantity !== null) {
                    $diff = bcsub((string) $item->counted_quantity, (string) $item->system_quantity, 3);
                    if (bccomp($diff, '0', 3) > 0) {
                        // Stock increase — use the on-hand cost for valuation
                        $this->adjustments->adjustIn(
                            $item->item_id,
                            $item->location_id,
                            $diff,
                            '0', // cost accounted via existing WAC
                            'Cycle count adjustment — session ' . $session->session_number,
                            $user,
                        );
                    } elseif (bccomp($diff, '0', 3) < 0) {
                        // Stock decrease
                        $this->adjustments->adjustOut(
                            $item->item_id,
                            $item->location_id,
                            substr($diff, 1), // remove minus sign
                            'Cycle count adjustment — session ' . $session->session_number,
                            $user,
                        );
                    }

                    $item->update(['status' => 'adjusted']);
                }
            }

            $session->update([
                'status'           => 'completed',
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
        if (in_array($session->status, ['completed', 'cancelled'])) {
            throw new RuntimeException('Session already completed or cancelled.');
        }
        $session->update(['status' => 'cancelled']);
        return $session->fresh();
    }
}
