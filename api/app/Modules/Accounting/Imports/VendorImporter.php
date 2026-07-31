<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Imports;

use App\Common\Services\Import\EntityImporter;
use App\Common\Services\BusinessPolicyService;
use App\Modules\Accounting\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * REC-03 — vendor/supplier master importer.
 * CSV columns: name (required); optional contact_person, email, phone,
 * address, tin, payment_terms_days.
 */
class VendorImporter implements EntityImporter
{
    public function __construct(private readonly BusinessPolicyService $policies) {}

    public function key(): string
    {
        return 'vendors';
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
        if (Vendor::query()->where('name', $name)->exists()) {
            throw new RuntimeException("Vendor '{$name}' already exists.");
        }

        $terms = trim($row['payment_terms_days'] ?? '');

        return Vendor::create([
            'name'               => $name,
            'contact_person'     => trim($row['contact_person'] ?? '') ?: null,
            'email'              => trim($row['email'] ?? '') ?: null,
            'phone'              => trim($row['phone'] ?? '') ?: null,
            'address'            => trim($row['address'] ?? '') ?: null,
            'tin'                => trim($row['tin'] ?? '') ?: null,
            'payment_terms_days' => $terms !== '' ? (int) $terms : $this->policies->vendorPaymentTermsDays(),
            'is_active'          => true,
        ]);
    }
}
