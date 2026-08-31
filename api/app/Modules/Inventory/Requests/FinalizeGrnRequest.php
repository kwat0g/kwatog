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

    /**
     * `numeric` is the wrong rule for a quantity that BCMath then reads.
     * is_numeric('1e3') is true but bccomp('1e3','0',3) raises a ValueError
     * (PHP 8), which no controller arm catches — measured as HTTP 500 for
     * 1e3 / 1e17 / 1e20. It also let 1.9999 through to a numeric(15,3)
     * column, where it stored silently as 2.000. `decimal:0,3` rejects both
     * shapes; the max matches the column so an in-range-looking decimal
     * cannot overflow into a 22003.
     */
    public function rules(): array
    {
        return [
            'items'                              => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id'     => ['required', 'string'],
            'items.*.location_id'                => ['required', 'string'],
            'items.*.quantity_received'          => ['required', 'decimal:0,3', 'min:0.001', 'max:999999999999.999'],
            'items.*.received_uom_code'          => ['nullable', 'string', 'max:20'],
            'items.*.lot_number'                 => ['nullable', 'string', 'max:50'],
            'items.*.material_lot_number'        => ['nullable', 'string', 'max:50'],
            'items.*.supplier_lot_reference'     => ['nullable', 'string', 'max:100'],
            'items.*.expiry_date'                => ['nullable', 'date'],
            'items.*.moisture_percentage'       => ['nullable', 'decimal:0,3', 'min:0', 'max:100'],
            'items.*.coa_document_path'         => ['nullable', 'string', 'max:500'],
            'items.*.coa_verified'              => ['prohibited'],
            'items.*.remarks'                    => ['nullable', 'string', 'max:200'],
        ];
    }
}
