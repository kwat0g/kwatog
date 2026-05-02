<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.adjust') ?? false;
    }

    public function rules(): array
    {
        return [
            'item_id'     => ['required'],
            'location_id' => ['required'],
            'direction'   => ['required', Rule::in(['in', 'out'])],
            'quantity'    => ['required', 'decimal:0,3', 'min:0.001'],
            'unit_cost'   => ['nullable', 'decimal:0,4', 'min:0'],
            'reason'      => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
