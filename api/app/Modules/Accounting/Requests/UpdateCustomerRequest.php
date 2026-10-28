<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Customer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.customers.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name'              => ['sometimes', 'string', 'max:200'],
            // The code is issued once by CustomerService and is immutable.
            'code'              => ['prohibited'],
            'contact_person'    => ['sometimes', 'nullable', 'string', 'max:100'],
            'email'             => ['sometimes', 'nullable', 'email', 'max:200'],
            'phone'             => ['sometimes', 'nullable', 'string', 'max:20'],
            'address'           => ['sometimes', 'nullable', 'string', 'max:500'],
            'delivery_address'         => ['sometimes', 'nullable', 'string', 'max:500'],
            'delivery_contact_person'  => ['sometimes', 'nullable', 'string', 'max:100'],
            'delivery_contact_phone'   => ['sometimes', 'nullable', 'string', 'max:20'],
            'allowed_payment_methods' => ['sometimes', 'nullable', 'array', 'list'],
            'allowed_payment_methods.*' => ['required', 'string', 'distinct', 'in:cash,check,bank_transfer'],
            'shipping_payer' => ['sometimes', 'nullable', 'string', 'in:customer,company'],
            'agreement_starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'agreement_ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'warranty_duration_days' => ['sometimes', 'nullable', 'integer', 'between:0,3650'],
            'warranty_report_window_days' => ['sometimes', 'nullable', 'integer', 'between:0,3650'],
            'warranty_start_basis' => ['sometimes', 'nullable', 'string', 'in:delivery,invoice'],
            'warranty_covered_issues' => ['sometimes', 'nullable', 'array', 'list'],
            'warranty_covered_issues.*' => ['required', 'string', 'distinct', 'in:manufacturing_defect,shipping_damage,customer_caused_damage'],
            'warranty_remedies' => ['sometimes', 'nullable', 'array', 'list'],
            'warranty_remedies.*' => ['required', 'string', 'in:repair,replace,credit,refund'],
            'tin'               => ['sometimes', 'nullable', 'string', 'max:20'],
            'credit_limit'      => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'payment_terms_days'=> ['sometimes', 'integer', 'min:0', 'max:365'],
            'is_active'         => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $customer = $this->route('customer');
            $customer = $customer instanceof Customer ? $customer : null;
            $startsOn = $this->input('agreement_starts_on', $customer?->agreement_starts_on?->toDateString());
            $endsOn = $this->input('agreement_ends_on', $customer?->agreement_ends_on?->toDateString());
            if ($startsOn && $endsOn && $endsOn < $startsOn) {
                $validator->errors()->add('agreement_ends_on', 'Agreement end date must be on or after its start date.');
            }
        });
    }
}
