<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Common\Support\SearchOperator;
use App\Common\Support\TrashedFilter;
use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Enums\PricingMethod;
use App\Modules\CRM\Exceptions\NoPriceAgreementException;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Models\Product;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        TrashedFilter::apply($q, $filters);

        if (! empty($filters['customer_id'])) {
            $cid = HashIdFilter::decode($filters['customer_id'], Customer::class);
            if ($cid) $q->where('customer_id', $cid);
        }
        if (! empty($filters['product_id'])) {
            $pid = HashIdFilter::decode($filters['product_id'], Product::class);
            if ($pid) $q->where('product_id', $pid);
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term): void {
                $qq->whereHas('product', function ($product) use ($term): void {
                    $product->where('part_number', SearchOperator::like(), "%{$term}%")
                        ->orWhere('name', SearchOperator::like(), "%{$term}%");
                })->orWhereHas('customer', function ($customer) use ($term): void {
                    $customer->where('name', SearchOperator::like(), "%{$term}%");
                });
            });
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
            $data = $this->normalisePricingData($data);
            $productId = (int) $data['product_id'];
            $customerId = (int) $data['customer_id'];

            $this->lockAgreementReferences([$productId], [$customerId]);
            $this->assertActiveReferences($productId, $customerId);
            $this->assertNoOverlap(
                $productId,
                $customerId,
                $data['effective_from'],
                $data['effective_to'],
            );
            return PriceAgreement::create($data)->load(['product', 'customer']);
        });
    }

    public function update(PriceAgreement $a, array $data): PriceAgreement
    {
        return DB::transaction(function () use ($a, $data) {
            $requestedProductId = (int) ($data['product_id'] ?? $a->product_id);
            $requestedCustomerId = (int) ($data['customer_id'] ?? $a->customer_id);
            $this->lockAgreementReferences(
                [$a->product_id, $requestedProductId],
                [$a->customer_id, $requestedCustomerId],
            );

            $locked = PriceAgreement::query()->lockForUpdate()->findOrFail($a->id);
            $data = $this->normalisePricingData($data, $locked);
            $productId = (int) ($data['product_id'] ?? $locked->product_id);
            $customerId = (int) ($data['customer_id'] ?? $locked->customer_id);
            $this->assertActiveReferences($productId, $customerId);
            $this->assertNoOverlap(
                $productId,
                $customerId,
                $data['effective_from'] ?? $locked->effective_from->toDateString(),
                $data['effective_to']   ?? $locked->effective_to->toDateString(),
                exceptId: $locked->id,
            );
            $locked->update($data);
            return $locked->fresh()->load(['product', 'customer']);
        });
    }

    public function delete(PriceAgreement $a): void
    {
        DB::transaction(fn () => PriceAgreement::query()->lockForUpdate()->findOrFail($a->id)->delete());
    }

    public function restore(PriceAgreement $a): PriceAgreement
    {
        return DB::transaction(function () use ($a): PriceAgreement {
            $this->lockAgreementReferences([$a->product_id], [$a->customer_id]);
            $locked = PriceAgreement::withTrashed()->lockForUpdate()->findOrFail($a->id);

            if (! $locked->trashed()) {
                return $locked->fresh()->load(['product', 'customer']);
            }

            $this->assertActiveReferences((int) $locked->product_id, (int) $locked->customer_id);
            $this->assertNoOverlap(
                (int) $locked->product_id,
                (int) $locked->customer_id,
                $locked->effective_from->toDateString(),
                $locked->effective_to->toDateString(),
                exceptId: $locked->id,
            );
            $locked->restore();

            return $locked->fresh()->load(['product', 'customer']);
        });
    }

    /**
     * Find the PriceAgreement covering the given (customer, product, date).
     * Throws NoPriceAgreementException if none is found.
     */
    public function resolve(int $customerId, int $productId, CarbonInterface $date): PriceAgreement
    {
        $found = PriceAgreement::query()
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->whereHas('customer', fn ($customer) => $customer->active())
            ->whereHas('product', fn ($product) => $product->active())
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
     * Resolve the effective unit price for a given PriceAgreement and quantity.
     *
     * - If pricing_method = 'tiered' and tiers are defined, the highest tier
     *   whose min_qty <= quantity is selected.
     * - If no tier applies (quantity below all min_qty), the first (lowest) tier
     *   is used as a fallback.
     * - If pricing_method = 'flat' or tiers is null/empty, the flat price is returned.
     */
    public function resolveUnitPrice(PriceAgreement $agreement, string|int $quantity = 1): string
    {
        if ($agreement->pricing_method === PricingMethod::Tiered && is_array($agreement->tiers) && count($agreement->tiers) > 0) {
            $tiers = collect($agreement->tiers)->sortByDesc('min_qty');
            $best = $tiers->first(
                fn (array $t): bool => Money::gte((string) $quantity, (string) ($t['min_qty'] ?? 0)),
            );

            if ($best !== null) {
                return Money::round2((string) ($best['unit_price'] ?? $agreement->price));
            }

            // Quantity is below the smallest tier's min_qty — use the lowest tier price.
            $lowest = $tiers->last();
            return Money::round2((string) ($lowest['unit_price'] ?? $agreement->price));
        }

        return Money::round2((string) $agreement->price);
    }

    /**
     * Service-level uniqueness rule: no two overlapping windows for the
     * same (customer, product).
     */
    private function assertNoOverlap(
        int $productId,
        int $customerId,
        string $from,
        string $to,
        ?int $exceptId = null,
    ): void {
        // Normalise to Y-m-d BEFORE comparing. Both FormRequests accept `date`,
        // not `date_format:Y-m-d`, so either bound can arrive in any format
        // strtotime() understands. A raw string comparison then sorts a non-ISO
        // bound wrongly against an ISO one — '12/01/2026' < '2026-03-31' because
        // '1' < '2' — so the guard below silently passed and an impossible
        // window was persisted. Nothing can ever satisfy resolve() inside an
        // inverted window, so that quietly removed every price for the pair.
        // Normalising also stops the whereDate() bindings depending on the
        // server's DateStyle to interpret an ambiguous bound.
        $from = CarbonImmutable::parse($from)->toDateString();
        $to = CarbonImmutable::parse($to)->toDateString();

        if ($from > $to) {
            // Keyed to effective_to because that is where both FormRequests put
            // the same rule (`after_or_equal:effective_from`), so the operator
            // sees the error against the same field whichever layer catches it.
            // Reachable despite those rules: a PATCH that sends only
            // effective_from leaves effective_to absent, and `sometimes` skips
            // the comparison entirely.
            throw ValidationException::withMessages([
                'effective_to' => ['The effective from date must be on or before the effective to date.'],
            ]);
        }
        $q = PriceAgreement::query()
            ->where('product_id', $productId)
            ->where('customer_id', $customerId)
            ->whereDate('effective_from', '<=', $to)
            ->whereDate('effective_to', '>=', $from);
        if ($exceptId !== null) $q->where('id', '!=', $exceptId);
        if ($q->exists()) {
            // Not a field error — the conflict is with another record, and no
            // single input on this form is the wrong one.
            throw new BusinessRuleException('A price agreement already exists for this customer/product in the selected date range.');
        }
    }

    /**
     * Lock the stable master-data rows used as the agreement's coordination key.
     * Every create/update/restore path takes these locks before checking windows,
     * so two application writers for the same product/customer cannot both pass
     * the overlap query before either writes.
     *
     * @param list<int> $productIds
     * @param list<int> $customerIds
     */
    private function lockAgreementReferences(array $productIds, array $customerIds): void
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $customerIds = array_values(array_unique(array_map('intval', $customerIds)));
        sort($productIds);
        sort($customerIds);

        foreach ($productIds as $productId) {
            Product::query()->lockForUpdate()->find($productId);
        }
        foreach ($customerIds as $customerId) {
            Customer::query()->lockForUpdate()->find($customerId);
        }
    }

    private function assertActiveReferences(int $productId, int $customerId): void
    {
        $errors = [];
        if (! Product::query()->active()->whereKey($productId)->exists()) {
            $errors['product_id'][] = 'The selected product must be active and not archived.';
        }
        if (! Customer::query()->active()->whereKey($customerId)->exists()) {
            $errors['customer_id'][] = 'The selected customer must be active and not archived.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Apply the API contract at the service boundary too: flat agreements do
     * not carry tiers; tiered agreements require strictly ascending, unique
     * quantity thresholds and centavo prices.
     */
    private function normalisePricingData(array $data, ?PriceAgreement $existing = null): array
    {
        $method = $data['pricing_method'] ?? $existing?->pricing_method ?? PricingMethod::default();
        $method = $method instanceof PricingMethod ? $method->value : ((string) $method ?: PricingMethod::default()->value);
        $tiersProvided = array_key_exists('tiers', $data);
        $tiers = $tiersProvided ? $data['tiers'] : ($existing?->tiers ?? null);

        $this->assertTierContract($method, $tiers);
        $data['pricing_method'] = $method;

        if ($method === PricingMethod::Flat->value && ($tiersProvided || $existing === null || array_key_exists('pricing_method', $data))) {
            $data['tiers'] = null;
        } elseif (is_array($tiers)) {
            $data['tiers'] = $this->normaliseTiers($tiers);
        }

        return $data;
    }

    private function assertTierContract(string $method, mixed $tiers): void
    {
        $errors = [];
        if (! in_array($method, PricingMethod::values(), true)) {
            $errors['pricing_method'][] = 'The selected pricing method is invalid.';
        } elseif ($method === PricingMethod::Tiered->value && (! is_array($tiers) || $tiers === [])) {
            $errors['tiers'][] = 'Tiered pricing requires at least one price tier.';
        } elseif ($method === PricingMethod::Flat->value && is_array($tiers) && $tiers !== []) {
            $errors['tiers'][] = 'Price tiers are only allowed for tiered pricing.';
        }

        if (is_array($tiers)) {
            $previous = 0;
            foreach ($tiers as $index => $tier) {
                $minQty = is_array($tier) ? ($tier['min_qty'] ?? null) : null;
                if (! is_int($minQty) && ! (is_string($minQty) && ctype_digit($minQty))) {
                    $errors["tiers.{$index}.min_qty"][] = 'Tier minimum quantities must be positive whole numbers.';
                    continue;
                }
                $minQty = (int) $minQty;
                if ($minQty < 1) {
                    $errors["tiers.{$index}.min_qty"][] = 'Tier minimum quantities must be positive whole numbers.';
                }
                if ($minQty <= $previous) {
                    $errors["tiers.{$index}.min_qty"][] = 'Tier minimum quantities must be strictly ascending and unique.';
                }
                $previous = $minQty;

                $unitPrice = is_array($tier) ? (string) ($tier['unit_price'] ?? '') : '';
                if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $unitPrice)) {
                    $errors["tiers.{$index}.unit_price"][] = 'Tier unit prices must be non-negative amounts with up to 2 decimal places.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param list<array{min_qty: int|string, unit_price: string|int}> $tiers */
    private function normaliseTiers(array $tiers): array
    {
        return array_map(static fn (array $tier): array => [
            'min_qty' => (int) $tier['min_qty'],
            'unit_price' => Money::round2((string) $tier['unit_price']),
        ], $tiers);
    }
}
