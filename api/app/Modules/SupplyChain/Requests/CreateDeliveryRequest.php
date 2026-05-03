<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Quality\Models\Inspection;
use App\Modules\SupplyChain\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;

class CreateDeliveryRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('supply_chain.deliveries.create') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'sales_order_id'           => SalesOrder::class,
            'vehicle_id'               => Vehicle::class,
            'driver_id'                => User::class,
            'items.*.sales_order_item_id' => SalesOrderItem::class,
            'items.*.inspection_id'    => Inspection::class,
        ];
    }

    public function rules(): array
    {
        return [
            'sales_order_id'              => ['required', 'integer', 'exists:sales_orders,id'],
            'vehicle_id'                  => ['nullable', 'integer', 'exists:vehicles,id'],
            'driver_id'                   => ['nullable', 'integer', 'exists:users,id'],
            'scheduled_date'              => ['required', 'date'],
            'notes'                       => ['nullable', 'string', 'max:2000'],
            'items'                       => ['required', 'array', 'min:1'],
            'items.*.sales_order_item_id' => ['required', 'integer', 'exists:sales_order_items,id'],
            'items.*.quantity'            => ['required', 'numeric', 'gt:0'],
            'items.*.inspection_id'       => ['nullable', 'integer', 'exists:inspections,id'],
        ];
    }
}
