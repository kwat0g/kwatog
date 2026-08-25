<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Enums\InspectionParameterType;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertInspectionSpecRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('quality.specs.manage') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'product_id' => Product::class,
        ];
    }

    public function rules(): array
    {
        return [
            'product_id'                => ['required', 'integer', 'exists:products,id'],
            'notes'                     => ['nullable', 'string', 'max:2000'],
            'items'                     => ['required', 'array', 'min:1', 'max:100'],
            'items.*.parameter_name'    => ['required', 'string', 'max:150'],
            'items.*.parameter_type'    => ['required', Rule::enum(InspectionParameterType::class)],
            'items.*.unit_of_measure'   => ['nullable', 'string', 'max:20'],
            'items.*.nominal_value'     => ['nullable', $this->signedDecimalRule()],
            'items.*.tolerance_min'     => ['nullable', $this->signedDecimalRule()],
            'items.*.tolerance_max'     => ['nullable', $this->signedDecimalRule()],
            'items.*.is_critical'       => ['nullable', 'boolean'],
            'items.*.sort_order'        => ['nullable', 'integer', 'min:0', 'max:65535'],
            'items.*.notes'             => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $seenNames = [];

            foreach ((array) $this->input('items', []) as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $path = "items.{$index}";
                $name = mb_strtolower(trim((string) ($row['parameter_name'] ?? '')));
                if ($name !== '' && isset($seenNames[$name])) {
                    $validator->errors()->add("{$path}.parameter_name", 'Parameter names must be unique within a revision.');
                }
                if ($name !== '') {
                    $seenNames[$name] = true;
                }

                $type = (string) ($row['parameter_type'] ?? '');
                $nominal = $this->decimalScaled($row['nominal_value'] ?? null);
                $minimum = $this->decimalScaled($row['tolerance_min'] ?? null);
                $maximum = $this->decimalScaled($row['tolerance_max'] ?? null);
                $hasNumeric = $nominal !== null || $minimum !== null || $maximum !== null;

                if ($type === InspectionParameterType::Visual->value && $hasNumeric) {
                    $validator->errors()->add(
                        "{$path}.parameter_type",
                        'Visual parameters use manual pass/fail and cannot define numeric values or tolerances.',
                    );
                }

                if ($type === InspectionParameterType::Dimensional->value) {
                    if ($nominal === null) {
                        $validator->errors()->add("{$path}.nominal_value", 'Dimensional parameters require a nominal value.');
                    }
                    if ($minimum === null) {
                        $validator->errors()->add("{$path}.tolerance_min", 'Dimensional parameters require a minimum tolerance.');
                    }
                    if ($maximum === null) {
                        $validator->errors()->add("{$path}.tolerance_max", 'Dimensional parameters require a maximum tolerance.');
                    }
                }

                if ($type === InspectionParameterType::Functional->value && $nominal !== null && $minimum === null && $maximum === null) {
                    $validator->errors()->add("{$path}.nominal_value", 'A functional nominal needs at least one numeric tolerance bound.');
                }

                if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
                    $validator->errors()->add("{$path}.tolerance_min", 'Minimum tolerance must not exceed maximum tolerance.');
                    $validator->errors()->add("{$path}.tolerance_max", 'Maximum tolerance must not be below minimum tolerance.');
                }
                if ($nominal !== null && $minimum !== null && $nominal < $minimum) {
                    $validator->errors()->add("{$path}.nominal_value", 'Nominal value must be within the tolerance window.');
                }
                if ($nominal !== null && $maximum !== null && $nominal > $maximum) {
                    $validator->errors()->add("{$path}.nominal_value", 'Nominal value must be within the tolerance window.');
                }
            }
        });
    }

    private function signedDecimalRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
                $fail('The value must be a signed decimal with up to four places.');
                return;
            }

            $raw = trim((string) $value);
            if (! preg_match('/^-?\d{1,8}(?:\.\d{1,4})?$/', $raw)) {
                $fail('The value must be a signed decimal with up to four places.');
            }
        };
    }

    private function decimalScaled(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = trim((string) $value);
        if (! preg_match('/^-?\d{1,8}(?:\.\d{1,4})?$/', $raw)) {
            return null;
        }

        $negative = str_starts_with($raw, '-');
        $unsigned = ltrim($raw, '-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $scaled = ((int) $whole * 10000) + (int) str_pad($fraction, 4, '0');

        return $negative ? -$scaled : $scaled;
    }
}
