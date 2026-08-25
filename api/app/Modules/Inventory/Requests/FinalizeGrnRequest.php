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
            'items.*.received_uom_code'          => ['nullable', 'string', 'max:20'],
            'items.*.lot_number'                 => ['nullable', 'string', 'max:50'],
            'items.*.material_lot_number'        => ['nullable', 'string', 'max:50'],
            'items.*.supplier_lot_reference'     => ['nullable', 'string', 'max:100'],
            'items.*.expiry_date'                => ['nullable', 'date'],
            'items.*.moisture_percentage'       => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.coa_document_path'         => ['nullable', 'string', 'max:500'],
            'items.*.coa_verified'              => ['prohibited'],
            'items.*.remarks'                    => ['nullable', 'string', 'max:200'],
        ];
    }
}
