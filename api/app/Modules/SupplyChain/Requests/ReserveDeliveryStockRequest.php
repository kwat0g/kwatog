<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReserveDeliveryStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->hasPermission('supply_chain.deliveries.create') ?? false)
            || ($this->user()?->hasPermission('inventory.adjust') ?? false);
    }

    public function rules(): array
    {
        return ['request_key' => ['required', 'uuid']];
    }
}
