<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Foundation\Http\FormRequest;

/**
 * REC-08 — raise an MRB hold (quarantine nonconforming stock).
 */
class StoreMrbRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.mrb.manage') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'item_id'                => Item::class,
            'source_location_id'     => WarehouseLocation::class,
            'quarantine_location_id' => WarehouseLocation::class,
            'ncr_id'                 => NonConformanceReport::class,
            'inspection_id'          => Inspection::class,
        ];
    }

    public function rules(): array
    {
        return [
            'item_id'                => ['required', 'integer', 'exists:items,id'],
            'quantity'               => ['required', 'decimal:0,3', 'min:0.001'],
            'source_location_id'     => ['required', 'integer', 'exists:warehouse_locations,id'],
            'quarantine_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id', 'different:source_location_id'],
            'ncr_id'                 => ['nullable', 'integer', 'exists:non_conformance_reports,id'],
            'inspection_id'          => ['nullable', 'integer', 'exists:inspections,id'],
            'notes'                  => ['nullable', 'string', 'max:1000'],
        ];
    }
}
