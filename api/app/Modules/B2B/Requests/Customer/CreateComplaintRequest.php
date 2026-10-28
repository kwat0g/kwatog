<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Customer;

use App\Common\Support\HashIdFilter;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Quality\Enums\NcrSeverity;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\DeliveryItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var \App\Modules\B2B\Models\CustomerPortalUser $user */
        $user = $this->user('customer_portal');

        return [
            'order_id' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($user) {
                    $decoded = HashIdFilter::decode($value, SalesOrder::class);
                    $order   = $decoded ? SalesOrder::find($decoded) : null;
                    if (! $order || $order->customer_id !== $user?->customer_id) {
                        $fail('The order ID is invalid or does not belong to your account.');
                        return;
                    }

                    $status = $order->status instanceof \BackedEnum
                        ? $order->status->value
                        : (string) $order->status;
                    if ($status === SalesOrderStatus::Cancelled->value) {
                        $fail('The selected order is cancelled and cannot be linked to a complaint.');
                    }
                },
            ],
            // Product provenance is not part of the portal form contract.
            // Reject it explicitly rather than silently accepting and dropping
            // a field a caller may believe was persisted.
            'product_id'        => ['prohibited'],
            'delivery_item_id' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                    $id = HashIdFilter::decode($value, DeliveryItem::class);
                    $item = $id ? DeliveryItem::query()->with('delivery')->find($id) : null;
                    $delivery = $item?->delivery;
                    $status = $delivery?->status instanceof \BackedEnum
                        ? $delivery->status->value
                        : (string) $delivery?->status;
                    $belongsToCustomer = $delivery !== null && SalesOrder::query()
                        ->whereKey($delivery->sales_order_id)
                        ->where('customer_id', $user?->customer_id)
                        ->exists();

                    if (! $item || ! $delivery || ! $belongsToCustomer
                        || ! in_array($status, [DeliveryStatus::Delivered->value, DeliveryStatus::Confirmed->value], true)) {
                        $fail('Choose a delivered item from your own account.');
                        return;
                    }

                    $orderId = HashIdFilter::decode($this->input('order_id'), SalesOrder::class);
                    if ($orderId !== null && $orderId !== (int) $delivery->sales_order_id) {
                        $fail('The delivered item must belong to the selected order.');
                    }
                },
            ],
            'severity'          => ['required', Rule::enum(NcrSeverity::class)],
            'description'       => ['required', 'string', 'max:2000'],
            'affected_quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
