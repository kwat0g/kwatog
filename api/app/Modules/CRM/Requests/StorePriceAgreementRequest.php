<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class StorePriceAgreementRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('crm.price_agreements.manage') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'product_id'  => Product::class,
            'customer_id' => Customer::class,
        ];
    }

    public function rules(): array
    {
        return [
            'product_id'     => ['required', 'integer', 'exists:products,id'],
            'customer_id'    => ['required', 'integer', 'exists:customers,id'],
            'price'          => ['required', 'decimal:0,2', 'min:0'],
            'effective_from' => ['required', 'date'],
            'effective_to'   => ['required', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
