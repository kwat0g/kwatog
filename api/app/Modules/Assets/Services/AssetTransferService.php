<?php

declare(strict_types=1);

namespace App\Modules\Assets\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Support\HashIdFilter;
use App\Modules\Assets\Enums\TransferStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetTransfer;
use App\Modules\Auth\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
            $q->where('asset_id', HashIdFilter::decode($filters['asset_id'], Asset::class) ?? 0);
        }

        return $q->orderByDesc('created_at')
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function create(array $data): AssetTransfer
    {
        return DB::transaction(function () use ($data) {
            $asset = Asset::findOrFail($data['asset_id']);

            if ((int) $asset->department_id !== (int) $data['from_department_id']) {
                throw new BusinessRuleException('Asset is not currently in the specified source department.');
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
            throw new BusinessRuleException('Only pending transfers can be approved.');
        }

        if ((int) $transfer->requested_by === $by->id) {
            throw new BusinessRuleException('Cannot approve a transfer you requested.');
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
            throw new BusinessRuleException('Only pending transfers can be rejected.');
        }

        $transfer->forceFill([
            'status'      => TransferStatus::Rejected->value,
            'approved_by' => $by->id,
            'approved_at' => now(),
        ])->save();

        // Match create()/approve(): fresh() with no arguments drops eager loads,
        // so the resource would omit asset / departments.
        return $transfer->fresh(['asset:id,asset_code,name', 'fromDepartment:id,name', 'toDepartment:id,name']);
    }
}
