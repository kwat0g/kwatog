<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FinalizeGrnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.grn.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'items'                              => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id'     => ['required', 'string'],
            'items.*.location_id'                => ['required', 'string'],
            'items.*.quantity_received'          => ['required', 'numeric', 'min:0.001'],
            'items.*.remarks'                    => ['nullable', 'string', 'max:200'],
        ];
    }
}
