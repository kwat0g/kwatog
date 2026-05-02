<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSalesOrderRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('crm.sales_orders.update') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'customer_id'        => Customer::class,
            'items.*.product_id' => Product::class,
        ];
    }

    public function rules(): array
    {
        return [
            'customer_id'              => ['sometimes', 'required', 'integer', 'exists:customers,id'],
            'date'                     => ['sometimes', 'required', 'date'],
            'payment_terms_days'       => ['nullable', 'integer', 'min:0', 'max:365'],
            'delivery_terms'           => ['nullable', 'string', 'max:50'],
            'notes'                    => ['nullable', 'string', 'max:2000'],
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.product_id'       => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity'         => ['required', 'decimal:0,2', 'min:0.01'],
            'items.*.delivery_date'    => ['required', 'date'],
        ];
    }
}
