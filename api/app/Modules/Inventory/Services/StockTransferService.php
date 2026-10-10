<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Quality\Services\QualityStockHoldService;
use App\Modules\SupplyChain\Models\DeliveryStockReservation;
use Illuminate\Support\Facades\DB;

class StockTransferService
{
    public function __construct(private readonly StockMovementService $movements) {}

    public function transfer(int $itemId, int $fromLocationId, int $toLocationId, string $qty, ?string $remarks, User $by, ?int $referenceId = null): StockMovement
    {
        return DB::transaction(function () use ($itemId, $fromLocationId, $toLocationId, $qty, $remarks, $by, $referenceId) {
            app(QualityStockHoldService::class)->heldQuantity($itemId, $fromLocationId);
            StockLevel::query()->where('item_id', $itemId)->whereIn('location_id', [$fromLocationId, $toLocationId])
                ->orderBy('location_id')->lockForUpdate()->get();
            $lots = app(StockLocationSummaryService::class)->lots($itemId, $fromLocationId);
            $remaining = $qty;
            $lastMovement = null;
            foreach ($lots as $lot) {
                $reserved = DeliveryStockReservation::query()->where('item_id', $itemId)
                    ->where('location_id', $fromLocationId)->where('lot_number', $lot['lot_number'])->where('status', 'reserved')
                    ->get()->reduce(fn (string $sum, $hold): string => bcadd($sum, $hold->remainingQuantity(), 3), '0.000');
                $available = bcsub($lot['net'], $reserved, 3);
                $take = bccomp($available, $remaining, 3) < 0 ? $available : $remaining;
                if (bccomp($take, '0', 3) <= 0) {
                    continue;
                }
                $lastMovement = $this->move($itemId, $fromLocationId, $toLocationId, $take, $remarks, $by, $referenceId, $lot['lot_number'], $lot['expiry_date']);
                $remaining = bcsub($remaining, $take, 3);
                if (bccomp($remaining, '0', 3) === 0) {
                    break;
                }
            }
            if ($lots === []) {
                return $this->move($itemId, $fromLocationId, $toLocationId, $qty, $remarks, $by, $referenceId);
            }
            if (bccomp($remaining, '0', 3) > 0 || $lastMovement === null) {
                throw new BusinessRuleException('Insufficient traceable available lot stock. Mixed untracked stock needs reconciliation before transfer.');
            }

            return $lastMovement;
        }, 3);
    }

    private function move(int $itemId, int $fromLocationId, int $toLocationId, string $qty, ?string $remarks, User $by, ?int $referenceId, ?string $lotNumber = null, ?string $expiryDate = null): StockMovement
    {
        return $this->movements->move(new StockMovementInput(
            type: StockMovementType::Transfer, itemId: $itemId, fromLocationId: $fromLocationId,
            toLocationId: $toLocationId, quantity: $qty,
            referenceType: $referenceId === null ? null : 'stock_transfer', referenceId: $referenceId,
            remarks: $remarks, createdBy: $by->id, lotNumber: $lotNumber, expiryDate: $expiryDate,
        ));
    }
}
