<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Support\CustomerInputSanitizer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.customers.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:200', 'regex:'.CustomerInputSanitizer::CUSTOMER_NAME_PATTERN],
            // The code is issued once by CustomerService and is immutable.
            'code' => ['prohibited'],
            'contact_person' => ['sometimes', 'nullable', 'string', 'min:2', 'max:100', 'regex:'.CustomerInputSanitizer::CONTACT_NAME_PATTERN],
            'contact_person_role' => ['sometimes', 'nullable', 'string', 'min:2', 'max:100', 'regex:'.CustomerInputSanitizer::ROLE_TITLE_PATTERN],
            'email' => ['sometimes', 'nullable', 'email', 'max:200'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:'.CustomerInputSanitizer::PHONE_PATTERN],
            'address' => ['sometimes', 'nullable', 'string', 'min:5', 'max:500', 'regex:'.CustomerInputSanitizer::ADDRESS_PATTERN],
            'delivery_address' => ['sometimes', 'nullable', 'string', 'min:5', 'max:500', 'regex:'.CustomerInputSanitizer::ADDRESS_PATTERN],
            'delivery_latitude' => ['sometimes', 'nullable', 'numeric', 'regex:/\A-?\d{1,3}(?:\.\d{1,7})?\z/', 'between:-90,90'],
            'delivery_longitude' => ['sometimes', 'nullable', 'numeric', 'regex:/\A-?\d{1,3}(?:\.\d{1,7})?\z/', 'between:-180,180'],
            'delivery_contact_person' => ['sometimes', 'nullable', 'string', 'min:2', 'max:100', 'regex:'.CustomerInputSanitizer::CONTACT_NAME_PATTERN],
            'delivery_contact_phone' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:'.CustomerInputSanitizer::PHONE_PATTERN],
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
            'tin' => ['sometimes', 'nullable', 'string', 'min:4', 'max:20', 'regex:'.CustomerInputSanitizer::TAX_ID_PATTERN],
            'credit_limit' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'payment_terms_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'portal_catalog_mode' => ['sometimes', 'string', Rule::in(['standard', 'restricted'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $customer = $this->route('customer');
            $customer = $customer instanceof Customer ? $customer : null;
            $name = $this->input('name');
            if (is_string($name) && $name !== '' && ! $validator->errors()->has('name')) {
                if (CustomerInputSanitizer::isPlaceholder($name)) {
                    $validator->errors()->add('name', 'Enter the actual customer name, not a placeholder.');
                } elseif ($customer !== null && Customer::query()
                    ->whereRaw(CustomerInputSanitizer::NORMALIZED_NAME_SQL.' = ?', [mb_strtolower($name, 'UTF-8')])
                    ->where('id', '<>', $customer->getKey())
                    ->exists()) {
                    $validator->errors()->add('name', 'A customer with this name already exists. Open the existing customer record or use a distinct name.');
                }
            }

            foreach (['contact_person', 'contact_person_role', 'delivery_contact_person', 'address', 'delivery_address'] as $field) {
                $value = $this->input($field);
                if (is_string($value) && $value !== '' && CustomerInputSanitizer::isPlaceholder($value)) {
                    $validator->errors()->add($field, 'Enter the actual information, not a placeholder.');
                }
            }

            foreach (['phone', 'delivery_contact_phone'] as $field) {
                $value = $this->input($field);
                if (is_string($value) && $value !== '' && ! CustomerInputSanitizer::isPlausiblePhone($value)) {
                    $validator->errors()->add($field, 'Enter a valid phone number with 7 to 15 digits.');
                }
            }
            $taxId = $this->input('tin');
            if (is_string($taxId) && $taxId !== '' && CustomerInputSanitizer::isPlaceholder($taxId)) {
                $validator->errors()->add('tin', 'Enter the actual tax identification number, not a placeholder.');
            }

            $latitudeSupplied = $this->exists('delivery_latitude');
            $longitudeSupplied = $this->exists('delivery_longitude');
            if ($latitudeSupplied !== $longitudeSupplied) {
                $missingField = $latitudeSupplied ? 'delivery_longitude' : 'delivery_latitude';
                $validator->errors()->add($missingField, 'Set both delivery coordinates by placing a pin on the map.');
            }

            $deliveryAddress = $this->input('delivery_address', $customer?->delivery_address);
            $savedDeliveryAddress = $customer?->delivery_address;
            $addressChanged = $this->exists('delivery_address') && is_string($deliveryAddress)
                && trim($deliveryAddress) !== trim(is_string($savedDeliveryAddress) ? $savedDeliveryAddress : '');
            if ($addressChanged && trim((string) $deliveryAddress) !== '') {
                foreach (['delivery_latitude', 'delivery_longitude'] as $coordinateField) {
                    if (! $this->filled($coordinateField)) {
                        $validator->errors()->add($coordinateField, 'Place a new pin for the changed delivery address.');
                    }
                }
            }

            $startsOn = $this->input('agreement_starts_on', $customer?->agreement_starts_on?->toDateString());
            $endsOn = $this->input('agreement_ends_on', $customer?->agreement_ends_on?->toDateString());
            foreach (['agreement_starts_on' => $startsOn, 'agreement_ends_on' => $endsOn] as $field => $date) {
                if (is_string($date) && $date !== '' && ! CustomerInputSanitizer::isValidCalendarDate($date)) {
                    $validator->errors()->add($field, 'Enter a valid calendar date.');
                }
            }
            if (is_string($startsOn) && is_string($endsOn) && $startsOn !== '' && $endsOn !== '' && $endsOn < $startsOn) {
                $validator->errors()->add('agreement_ends_on', 'Agreement end date must be on or after its start date.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge(CustomerInputSanitizer::sanitizePayload($this->all()));
    }
}
