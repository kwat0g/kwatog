<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Supplier;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RespondToPurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is behind auth:supplier_portal + B2BTenancyScopeMiddleware;
        // ownership of the specific PO is re-checked by the service under lock.
        return true;
    }

    public function rules(): array
    {
        return [
            'type'                        => ['required', Rule::in(['accept', 'propose', 'decline'])],
            'proposed_delivery_date'      => ['nullable', 'date'],
            'notes'                       => ['nullable', 'string', 'max:2000'],
            // A counter-offer must name at least one line to be meaningful.
            'items'                       => ['required_if:type,propose', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'string'],
            'items.*.proposed_quantity'   => ['nullable', 'numeric', 'min:0.01'],
            'items.*.proposed_unit_price' => ['nullable', 'numeric', 'min:0.01'],
            'items.*.reason'              => ['nullable', 'string', 'max:500'],
        ];
    }
}
