<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\CRM\Models\Product;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerProductAccessRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.customers.manage') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'product_ids.*' => Product::class,
            'product_order_limits.*.product_id' => Product::class,
        ];
    }

    public function rules(): array
    {
        return [
            'portal_catalog_mode' => ['required', Rule::in(['standard', 'restricted'])],
            'product_ids' => ['present', 'array'],
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'product_order_limits' => ['sometimes', 'array', 'list', 'max:500'],
            'product_order_limits.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'product_order_limits.*.minimum_order_quantity' => ['required', 'integer', 'between:1,999999999'],
            'product_order_limits.*.maximum_order_quantity' => ['nullable', 'integer', 'between:1,999999999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $rawProducts = $this->input('product_ids', []);
            $productIds = is_array($rawProducts) ? array_map('intval', array_filter($rawProducts, 'is_numeric')) : [];
            $rawLimits = $this->input('product_order_limits', []);
            foreach (is_array($rawLimits) ? $rawLimits : [] as $index => $limit) {
                if (! is_array($limit) || ! isset($limit['product_id'])) {
                    continue;
                }
                $productId = (int) $limit['product_id'];
                if (! in_array($productId, $productIds, true)) {
                    $validator->errors()->add("product_order_limits.{$index}.product_id", 'Select the product before setting its order limits.');
                }
                if (isset($limit['minimum_order_quantity'], $limit['maximum_order_quantity'])
                    && is_numeric($limit['minimum_order_quantity']) && is_numeric($limit['maximum_order_quantity'])
                    && (int) $limit['maximum_order_quantity'] < (int) $limit['minimum_order_quantity']) {
                    $validator->errors()->add("product_order_limits.{$index}.maximum_order_quantity", 'Maximum order quantity must be at least the minimum.');
                }
            }
        });
    }
}
