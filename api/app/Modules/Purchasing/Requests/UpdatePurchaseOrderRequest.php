<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseOrderRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchasing.po.create') ?? false;
    }

    protected function hashIdFields(): array
    {
        return ['items.*.item_id' => Item::class];
    }

    public function rules(): array
    {
        return [
            'date'                   => ['nullable', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'is_vatable'             => ['nullable', 'boolean'],
            'remarks'                => ['nullable', 'string', 'max:1000'],
            'items'                  => ['nullable', 'array', 'min:1'],
            'items.*.item_id'        => ['required_with:items', 'integer', 'exists:items,id'],
            'items.*.description'    => ['required_with:items', 'string', 'min:2', 'max:200'],
            'items.*.quantity'       => ['required_with:items', 'decimal:0,2', 'min:0.01'],
            'items.*.unit'           => ['nullable', 'string', 'max:20'],
            'items.*.unit_price'     => ['required_with:items', 'decimal:0,2', 'min:0'],
        ];
    }
}
