<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewCustomerPaymentTermsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('crm.sales_orders.update')
            || $this->user()?->hasPermission('crm.sales_orders.confirm')
            || false;
    }

    public function rules(): array
    {
        return [
            'payment_terms_days' => ['required', 'integer', 'between:0,365'],
        ];
    }
}
