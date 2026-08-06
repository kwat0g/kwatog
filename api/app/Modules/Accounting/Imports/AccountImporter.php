<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Imports;

use App\Common\Services\Import\EntityImporter;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * REC-03 — Chart of Accounts importer.
 * CSV columns: code, name, type, normal_balance, [description], [parent_code], [is_active].
 */
class AccountImporter implements EntityImporter
{
    public function key(): string
    {
        return 'coa';
    }

    public function requiredColumns(): array
    {
        return ['code', 'name', 'type', 'normal_balance'];
    }

    public function importRow(array $row): Model
    {
        $code = trim($row['code'] ?? '');
        $name = trim($row['name'] ?? '');
        $type = strtolower(trim($row['type'] ?? ''));
        $normal = strtolower(trim($row['normal_balance'] ?? ''));

        if ($code === '' || $name === '') {
            throw new RuntimeException('code and name are required.');
        }
        if (! in_array($type, AccountType::values(), true)) {
            throw new RuntimeException("Invalid account type '{$type}'. Expected one of: ".implode(', ', AccountType::values()));
        }
        if (! in_array($normal, ['debit', 'credit'], true)) {
            throw new RuntimeException("normal_balance must be 'debit' or 'credit', got '{$normal}'.");
        }
        if (Account::query()->where('code', $code)->exists()) {
            throw new RuntimeException("Account code '{$code}' already exists.");
        }

        $parentId = null;
        $parentCode = trim($row['parent_code'] ?? '');
        if ($parentCode !== '') {
            $parentId = Account::query()->where('code', $parentCode)->value('id');
            if (! $parentId) {
                throw new RuntimeException("parent_code '{$parentCode}' not found (import parents before children).");
            }
        }

        $payload = [
            'code'           => $code,
            'name'           => $name,
            'type'           => $type,
            'normal_balance' => $normal,
            'description'    => trim($row['description'] ?? '') ?: null,
            'parent_id'      => $parentId,
        ];
        $active = trim($row['is_active'] ?? '');
        if ($active !== '') {
            $payload['is_active'] = filter_var($active, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($payload['is_active'] === null) throw new RuntimeException('is_active must be true or false.');
        }

        return Account::create($payload);
    }
}
