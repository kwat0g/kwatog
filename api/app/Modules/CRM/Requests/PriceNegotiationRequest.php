<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PriceNegotiationRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        if ($this->is('api/v1/b2b/customer/*')) {
            return $this->user('customer_portal') !== null;
        }
        if ($this->is('*/withdraw')) {
            return $this->user()?->hasPermission('crm.price_agreements.propose') || $this->user()?->hasPermission('crm.price_agreements.approve');
        }
        $permission = $this->is('*/approve') ? 'crm.price_agreements.approve' : 'crm.price_agreements.propose';

        return $this->user()?->hasPermission($permission) ?? false;
    }

    protected function hashIdFields(): array
    {
        return ['product_id' => Product::class, 'customer_id' => Customer::class];
    }

    public function rules(): array
    {
        if ($this->is('*/respond')) {
            return ['decision' => ['required', Rule::in(['accept', 'reject'])], 'version' => ['required', 'integer', 'min:1']];
        }
        if ($this->is('*/approve')) {
            return ['notes' => ['required', 'string', 'max:1000'], 'version' => ['required', 'integer', 'min:1'], 'cost_signature' => ['required', 'string', 'size:64']];
        }
        if ($this->is('*/withdraw')) {
            return ['notes' => ['required', 'string', 'max:1000']];
        }
        if ($this->is('api/v1/b2b/customer/*')) {
            return [
                'product_id' => ['required', 'integer', 'exists:products,id'], 'quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
                'requested_price' => ['nullable', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'], 'notes' => ['required', 'string', 'max:1000'],
            ];
        }
        $references = $this->route('priceRequest') ? 'sometimes' : 'required';

        return [
            'product_id' => [$references, 'integer', Rule::exists('products', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'customer_id' => [$references, 'integer', Rule::exists('customers', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'price' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'], 'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'effective_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:effective_from'], 'pricing_method' => ['required', Rule::in(['flat', 'tiered'])],
            'tiers' => ['nullable', 'array', 'max:20'], 'tiers.*.min_qty' => ['required', 'integer', 'min:1', 'distinct', 'max:2147483647'],
            'tiers.*.unit_price' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'], 'notes' => ['required', 'string', 'max:1000'],
        ];
    }
}
