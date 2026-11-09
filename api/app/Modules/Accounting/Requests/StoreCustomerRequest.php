<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'contact_person_role' => ['nullable', 'string', 'max:100'],
            'email'             => ['nullable', 'email', 'max:200'],
            'phone'             => ['nullable', 'string', 'max:20'],
            'address'           => ['nullable', 'string', 'max:500'],
            'delivery_address'         => ['nullable', 'string', 'max:500'],
            'delivery_latitude'        => ['nullable', 'numeric', 'between:-90,90', 'required_with:delivery_address'],
            'delivery_longitude'       => ['nullable', 'numeric', 'between:-180,180', 'required_with:delivery_address'],
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
            'portal_catalog_mode' => ['sometimes', 'string', Rule::in(['standard', 'restricted'])],
            'is_active'         => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('portal_catalog_mode')) {
            $this->merge(['portal_catalog_mode' => 'restricted']);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasLatitude = $this->filled('delivery_latitude');
            $hasLongitude = $this->filled('delivery_longitude');
            if ($hasLatitude !== $hasLongitude) {
                $missingField = $hasLatitude ? 'delivery_longitude' : 'delivery_latitude';
                $validator->errors()->add($missingField, 'Set both delivery coordinates by placing a pin on the map.');
            }

            $startsOn = $this->input('agreement_starts_on');
            $endsOn = $this->input('agreement_ends_on');
            if ($startsOn && $endsOn && $endsOn < $startsOn) {
                $validator->errors()->add('agreement_ends_on', 'Agreement end date must be on or after its start date.');
            }
        });
    }
}
