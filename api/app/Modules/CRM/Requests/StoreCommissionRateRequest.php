<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use App\Common\Support\HashIdFilter;
use App\Modules\CRM\Models\Product;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

class StoreCommissionRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('crm.commissions.manage');
    }

    /**
     * The SPA sends hashed ids; the rules below are `integer|exists` and the
     * service hands the result straight to CommissionRate::create(). Decode
     * first, mapping an undecodable hash to 0 so `exists` 422s rather than the
     * string reaching a bigint column (Postgres 22P02).
     */
    protected function prepareForValidation(): void
    {
        foreach ([
            'employee_id' => Employee::class,
            'product_id'  => Product::class,
        ] as $field => $model) {
            $raw = $this->input($field);
            if ($raw === null || $raw === '') {
                continue;
            }
            $this->merge([$field => HashIdFilter::decode($raw, $model) ?? 0]);
        }
    }

    public function rules(): array
    {
        return [
            'employee_id'     => ['required', 'integer', 'exists:employees,id'],
            'product_id'      => ['nullable', 'integer', 'exists:products,id'],
            'rate'            => ['required', 'decimal:0,4', 'min:0', 'max:1'],
            'effective_from'  => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ];
    }
}
