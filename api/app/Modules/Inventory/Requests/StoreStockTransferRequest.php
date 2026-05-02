<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.adjust') ?? false;
    }

    public function rules(): array
    {
        return [
            'item_id'          => ['required'],
            'from_location_id' => ['required', 'different:to_location_id'],
            'to_location_id'   => ['required'],
            'quantity'         => ['required', 'decimal:0,3', 'min:0.001'],
            'remarks'          => ['nullable', 'string', 'max:500'],
        ];
    }
}
