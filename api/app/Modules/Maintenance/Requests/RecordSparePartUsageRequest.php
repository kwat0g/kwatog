<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use Illuminate\Foundation\Http\FormRequest;

class RecordSparePartUsageRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('maintenance.wo.complete');
    }

    protected function hashIdFields(): array
    {
        return [
            'item_id'     => Item::class,
            'location_id' => WarehouseLocation::class,
        ];
    }

    public function rules(): array
    {
        return [
            'item_id'     => ['required', 'integer', 'exists:items,id'],
            'location_id' => ['required', 'integer', 'exists:warehouse_locations,id'],
            'quantity'    => ['required', 'numeric', 'gt:0'],
        ];
    }
}
