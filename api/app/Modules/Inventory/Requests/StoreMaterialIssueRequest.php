<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use Illuminate\Foundation\Http\FormRequest;

class StoreMaterialIssueRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.issue.create') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'items.*.item_id'     => Item::class,
            'items.*.location_id' => WarehouseLocation::class,
        ];
    }

    public function rules(): array
    {
        return [
            'work_order_id'                   => ['nullable', 'integer'],
            'issued_date'                     => ['required', 'date'],
            'reference_text'                  => ['nullable', 'string', 'max:200'],
            'remarks'                         => ['nullable', 'string', 'max:1000'],
            'items'                           => ['required', 'array', 'min:1'],
            'items.*.item_id'                 => ['required', 'integer', 'exists:items,id'],
            'items.*.location_id'             => ['required', 'integer', 'exists:warehouse_locations,id'],
            'items.*.quantity_issued'         => ['required', 'decimal:0,3', 'min:0.001'],
            'items.*.material_reservation_id' => ['nullable', 'integer'],
            'items.*.remarks'                 => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'At least one line item is required.',
            'items.min'      => 'At least one line item is required.',
        ];
    }
}
