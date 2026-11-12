<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Support\CustomerInputSanitizer;
use App\Modules\CRM\Models\Product;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    use ResolvesHashIds {
        prepareForValidation as protected decodeHashIdsForValidation;
    }

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.customers.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:200', 'regex:'.CustomerInputSanitizer::CUSTOMER_NAME_PATTERN],
            'code' => ['prohibited'],
            'contact_person' => ['required', 'string', 'min:2', 'max:100', 'regex:'.CustomerInputSanitizer::CONTACT_NAME_PATTERN],
            'contact_person_role' => ['required', 'string', 'min:2', 'max:100', 'regex:'.CustomerInputSanitizer::ROLE_TITLE_PATTERN],
            'email' => ['nullable', 'required_without:phone', 'email', 'max:200'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:20', 'regex:'.CustomerInputSanitizer::PHONE_PATTERN],
            'address' => ['required', 'string', 'min:5', 'max:500', 'regex:'.CustomerInputSanitizer::ADDRESS_PATTERN],
            'delivery_address' => ['required', 'string', 'min:5', 'max:500', 'regex:'.CustomerInputSanitizer::ADDRESS_PATTERN],
            'delivery_latitude' => ['required', 'numeric', 'regex:/\A-?\d{1,3}(?:\.\d{1,7})?\z/', 'between:-90,90'],
            'delivery_longitude' => ['required', 'numeric', 'regex:/\A-?\d{1,3}(?:\.\d{1,7})?\z/', 'between:-180,180'],
            'delivery_contact_person' => ['nullable', 'string', 'min:2', 'max:100', 'regex:'.CustomerInputSanitizer::CONTACT_NAME_PATTERN],
            'delivery_contact_phone' => ['nullable', 'string', 'max:20', 'regex:'.CustomerInputSanitizer::PHONE_PATTERN],
            'allowed_payment_methods' => ['required', 'array', 'list', 'min:1'],
            'allowed_payment_methods.*' => ['required', 'string', 'distinct', 'in:cash,check,bank_transfer'],
            'shipping_payer' => ['nullable', 'string', 'in:customer,company'],
            'agreement_starts_on' => ['nullable', 'date_format:Y-m-d'],
            'agreement_ends_on' => ['nullable', 'date_format:Y-m-d'],
            'warranty_duration_days' => ['nullable', 'integer', 'between:0,3650'],
            'warranty_report_window_days' => ['nullable', 'integer', 'between:0,3650'],
            'warranty_start_basis' => ['nullable', 'string', 'in:delivery,invoice'],
            'warranty_covered_issues' => ['nullable', 'array', 'list'],
            'warranty_covered_issues.*' => ['required', 'string', 'distinct', 'in:manufacturing_defect,shipping_damage,customer_caused_damage'],
            'warranty_remedies' => ['nullable', 'array', 'list'],
            'warranty_remedies.*' => ['required', 'string', 'in:repair,replace,credit,refund'],
            'tin' => ['nullable', 'string', 'min:4', 'max:20', 'regex:'.CustomerInputSanitizer::TAX_ID_PATTERN],
            'credit_limit' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'portal_catalog_mode' => ['sometimes', 'string', Rule::in(['standard', 'restricted'])],
            'portal_product_ids' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'portal_product_ids.*' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'product_order_limits' => ['sometimes', 'array', 'list', 'max:500'],
            'product_order_limits.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'product_order_limits.*.minimum_order_quantity' => ['required', 'integer', 'between:1,999999999'],
            'product_order_limits.*.maximum_order_quantity' => ['nullable', 'integer', 'between:1,999999999'],
            'product_warranties' => ['required', 'array', 'list', 'min:1', 'max:1000'],
            'product_warranties.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'product_warranties.*.label' => ['required', 'string', 'min:2', 'max:120', 'regex:'.CustomerInputSanitizer::WARRANTY_LABEL_PATTERN],
            'product_warranties.*.warranty_duration_days' => ['nullable', 'integer', 'between:0,3650'],
            'product_warranties.*.warranty_report_window_days' => ['nullable', 'integer', 'between:0,3650'],
            'product_warranties.*.warranty_start_basis' => ['nullable', 'string', 'in:delivery,invoice'],
            'product_warranties.*.warranty_covered_issues' => ['nullable', 'array', 'list'],
            'product_warranties.*.warranty_covered_issues.*' => ['required', 'string', 'in:manufacturing_defect,shipping_damage,customer_caused_damage'],
            'product_warranties.*.warranty_remedies' => ['nullable', 'array', 'list'],
            // Laravel's nested wildcard distinct rule compares values across
            // different product warranties too. The after-validator below
            // already rejects repeats within each individual choice.
            'product_warranties.*.warranty_remedies.*' => ['required', 'string', 'in:repair,replace,credit,refund'],
            'product_warranties.*.additional_terms' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function hashIdFields(): array
    {
        return [
            'portal_product_ids.*' => Product::class,
            'product_order_limits.*.product_id' => Product::class,
            'product_warranties.*.product_id' => Product::class,
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->decodeHashIdsForValidation();
        $this->merge(CustomerInputSanitizer::sanitizePayload($this->all()));
        if (! $this->exists('portal_catalog_mode')) {
            $this->merge(['portal_catalog_mode' => 'restricted']);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $rawName = $this->input('name');
            $name = is_string($rawName) ? trim($rawName) : '';
            if ($name !== '' && ! $validator->errors()->has('name')) {
                if (CustomerInputSanitizer::isPlaceholder($name)) {
                    $validator->errors()->add('name', 'Enter the actual customer name, not a placeholder.');
                } elseif (Customer::query()
                    ->whereRaw(CustomerInputSanitizer::NORMALIZED_NAME_SQL.' = ?', [mb_strtolower($name, 'UTF-8')])
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

            $hasLatitude = $this->filled('delivery_latitude');
            $hasLongitude = $this->filled('delivery_longitude');
            if ($hasLatitude !== $hasLongitude) {
                $missingField = $hasLatitude ? 'delivery_longitude' : 'delivery_latitude';
                $validator->errors()->add($missingField, 'Set both delivery coordinates by placing a pin on the map.');
            }

            $startsOn = $this->input('agreement_starts_on');
            $endsOn = $this->input('agreement_ends_on');
            foreach (['agreement_starts_on' => $startsOn, 'agreement_ends_on' => $endsOn] as $field => $date) {
                if (is_string($date) && $date !== '' && ! CustomerInputSanitizer::isValidCalendarDate($date)) {
                    $validator->errors()->add($field, 'Enter a valid calendar date.');
                }
            }
            if (is_string($startsOn) && is_string($endsOn) && $startsOn !== '' && $endsOn !== '' && $endsOn < $startsOn) {
                $validator->errors()->add('agreement_ends_on', 'Agreement end date must be on or after its start date.');
            }

            $rawSelectedProducts = $this->input('portal_product_ids', []);
            $selectedProducts = is_array($rawSelectedProducts)
                ? array_map('intval', array_filter($rawSelectedProducts, 'is_numeric'))
                : [];
            $rawWarranties = $this->input('product_warranties', []);
            $warrantyProductIds = is_array($rawWarranties)
                ? array_values(array_unique(array_map(
                    static fn (array $warranty): int => (int) ($warranty['product_id'] ?? 0),
                    array_filter($rawWarranties, 'is_array'),
                )))
                : [];
            foreach ($selectedProducts as $productId) {
                if (! in_array($productId, $warrantyProductIds, true)) {
                    $validator->errors()->add('product_warranties', 'Add at least one warranty choice for every selected product.');
                    break;
                }
            }
            $rawOrderLimits = $this->input('product_order_limits', []);
            foreach (is_array($rawOrderLimits) ? $rawOrderLimits : [] as $index => $limit) {
                if (! is_array($limit) || ! isset($limit['product_id'])) {
                    continue;
                }
                $productId = (int) $limit['product_id'];
                if (! in_array($productId, $selectedProducts, true)) {
                    $validator->errors()->add("product_order_limits.{$index}.product_id", 'Choose this product in Product Ordering Setup before setting its quantity limits.');
                }
                if (isset($limit['minimum_order_quantity'], $limit['maximum_order_quantity'])
                    && is_numeric($limit['minimum_order_quantity']) && is_numeric($limit['maximum_order_quantity'])
                    && (int) $limit['maximum_order_quantity'] < (int) $limit['minimum_order_quantity']) {
                    $validator->errors()->add("product_order_limits.{$index}.maximum_order_quantity", 'Maximum order quantity must be at least the minimum.');
                }
            }
            $seenWarrantyLabels = [];
            foreach (is_array($rawWarranties) ? $rawWarranties : [] as $index => $warranty) {
                if (! is_array($warranty) || ! isset($warranty['product_id'])) {
                    continue;
                }

                $productId = (int) $warranty['product_id'];
                if (! in_array($productId, $selectedProducts, true)) {
                    $validator->errors()->add("product_warranties.{$index}.product_id", 'Choose this product in Product Ordering Setup before adding its warranty.');
                }

                if (isset($warranty['label']) && is_string($warranty['label'])) {
                    if (CustomerInputSanitizer::isPlaceholder($warranty['label'])) {
                        $validator->errors()->add("product_warranties.{$index}.label", 'Enter the actual warranty option name, not a placeholder.');
                    }
                    $labelKey = $productId.':'.mb_strtolower(trim($warranty['label']));
                    if (isset($seenWarrantyLabels[$labelKey])) {
                        $validator->errors()->add("product_warranties.{$index}.label", 'Each product must have a different warranty choice name.');
                    }
                    $seenWarrantyLabels[$labelKey] = true;
                }

                $terms = $warranty['additional_terms'] ?? null;
                if (is_string($terms)) {
                    if (preg_match('/<\/?[a-z][^>]*>/i', $terms) === 1) {
                        $validator->errors()->add("product_warranties.{$index}.additional_terms", 'Enter warranty terms as plain text without HTML markup.');
                    } elseif (CustomerInputSanitizer::hasInvalidOtherCoveredIssue($terms)) {
                        $validator->errors()->add("product_warranties.{$index}.additional_terms", 'Describe the other covered issue using real plain text.');
                    }
                }

                foreach (['warranty_covered_issues', 'warranty_remedies'] as $field) {
                    $values = $warranty[$field] ?? null;
                    if (! is_array($values)) {
                        continue;
                    }

                    $seenValues = [];
                    foreach ($values as $valueIndex => $value) {
                        if (! is_string($value)) {
                            continue;
                        }

                        if (isset($seenValues[$value])) {
                            $validator->errors()->add(
                                "product_warranties.{$index}.{$field}.{$valueIndex}",
                                'Values must not repeat within one product warranty choice.',
                            );
                        }
                        $seenValues[$value] = true;
                    }
                }
            }
        });
    }
}
