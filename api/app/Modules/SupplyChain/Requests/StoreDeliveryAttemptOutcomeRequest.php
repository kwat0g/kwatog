<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Requests;

use App\Modules\SupplyChain\Enums\DeliveryAttemptReason;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeliveryAttemptOutcomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $delivery = $this->route('delivery');
        if (! $user || ! $delivery instanceof Delivery) {
            return false;
        }

        // Ownership is rechecked on the locked delivery inside the service.
        // Let a wrong driver reach that boundary so the private route keeps
        // the same 404 existence-hiding behavior as driver detail/status APIs.
        return $this->is('api/v1/driver/*')
            ? true
            : $user->hasPermission('supply_chain.deliveries.create');
    }

    public function rules(): array
    {
        $quantity = ['required', 'decimal:0,3', 'min:0', 'max:999999999.999'];

        return [
            'request_key' => ['required', 'uuid'],
            'reason_code' => ['required', Rule::enum(DeliveryAttemptReason::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.delivery_item_id' => ['required', 'string', 'max:100', 'distinct'],
            'lines.*.customer_received_quantity' => $quantity,
            'lines.*.customer_received_damaged_quantity' => ['required', 'decimal:0,3', 'min:0', 'max:999999999.999'],
            'lines.*.truck_return_quantity' => $quantity,
            'lines.*.truck_return_damaged_quantity' => ['required', 'decimal:0,3', 'min:0', 'max:999999999.999'],
            'lines.*.unaccounted_quantity' => $quantity,
        ];
    }
}
