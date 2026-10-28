<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.customers.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name'              => ['required', 'string', 'max:200'],
            'code'              => ['prohibited'],
            'contact_person'    => ['nullable', 'string', 'max:100'],
            'email'             => ['nullable', 'email', 'max:200'],
            'phone'             => ['nullable', 'string', 'max:20'],
            'address'           => ['nullable', 'string', 'max:500'],
            'delivery_address'         => ['nullable', 'string', 'max:500'],
            'delivery_contact_person'  => ['nullable', 'string', 'max:100'],
            'delivery_contact_phone'   => ['nullable', 'string', 'max:20'],
            'allowed_payment_methods' => ['nullable', 'array', 'list'],
            'allowed_payment_methods.*' => ['required', 'string', 'distinct', 'in:cash,check,bank_transfer'],
            'shipping_payer' => ['nullable', 'string', 'in:customer,company'],
            'agreement_starts_on' => ['nullable', 'date_format:Y-m-d'],
            'agreement_ends_on' => ['nullable', 'date_format:Y-m-d'],
            'warranty_duration_days' => ['required', 'integer', 'between:0,3650'],
            'warranty_report_window_days' => ['required', 'integer', 'between:0,3650'],
            'warranty_start_basis' => ['nullable', 'string', 'in:delivery,invoice'],
            'warranty_covered_issues' => ['nullable', 'array', 'list'],
            'warranty_covered_issues.*' => ['required', 'string', 'distinct', 'in:manufacturing_defect,shipping_damage,customer_caused_damage'],
            'warranty_remedies' => ['nullable', 'array', 'list'],
            'warranty_remedies.*' => ['required', 'string', 'in:repair,replace,credit,refund'],
            'tin'               => ['nullable', 'string', 'max:20'],
            'credit_limit'      => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'payment_terms_days'=> ['nullable', 'integer', 'min:0', 'max:365'],
            'is_active'         => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $startsOn = $this->input('agreement_starts_on');
            $endsOn = $this->input('agreement_ends_on');
            if ($startsOn && $endsOn && $endsOn < $startsOn) {
                $validator->errors()->add('agreement_ends_on', 'Agreement end date must be on or after its start date.');
            }
        });
    }
}
