<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Imports;

use App\Common\Services\Import\EntityImporter;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * REC-03 — approved-supplier (ASL) link importer.
 * CSV columns: item_code, vendor_name, [lead_time_days], [last_price],
 *   [is_preferred], [qualification_status].
 *
 * The vendor master has no stable code column, so the vendor is resolved by
 * name (case-sensitive; import vendors first or match the existing spelling).
 * Both sides must already exist — this importer links catalogue rows, it does
 * not create items or vendors. qualification_status defaults to `approved`.
 */
class ApprovedSupplierImporter implements EntityImporter
{
    public function key(): string
    {
        return 'approved_suppliers';
    }

    public function requiredColumns(): array
    {
        return ['item_code', 'vendor_name'];
    }

    public function importRow(array $row): Model
    {
        $itemCode = trim($row['item_code'] ?? '');
        $vendorName = trim($row['vendor_name'] ?? '');

        if ($itemCode === '') {
            throw new RuntimeException('item_code is required.');
        }
        if ($vendorName === '') {
            throw new RuntimeException('vendor_name is required.');
        }

        $item = Item::query()->where('code', $itemCode)->first();
        if (! $item) {
            throw new RuntimeException("Item '{$itemCode}' was not found. Check the code or import the item first.");
        }

        $vendor = Vendor::query()->where('name', $vendorName)->first();
        if (! $vendor) {
            throw new RuntimeException("Vendor '{$vendorName}' was not found. Check the name or import the vendor first.");
        }

        if (ApprovedSupplier::query()
            ->where('item_id', $item->id)
            ->where('vendor_id', $vendor->id)
            ->exists()) {
            throw new RuntimeException("An approved-supplier link already exists for item '{$itemCode}' and vendor '{$vendorName}'.");
        }

        $leadTime = trim($row['lead_time_days'] ?? '');
        if ($leadTime !== '' && (! ctype_digit($leadTime) || (int) $leadTime < 0)) {
            throw new RuntimeException('lead_time_days must be a non-negative integer.');
        }

        $lastPrice = trim($row['last_price'] ?? '');
        if ($lastPrice !== '' && (! is_numeric($lastPrice) || (float) $lastPrice < 0)) {
            throw new RuntimeException('last_price must be a non-negative number.');
        }

        $qualification = strtolower(trim($row['qualification_status'] ?? ''));
        $qualification = $qualification === '' ? ApprovedSupplier::QUALIFICATION_APPROVED : $qualification;
        if (! in_array($qualification, [
            ApprovedSupplier::QUALIFICATION_APPROVED,
            ApprovedSupplier::QUALIFICATION_PROVISIONAL,
        ], true)) {
            throw new RuntimeException(
                "Invalid qualification_status '{$qualification}'. Expected one of: "
                .ApprovedSupplier::QUALIFICATION_APPROVED.', '.ApprovedSupplier::QUALIFICATION_PROVISIONAL.'.'
            );
        }

        $isPreferred = false;
        $rawPreferred = trim($row['is_preferred'] ?? '');
        if ($rawPreferred !== '') {
            $parsed = filter_var($rawPreferred, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed === null) {
                throw new RuntimeException('is_preferred must be true or false.');
            }
            $isPreferred = $parsed;
        }

        if ($isPreferred) {
            // "One preferred per item" is a partial unique index; clear the
            // incumbent so this row can take the flag.
            ApprovedSupplier::query()
                ->where('item_id', $item->id)
                ->update(['is_preferred' => false]);
        }

        return ApprovedSupplier::create([
            'item_id' => $item->id,
            'vendor_id' => $vendor->id,
            'qualification_status' => $qualification,
            'lead_time_days' => $leadTime !== '' ? (int) $leadTime : 0,
            'last_price' => $lastPrice !== '' ? $lastPrice : null,
            'is_preferred' => $isPreferred,
        ]);
    }
}
