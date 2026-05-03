<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Http\FormRequest;

class CreateShipmentRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('supply_chain.shipments.manage') ?? false;
    }

    protected function hashIdFields(): array
    {
        return ['purchase_order_id' => PurchaseOrder::class];
    }

    public function rules(): array
    {
        return [
            'purchase_order_id' => ['required', 'integer', 'exists:purchase_orders,id'],
            'carrier'           => ['nullable', 'string', 'max:100'],
            'vessel'            => ['nullable', 'string', 'max:100'],
            'container_number'  => ['nullable', 'string', 'max:32'],
            'bl_number'         => ['nullable', 'string', 'max:32'],
            'etd'               => ['nullable', 'date'],
            'eta'               => ['nullable', 'date'],
            'notes'             => ['nullable', 'string', 'max:2000'],
        ];
    }
}
