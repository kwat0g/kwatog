<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\TransferOrder;
use App\Common\Services\DocumentSequenceService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TransferOrderService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly StockTransferService $transfers,
    ) {}

    public function list(): Collection
    {
        return TransferOrder::query()
            ->with(['fromLocation.zone.warehouse', 'toLocation.zone.warehouse', 'item', 'creator'])
            ->orderByDesc('created_at')
            ->get();
    }

    public function get(int $id): TransferOrder
    {
        return TransferOrder::with([
            'fromLocation.zone.warehouse',
            'toLocation.zone.warehouse',
            'item',
            'creator',
            'transferrer',
        ])->findOrFail($id);
    }

    public function create(array $data, User $user): TransferOrder
    {
        return DB::transaction(function () use ($data, $user) {
            return TransferOrder::create([
                'transfer_number'   => $this->sequences->generate('transfer_order'),
                'from_location_id'  => $data['from_location_id'],
                'to_location_id'    => $data['to_location_id'],
                'item_id'           => $data['item_id'],
                'quantity'          => $data['quantity'],
                'reason'            => $data['reason'] ?? null,
                'status'            => 'pending',
                'created_by'        => $user->id,
            ]);
        });
    }

    public function execute(int $id, User $user): TransferOrder
    {
        return DB::transaction(function () use ($id, $user) {
            $order = TransferOrder::findOrFail($id);
            if ($order->status !== 'pending') {
                throw new RuntimeException('Transfer order is not pending.');
            }

            // Execute via existing StockTransferService
            $this->transfers->transfer(
                $order->item_id,
                $order->from_location_id,
                $order->to_location_id,
                (string) $order->quantity,
                $order->reason,
                $user,
            );

            $order->update([
                'status'         => 'transferred',
                'transferred_by' => $user->id,
                'transferred_at' => now(),
            ]);

            return $order->fresh()->load([
                'fromLocation.zone.warehouse',
                'toLocation.zone.warehouse',
                'item',
                'creator',
                'transferrer',
            ]);
        });
    }

    public function cancel(int $id): TransferOrder
    {
        $order = TransferOrder::findOrFail($id);
        if ($order->status !== 'pending') {
            throw new RuntimeException('Can only cancel pending transfer orders.');
        }
        $order->update(['status' => 'cancelled']);
        return $order->fresh();
    }
}
