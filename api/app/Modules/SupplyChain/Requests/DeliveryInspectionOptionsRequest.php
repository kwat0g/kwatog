<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\CRM\Models\SalesOrder;
use Illuminate\Foundation\Http\FormRequest;

class DeliveryInspectionOptionsRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('supply_chain.deliveries.create') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'sales_order_id' => SalesOrder::class,
        ];
    }

    public function rules(): array
    {
        return [
            'sales_order_id' => ['required', 'integer', 'exists:sales_orders,id'],
        ];
    }
}
