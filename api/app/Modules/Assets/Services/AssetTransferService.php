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
        return DB::transaction(function () use ($transfer, $by) {
            // Lock-then-guard: re-read the authoritative row so a concurrent
            // rejection holding a stale pending instance cannot land after the
            // asset already moved (asset relocated while ledger says Rejected).
            $locked = AssetTransfer::query()->lockForUpdate()->findOrFail($transfer->getKey());
            if ($locked->status !== TransferStatus::Pending) {
                throw new BusinessRuleException('Only pending transfers can be approved.');
            }
            if ((int) $locked->requested_by === $by->id) {
                throw new BusinessRuleException('Cannot approve a transfer you requested.');
            }

            $locked->forceFill([
                'status'      => TransferStatus::Approved->value,
                'approved_by' => $by->id,
                'approved_at' => now(),
            ])->save();

            $locked->asset->update(['department_id' => $locked->to_department_id]);

            $locked->forceFill(['status' => TransferStatus::Completed->value])->save();

            return $locked->fresh(['asset:id,asset_code,name', 'fromDepartment:id,name', 'toDepartment:id,name']);
        });
    }

    public function reject(AssetTransfer $transfer, User $by): AssetTransfer
    {
        return DB::transaction(function () use ($transfer, $by) {
            // Lock-then-guard: without it, reject could race a concurrent
            // approval and mark the transfer Rejected after its asset moved.
            $locked = AssetTransfer::query()->lockForUpdate()->findOrFail($transfer->getKey());
            if ($locked->status !== TransferStatus::Pending) {
                throw new BusinessRuleException('Only pending transfers can be rejected.');
            }

            $locked->forceFill([
                'status'      => TransferStatus::Rejected->value,
                'approved_by' => $by->id,
                'approved_at' => now(),
            ])->save();

            // Match create()/approve(): fresh() with no arguments drops eager loads,
            // so the resource would omit asset / departments.
            return $locked->fresh(['asset:id,asset_code,name', 'fromDepartment:id,name', 'toDepartment:id,name']);
        });
    }
}
