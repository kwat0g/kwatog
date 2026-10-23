<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Accounting\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductCustomerAccessRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('crm.products.customer_access.manage') ?? false;
    }

    protected function hashIdFields(): array
    {
        return ['portal_customer_ids.*' => Customer::class];
    }

    public function rules(): array
    {
        return [
            'portal_visibility' => ['required', Rule::in(['standard', 'restricted'])],
            'portal_customer_ids' => ['required', 'array'],
            'portal_customer_ids.*' => ['integer', 'distinct', Rule::exists('customers', 'id')->where('is_active', true)->whereNull('deleted_at')],
        ];
    }
}
