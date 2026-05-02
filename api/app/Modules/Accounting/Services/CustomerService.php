<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CustomerService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = Customer::query();

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
        return DB::transaction(fn () => Customer::create($data));
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
            throw new RuntimeException('Cannot delete a customer with invoices. Deactivate instead.');
        }
        $customer->delete();
    }
}
