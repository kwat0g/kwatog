<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGrnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.grn.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'purchase_order_id'             => ['required'],
            'received_date'                 => ['nullable', 'date'],
            'remarks'                       => ['nullable', 'string', 'max:1000'],
            'items'                         => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id'=> ['required'],
            'items.*.item_id'               => ['required'],
            'items.*.location_id'           => ['required'],
            'items.*.quantity_received'     => ['required', 'decimal:0,3', 'min:0.001'],
            'items.*.unit_cost'             => ['nullable', 'decimal:0,4', 'min:0'],
            'items.*.remarks'               => ['nullable', 'string', 'max:200'],
        ];
    }
}
