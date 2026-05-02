<?php

declare(strict_types=1);

namespace App\Modules\MRP\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Http\FormRequest;

class StoreBomRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('mrp.boms.manage') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'product_id'      => Product::class,
            'items.*.item_id' => Item::class,
        ];
    }

    public function rules(): array
    {
        return [
            'product_id'                => ['required', 'integer', 'exists:products,id'],
            'items'                     => ['required', 'array', 'min:1'],
            'items.*.item_id'           => ['required', 'integer', 'exists:items,id'],
            'items.*.quantity_per_unit' => ['required', 'decimal:0,4', 'min:0.0001'],
            'items.*.unit'              => ['required', 'string', 'max:20'],
            'items.*.waste_factor'      => ['nullable', 'decimal:0,2', 'min:0', 'max:50'],
            'items.*.sort_order'        => ['nullable', 'integer', 'min:0'],
        ];
    }
}
