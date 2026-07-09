<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Imports;

use App\Common\Services\Import\EntityImporter;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * REC-03 — raw-material / finished-good item importer.
 * CSV columns: code, name, item_type, unit_of_measure, category,
 *   [standard_cost], [description], [reorder_point], [lead_time_days].
 * The category is resolved (or created) by name.
 */
class ItemImporter implements EntityImporter
{
    public function key(): string
    {
        return 'items';
    }

    public function requiredColumns(): array
    {
        return ['code', 'name', 'item_type', 'unit_of_measure', 'category'];
    }

    public function importRow(array $row): Model
    {
        $code = trim($row['code'] ?? '');
        $name = trim($row['name'] ?? '');
        $type = strtolower(trim($row['item_type'] ?? ''));
        $uom  = trim($row['unit_of_measure'] ?? '');
        $categoryName = trim($row['category'] ?? '');

        if ($code === '' || $name === '') {
            throw new RuntimeException('code and name are required.');
        }
        if (! in_array($type, ItemType::values(), true)) {
            throw new RuntimeException("Invalid item_type '{$type}'. Expected one of: ".implode(', ', ItemType::values()));
        }
        if ($uom === '') {
            throw new RuntimeException('unit_of_measure is required.');
        }
        if ($categoryName === '') {
            throw new RuntimeException('category is required.');
        }
        if (Item::query()->where('code', $code)->exists()) {
            throw new RuntimeException("Item code '{$code}' already exists.");
        }

        $category = ItemCategory::firstOrCreate(['name' => $categoryName]);

        $stdCost = trim($row['standard_cost'] ?? '');

        return Item::create([
            'code'            => $code,
            'name'            => $name,
            'description'     => trim($row['description'] ?? '') ?: null,
            'category_id'     => $category->id,
            'item_type'       => $type,
            'unit_of_measure' => $uom,
            'standard_cost'   => $stdCost !== '' ? $stdCost : '0',
            'reorder_point'   => trim($row['reorder_point'] ?? '') ?: '0',
            'lead_time_days'  => (int) (trim($row['lead_time_days'] ?? '') ?: 0),
            'is_active'       => true,
        ]);
    }
}
