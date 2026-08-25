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
use Illuminate\Validation\Rule;

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
            'vehicle_id'                  => [
                'nullable',
                'integer',
                Rule::exists('vehicles', 'id')->where(static fn ($query) => $query->where('status', 'available')),
            ],
            'driver_id'                   => [
                'nullable', 'integer', 'exists:users,id',
                // Only actual drivers may be assigned — assigning any user
                // effectively grants driver PWA access to customer PII.
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && ! User::query()->whereKey($value)
                        ->where('is_active', true)
                        ->whereHas('role', fn ($q) => $q->where('slug', 'driver'))
                        ->exists()) {
                        $fail('The selected driver is not an active driver.');
                    }
                },
            ],
            'scheduled_date'              => ['required', 'date'],
            'notes'                       => ['nullable', 'string', 'max:2000'],
            'items'                       => ['required', 'array', 'min:1'],
            'items.*.sales_order_item_id' => ['required', 'integer', 'exists:sales_order_items,id'],
            // Sales-order quantities and quantity_delivered are stored to two
            // decimal places; keep the delivery input at the same precision so
            // reconciliation cannot silently round a shipment line.
            'items.*.quantity'            => ['required', 'decimal:0,2', 'min:0.01'],
            // Every manual delivery line must choose the same passed, outgoing
            // inspection that DeliveryService validates against its production
            // provenance and remaining accepted quantity.
            'items.*.inspection_id'       => ['required', 'integer', 'exists:inspections,id'],
        ];
    }
}
