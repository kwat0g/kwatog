<?php

declare(strict_types=1);

namespace App\Modules\Assets\Services;

use App\Common\Services\DocumentSequenceService;
use App\Modules\Assets\Enums\TransferStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetTransfer;
use App\Modules\Auth\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AssetTransferService
{
    public function __construct(private readonly DocumentSequenceService $sequences) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = AssetTransfer::query()
            ->with(['asset:id,asset_code,name', 'fromDepartment:id,name', 'toDepartment:id,name']);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['asset_id'])) {
            $q->where('asset_id', $filters['asset_id']);
        }

        return $q->orderByDesc('created_at')
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function create(array $data): AssetTransfer
    {
        return DB::transaction(function () use ($data) {
            $asset = Asset::findOrFail($data['asset_id']);

            if ((int) $asset->department_id !== (int) $data['from_department_id']) {
                throw new RuntimeException('Asset is not currently in the specified source department.');
            }

            $transfer = new AssetTransfer();
            $transfer->fill($data);
            $transfer->transfer_number = $this->sequences->generate('asset_transfer');
            $transfer->requested_by = Auth::id();
            $transfer->status = TransferStatus::Pending;
            $transfer->save();

            return $transfer->fresh(['asset:id,asset_code,name', 'fromDepartment:id,name', 'toDepartment:id,name']);
        });
    }

    public function approve(AssetTransfer $transfer, User $by): AssetTransfer
    {
        if ($transfer->status !== TransferStatus::Pending) {
            throw new RuntimeException('Only pending transfers can be approved.');
        }

        if ((int) $transfer->requested_by === $by->id) {
            throw new RuntimeException('Cannot approve a transfer you requested.');
        }

        return DB::transaction(function () use ($transfer, $by) {
            $transfer->forceFill([
                'status'      => TransferStatus::Approved->value,
                'approved_by' => $by->id,
                'approved_at' => now(),
            ])->save();

            $transfer->asset->update(['department_id' => $transfer->to_department_id]);

            $transfer->forceFill(['status' => TransferStatus::Completed->value])->save();

            return $transfer->fresh(['asset:id,asset_code,name', 'fromDepartment:id,name', 'toDepartment:id,name']);
        });
    }

    public function reject(AssetTransfer $transfer, User $by): AssetTransfer
    {
        if ($transfer->status !== TransferStatus::Pending) {
            throw new RuntimeException('Only pending transfers can be rejected.');
        }

        $transfer->forceFill([
            'status'      => TransferStatus::Rejected->value,
            'approved_by' => $by->id,
            'approved_at' => now(),
        ])->save();

        return $transfer->fresh();
    }
}
