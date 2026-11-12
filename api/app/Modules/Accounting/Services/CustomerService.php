<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\BusinessPolicyService;
use App\Common\Services\DocumentSequenceService;
use App\Common\Support\SearchOperator;
use App\Common\Support\TrashedFilter;

use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Support\CustomerInputSanitizer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\CRM\Models\CustomerProductWarrantyOption;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    public function __construct(
        private readonly BusinessPolicyService $policies,
        private readonly DocumentSequenceService $sequences,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = Customer::query()->withSum([
            'invoices as credit_used' => fn ($invoice) => $invoice
                ->whereIn('status', [InvoiceStatus::Finalized, InvoiceStatus::Partial]),
        ], 'balance');

        TrashedFilter::apply($q, $filters);

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('name', SearchOperator::like(), SearchOperator::contains($term))
                   ->orWhere('contact_person', SearchOperator::like(), SearchOperator::contains($term));
            });
        }

        return $q->orderBy('name')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(Customer $customer): Customer
    {
        return $customer->loadCount(['invoices']);
    }

    public function creditUsed(Customer $customer): string
    {
        return (string) Invoice::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [InvoiceStatus::Finalized, InvoiceStatus::Partial])
            ->sum('balance');
    }

    public function create(array $data): Customer
    {
        return DB::transaction(function () use ($data): Customer {
            $name = is_string($data['name'] ?? null) ? CustomerInputSanitizer::normalizeText($data['name']) : '';
            if ($name !== '') {
                $normalizedName = mb_strtolower($name, 'UTF-8');
                if (DB::connection()->getDriverName() === 'pgsql') {
                    // Serialize writes for the same normalized name, then
                    // recheck under the lock. This closes the race between
                    // request validation and concurrent customer creation.
                    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$normalizedName]);
                }

                if (Customer::query()->whereRaw(CustomerInputSanitizer::NORMALIZED_NAME_SQL.' = ?', [$normalizedName])->exists()) {
                    throw ValidationException::withMessages([
                        'name' => ['A customer with this name already exists. Open the existing customer record or use a distinct name.'],
                    ]);
                }
            }

            $portalProductIds = $data['portal_product_ids'] ?? [];
            $productOrderLimits = $data['product_order_limits'] ?? [];
            $productWarranties = $data['product_warranties'] ?? [];
            unset($data['portal_product_ids'], $data['product_order_limits'], $data['product_warranties']);

            $supportsOrderLimits = Schema::hasColumns('customer_product_access', [
                'minimum_order_quantity', 'maximum_order_quantity',
            ]);
            if (! $supportsOrderLimits && collect($productOrderLimits)->contains(static fn (array $limit): bool =>
                (int) ($limit['minimum_order_quantity'] ?? 1) !== 1
                || ($limit['maximum_order_quantity'] ?? null) !== null)) {
                throw ValidationException::withMessages([
                    'product_order_limits' => ['Customer quantity limits need a database update before they can be saved.'],
                ]);
            }

            $data['payment_terms_days'] ??= $this->policies->customerPaymentTermsDays();
            // Customer codes are stable ERP identifiers, not a data-entry
            // choice. Generate them in the write path so CRM and Accounting
            // callers cannot collide or bypass the sequence.
            $data['code'] = $this->sequences->generate('customer');

            $customer = Customer::create($data);
            if ($portalProductIds !== []) {
                if ($supportsOrderLimits) {
                    $limitsByProduct = collect($productOrderLimits)->keyBy(static fn (array $limit): int => (int) $limit['product_id']);
                    $syncData = [];
                    foreach ($portalProductIds as $productId) {
                        $limit = $limitsByProduct->get((int) $productId, []);
                        $syncData[(int) $productId] = [
                            'minimum_order_quantity' => $limit['minimum_order_quantity'] ?? 1,
                            'maximum_order_quantity' => $limit['maximum_order_quantity'] ?? null,
                        ];
                    }
                    $customer->portalProducts()->sync($syncData);
                } else {
                    $customer->portalProducts()->sync($portalProductIds);
                }
            }

            $positions = [];
            foreach ($productWarranties as $warranty) {
                $productId = (int) $warranty['product_id'];
                CustomerProductWarrantyOption::query()->create([
                    ...$warranty,
                    'customer_id' => $customer->id,
                    'position' => $positions[$productId] ?? 0,
                ]);
                $positions[$productId] = ($positions[$productId] ?? 0) + 1;
            }

            return $customer;
        });
    }

    public function update(Customer $customer, array $data): Customer
    {
        return DB::transaction(function () use ($customer, $data) {
            $customer->update($data);
            return $customer->fresh();
        });
    }

    public function delete(Customer $customer): void
    {
        if ($customer->invoices()->exists()) {
            throw new BusinessRuleException('Cannot delete a customer with invoices. Deactivate instead.');
        }
        $customer->delete();
    }
}
