<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Enums\WithholdingTaxType;
use App\Modules\Accounting\Models\Vendor;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.vendors.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name'              => [
                'required',
                'string',
                'max:200',
                function (string $attribute, mixed $value, Closure $fail) {
                    $exists = Vendor::whereNull('deleted_at')
                        ->whereRaw('LOWER(TRIM(name)) = LOWER(TRIM(?))', [$value])
                        ->exists();
                    if ($exists) {
                        $fail('A vendor with this name already exists.');
                    }
                },
            ],
            'contact_person'    => ['nullable', 'string', 'max:100'],
            'email'             => ['nullable', 'email', 'max:200'],
            'phone'             => ['nullable', 'string', 'max:20'],
            'address'           => ['nullable', 'string', 'max:500'],
            'tin'               => ['nullable', 'string', 'max:20', $this->tinHashRule()],
            'payment_terms_days'=> ['nullable', 'integer', 'min:0', 'max:365'],
            'withholding_tax_type' => ['sometimes', Rule::enum(WithholdingTaxType::class)],
            'is_active'         => ['nullable', 'boolean'],
        ];
    }

    private function tinHashRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if ($value === null || $value === '') {
                return; // TIN is optional
            }

            $hash = Vendor::tinHash($value);
            if ($hash === null) {
                return; // Hash normalization resulted in null (empty after digit extraction)
            }

            $exists = Vendor::whereNull('deleted_at')
                ->where('tin_hash', $hash)
                ->exists();

            if ($exists) {
                $fail('A vendor with this TIN already exists.');
            }
        };
    }
}
