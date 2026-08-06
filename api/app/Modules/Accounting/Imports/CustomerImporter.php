<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Imports;

use App\Common\Services\Import\EntityImporter;
use App\Common\Services\BusinessPolicyService;
use App\Modules\Accounting\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * REC-03 — customer master importer.
 * CSV columns: name (required); optional code, contact_person, email, phone,
 * address, tin, credit_limit, payment_terms_days, is_active.
 */
class CustomerImporter implements EntityImporter
{
    public function __construct(private readonly BusinessPolicyService $policies) {}

    public function key(): string
    {
        return 'customers';
    }

    public function requiredColumns(): array
    {
        return ['name'];
    }

    public function importRow(array $row): Model
    {
        $name = trim($row['name'] ?? '');
        if ($name === '') {
            throw new RuntimeException('name is required.');
        }

        $code = trim($row['code'] ?? '') ?: null;
        if ($code !== null && Customer::query()->where('code', $code)->exists()) {
            throw new RuntimeException("Customer code '{$code}' already exists.");
        }
        if (Customer::query()->where('name', $name)->exists()) {
            throw new RuntimeException("Customer '{$name}' already exists.");
        }

        $terms = trim($row['payment_terms_days'] ?? '');
        $credit = trim($row['credit_limit'] ?? '');

        $payload = [
            'name'               => $name,
            'code'               => $code,
            'contact_person'     => trim($row['contact_person'] ?? '') ?: null,
            'email'              => trim($row['email'] ?? '') ?: null,
            'phone'              => trim($row['phone'] ?? '') ?: null,
            'address'            => trim($row['address'] ?? '') ?: null,
            'tin'                => trim($row['tin'] ?? '') ?: null,
            'credit_limit'       => $credit !== '' ? $credit : null,
            'payment_terms_days' => $terms !== '' ? (int) $terms : $this->policies->customerPaymentTermsDays(),
        ];
        $active = trim($row['is_active'] ?? '');
        if ($active !== '') {
            $payload['is_active'] = filter_var($active, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($payload['is_active'] === null) throw new RuntimeException('is_active must be true or false.');
        }

        return Customer::create($payload);
    }
}
