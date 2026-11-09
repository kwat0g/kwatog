<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\CRM\Models\Product;
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
        return ['product_ids.*' => Product::class];
    }

    public function rules(): array
    {
        return [
            'portal_catalog_mode' => ['required', Rule::in(['standard', 'restricted'])],
            'product_ids' => ['present', 'array'],
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->where('is_active', true)->whereNull('deleted_at')],
        ];
    }
}
