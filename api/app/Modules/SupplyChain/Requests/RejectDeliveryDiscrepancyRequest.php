<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectDeliveryDiscrepancyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('supply_chain.deliveries.confirm') ?? false;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:2000']];
    }
}
