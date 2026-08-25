<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Quality\Enums\NcrSeverity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreComplaintRequest extends FormRequest
{
    use ResolvesHashIds {
        prepareForValidation as private decodeComplaintHashIds;
    }

    /** @var array<string, mixed> */
    private array $invalidHashIds = [];

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('crm.complaints.manage') ?? false;
    }

    /** @return array<string, class-string> */
    protected function hashIdFields(): array
    {
        return [
            'customer_id' => Customer::class,
            'product_id' => Product::class,
            'sales_order_id' => SalesOrder::class,
            'assigned_to' => User::class,
        ];
    }

    protected function prepareForValidation(): void
    {
        $raw = [];
        foreach (array_keys($this->hashIdFields()) as $field) {
            $value = $this->input($field);
            if ($value !== null && $value !== '') {
                $raw[$field] = $value;
            }
        }

        $this->decodeComplaintHashIds();

        foreach ($raw as $field => $_value) {
            if ($this->input($field) === null) {
                $this->invalidHashIds[$field] = true;
            }
        }
    }

    public function rules(): array
    {
        return [
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where(
                    fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'),
                ),
            ],
            'product_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where(
                    fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'),
                ),
            ],
            'sales_order_id' => [
                'nullable',
                'integer',
                Rule::exists('sales_orders', 'id')->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'received_date' => ['required', 'date'],
            'severity' => ['required', Rule::enum(NcrSeverity::class)],
            'description' => ['required', 'string', 'max:5000'],
            'affected_quantity' => ['nullable', 'integer', 'min:0'],
            'assigned_to' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'),
                ),
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach (array_keys($this->invalidHashIds) as $field) {
                $validator->errors()->add($field, 'The selected ID is invalid.');
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $orderId = $this->input('sales_order_id');
            if (! $orderId) {
                return;
            }

            $order = SalesOrder::query()
                ->with('items:id,sales_order_id,product_id')
                ->find((int) $orderId);
            if (! $order) {
                $validator->errors()->add('sales_order_id', 'The selected sales order is inactive or no longer exists.');
                return;
            }

            $orderStatus = $order->status instanceof \BackedEnum
                ? $order->status->value
                : (string) $order->status;
            if ($orderStatus === 'cancelled') {
                $validator->errors()->add('sales_order_id', 'The selected sales order is inactive or no longer exists.');
                return;
            }

            if ((int) $order->customer_id !== (int) $this->input('customer_id')) {
                $validator->errors()->add('sales_order_id', 'The selected sales order does not belong to the selected customer.');
            }

            $productId = $this->input('product_id');
            if ($productId && ! $order->items->contains('product_id', (int) $productId)) {
                $validator->errors()->add('product_id', 'The selected product is not part of the selected sales order.');
            }
        });
    }
}
