<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Enums\WithholdingTaxType;
use App\Modules\Accounting\Models\Vendor;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.vendors.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name'              => [
                'sometimes',
                'string',
                'max:200',
                function (string $attribute, mixed $value, Closure $fail) {
                    $vendor = $this->route('vendor');
                    // Re-saving an unchanged name must not trip on a legacy
                    // duplicate that predates this guard.
                    if (mb_strtolower(trim((string) $value)) === mb_strtolower(trim((string) $vendor->name))) {
                        return;
                    }
                    $exists = Vendor::whereNull('deleted_at')
                        ->where('id', '!=', $vendor->id)
                        ->whereRaw('LOWER(TRIM(name)) = LOWER(TRIM(?))', [$value])
                        ->exists();
                    if ($exists) {
                        $fail('A vendor with this name already exists.');
                    }
                },
            ],
            'contact_person'    => ['sometimes', 'nullable', 'string', 'max:100'],
            'email'             => ['sometimes', 'nullable', 'email', 'max:200'],
            'phone'             => ['sometimes', 'nullable', 'string', 'max:20'],
            'address'           => ['sometimes', 'nullable', 'string', 'max:500'],
            'tin'               => ['sometimes', 'nullable', 'string', 'max:20', $this->tinHashRule()],
            'payment_terms_days'=> ['sometimes', 'integer', 'min:0', 'max:365'],
            'withholding_tax_type' => ['sometimes', Rule::enum(WithholdingTaxType::class)],
            'is_active'         => ['sometimes', 'boolean'],
        ];
    }

    private function tinHashRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if ($value === null || $value === '') {
                return; // TIN is optional
            }

            $vendor = $this->route('vendor');
            $hash = Vendor::tinHash($value);
            if ($hash === null) {
                return; // Hash normalization resulted in null (empty after digit extraction)
            }
            if ($hash === Vendor::tinHash($vendor->tin)) {
                return; // Unchanged TIN — see the name rule above.
            }

            $exists = Vendor::whereNull('deleted_at')
                ->where('id', '!=', $vendor->id)
                ->where('tin_hash', $hash)
                ->exists();

            if ($exists) {
                $fail('A vendor with this TIN already exists.');
            }
        };
    }
}
