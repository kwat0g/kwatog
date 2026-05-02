<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaterialIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inventory.issue.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'work_order_id'         => ['nullable', 'integer'],
            'issued_date'           => ['required', 'date'],
            'reference_text'        => ['nullable', 'string', 'max:200'],
            'remarks'               => ['nullable', 'string', 'max:1000'],
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.item_id'       => ['required'],
            'items.*.location_id'   => ['required'],
            'items.*.quantity_issued' => ['required', 'decimal:0,3', 'min:0.001'],
            'items.*.material_reservation_id' => ['nullable', 'integer'],
            'items.*.remarks'       => ['nullable', 'string', 'max:200'],
        ];
    }
}
