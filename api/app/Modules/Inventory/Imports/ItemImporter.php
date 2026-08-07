<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Imports;

use App\Common\Services\Import\EntityImporter;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\ReorderMethod;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Inventory\Models\Uom;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * REC-03 — raw-material / finished-good item importer.
 * CSV columns: code, name, item_type, unit_of_measure, category,
 *   standard_cost, reorder_method, reorder_point, safety_stock,
 *   minimum_order_quantity, lead_time_days, [description], [is_active].
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
        return [
            'code', 'name', 'item_type', 'unit_of_measure', 'category',
            'standard_cost', 'reorder_method', 'reorder_point', 'safety_stock',
            'minimum_order_quantity', 'lead_time_days',
        ];
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
        if (strlen($uom) > 20) {
            throw new RuntimeException("unit_of_measure '{$uom}' exceeds the 20-character limit.");
        }
        if ($categoryName === '') {
            throw new RuntimeException('category is required.');
        }
        if (Item::query()->where('code', $code)->exists()) {
            throw new RuntimeException("Item code '{$code}' already exists.");
        }

        // Resolve the unit of measure against the canonical `uoms` catalog.
        // Case is normalized so `kg`/`Kg` map to `KG`; unknown units are
        // created (uppercased) so conversions can reference them later.
        $uomRow = Uom::firstOrCreate(
            ['code' => mb_strtoupper($uom)],
            ['name' => mb_strtoupper($uom)],
        );
        $uom = $uomRow->code;

        $category = ItemCategory::firstOrCreate(['name' => $categoryName]);

        $stdCost = trim($row['standard_cost'] ?? '');
        $reorderMethod = strtolower(trim($row['reorder_method'] ?? ''));
        $reorderPoint = trim($row['reorder_point'] ?? '');
        $safetyStock = trim($row['safety_stock'] ?? '');
        $minimumOrderQuantity = trim($row['minimum_order_quantity'] ?? '');
        $leadTimeDays = trim($row['lead_time_days'] ?? '');
        if ($stdCost === '' || ! is_numeric($stdCost) || (float) $stdCost < 0) {
            throw new RuntimeException('standard_cost is required and must be non-negative.');
        }
        if (! in_array($reorderMethod, ReorderMethod::values(), true)) {
            throw new RuntimeException("Invalid reorder_method '{$reorderMethod}'. Expected one of: ".implode(', ', ReorderMethod::values()));
        }
        foreach (['reorder_point' => $reorderPoint, 'safety_stock' => $safetyStock, 'minimum_order_quantity' => $minimumOrderQuantity] as $field => $value) {
            if ($value === '' || ! is_numeric($value) || (float) $value < 0 || ($field === 'minimum_order_quantity' && (float) $value <= 0)) {
                throw new RuntimeException("{$field} is required and must be a valid non-negative quantity.");
            }
        }
        if ($leadTimeDays === '' || filter_var($leadTimeDays, FILTER_VALIDATE_INT) === false || (int) $leadTimeDays < 0) {
            throw new RuntimeException('lead_time_days is required and must be a non-negative integer.');
        }

        $payload = [
            'code'            => $code,
            'name'            => $name,
            'description'     => trim($row['description'] ?? '') ?: null,
            'category_id'     => $category->id,
            'item_type'       => $type,
            'unit_of_measure' => $uom,
            'standard_cost'   => $stdCost,
            'reorder_method'  => $reorderMethod,
            'reorder_point'   => $reorderPoint,
            'safety_stock'    => $safetyStock,
            'minimum_order_quantity' => $minimumOrderQuantity,
            'lead_time_days'  => (int) $leadTimeDays,
        ];
        $active = trim($row['is_active'] ?? '');
        if ($active !== '') {
            $payload['is_active'] = filter_var($active, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($payload['is_active'] === null) throw new RuntimeException('is_active must be true or false.');
        }

        return Item::create($payload);
    }
}
