<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Imports;

use App\Common\Services\Import\EntityImporter;
use App\Modules\Accounting\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * REC-03 — customer master importer.
 * CSV columns: name (required); optional code, contact_person, email, phone,
 * address, tin, credit_limit, payment_terms_days.
 */
class CustomerImporter implements EntityImporter
{
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

        return Customer::create([
            'name'               => $name,
            'code'               => $code,
            'contact_person'     => trim($row['contact_person'] ?? '') ?: null,
            'email'              => trim($row['email'] ?? '') ?: null,
            'phone'              => trim($row['phone'] ?? '') ?: null,
            'address'            => trim($row['address'] ?? '') ?: null,
            'tin'                => trim($row['tin'] ?? '') ?: null,
            'credit_limit'       => $credit !== '' ? $credit : null,
            'payment_terms_days' => $terms !== '' ? (int) $terms : 30,
            'is_active'          => true,
        ]);
    }
}
