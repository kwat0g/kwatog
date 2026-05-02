<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Vendor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class VendorService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = Vendor::query();

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('name', 'ilike', "%{$term}%")
                   ->orWhere('contact_person', 'ilike', "%{$term}%");
            });
        }

        return $q->orderBy('name')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(Vendor $vendor): Vendor
    {
        return $vendor->loadCount(['bills']);
    }

    public function create(array $data): Vendor
    {
        return DB::transaction(fn () => Vendor::create($data));
    }

    public function update(Vendor $vendor, array $data): Vendor
    {
        return DB::transaction(function () use ($vendor, $data) {
            $vendor->update($data);
            return $vendor->fresh();
        });
    }

    public function delete(Vendor $vendor): void
    {
        if ($vendor->bills()->exists()) {
            throw new RuntimeException('Cannot delete a vendor with bills. Deactivate instead.');
        }
        $vendor->delete();
    }
}
