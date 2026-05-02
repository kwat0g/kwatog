<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Exceptions\NoPriceAgreementException;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single sanctioned price entry point in the codebase.
 * SO line items pull from here at create time and freeze the resolved unit_price.
 */
class PriceAgreementService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = PriceAgreement::query()
            ->with(['product:id,part_number,name,unit_of_measure', 'customer:id,name']);

        if (! empty($filters['customer_id'])) {
            $cid = HashIdFilter::decode($filters['customer_id'], Customer::class);
            if ($cid) $q->where('customer_id', $cid);
        }
        if (! empty($filters['product_id'])) {
            $pid = HashIdFilter::decode($filters['product_id'], Product::class);
            if ($pid) $q->where('product_id', $pid);
        }
        if (! empty($filters['active_on'])) {
            $q->whereDate('effective_from', '<=', $filters['active_on'])
              ->whereDate('effective_to', '>=', $filters['active_on']);
        }

        return $q->orderByDesc('effective_from')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function listForCustomer(int $customerId): Collection
    {
        return PriceAgreement::with('product:id,part_number,name,unit_of_measure')
            ->where('customer_id', $customerId)
            ->orderByDesc('effective_from')
            ->get();
    }

    public function show(PriceAgreement $a): PriceAgreement
    {
        return $a->load(['product', 'customer']);
    }

    public function create(array $data): PriceAgreement
    {
        return DB::transaction(function () use ($data) {
            $this->assertNoOverlap(
                (int) $data['product_id'],
                (int) $data['customer_id'],
                $data['effective_from'],
                $data['effective_to'],
            );
            return PriceAgreement::create($data)->load(['product', 'customer']);
        });
    }

    public function update(PriceAgreement $a, array $data): PriceAgreement
    {
        return DB::transaction(function () use ($a, $data) {
            $this->assertNoOverlap(
                (int) ($data['product_id'] ?? $a->product_id),
                (int) ($data['customer_id'] ?? $a->customer_id),
                $data['effective_from'] ?? $a->effective_from->toDateString(),
                $data['effective_to']   ?? $a->effective_to->toDateString(),
                exceptId: $a->id,
            );
            $a->update($data);
            return $a->fresh()->load(['product', 'customer']);
        });
    }

    public function delete(PriceAgreement $a): void
    {
        $a->delete();
    }

    /**
     * The pricing read path. Returns the price agreement that covers the given
     * (customer, product) on the specified date — or throws.
     */
    public function resolve(int $customerId, int $productId, CarbonInterface $date): PriceAgreement
    {
        $found = PriceAgreement::query()
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->whereDate('effective_from', '<=', $date)
            ->whereDate('effective_to', '>=', $date)
            ->orderByDesc('effective_from')
            ->first();

        if (! $found) {
            throw new NoPriceAgreementException();
        }
        return $found;
    }

    /**
     * Service-level uniqueness rule: no two overlapping windows for the
     * same (customer, product). Throws RuntimeException on overlap.
     */
    private function assertNoOverlap(
        int $productId,
        int $customerId,
        string $from,
        string $to,
        ?int $exceptId = null,
    ): void {
        if ($from > $to) {
            throw new RuntimeException('effective_from must be on or before effective_to.');
        }
        $q = PriceAgreement::query()
            ->where('product_id', $productId)
            ->where('customer_id', $customerId)
            ->whereDate('effective_from', '<=', $to)
            ->whereDate('effective_to', '>=', $from);
        if ($exceptId !== null) $q->where('id', '!=', $exceptId);
        if ($q->exists()) {
            throw new RuntimeException('A price agreement already exists for this customer/product in the selected date range.');
        }
    }
}
