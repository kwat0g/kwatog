<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class ProcessYearEndLeaveRequest extends FormRequest
{
    public const MIN_SUPPORTED_YEAR = 2020;

    public const MAX_SUPPORTED_YEAR = 2099;

    public function authorize(): bool
    {
        return $this->user()?->can('leave.types.manage') ?? false;
    }

    public static function normalizeYear(mixed $year): ?int
    {
        $normalized = filter_var($year, FILTER_VALIDATE_INT);

        if (! is_int($normalized)
            || $normalized < self::MIN_SUPPORTED_YEAR
            || $normalized > self::MAX_SUPPORTED_YEAR) {
            return null;
        }

        return $normalized;
    }

    public static function isSupportedYear(mixed $year): bool
    {
        return self::normalizeYear($year) !== null;
    }

    public static function yearValidationMessage(): string
    {
        return sprintf(
            'Year must be an integer from %d through %d.',
            self::MIN_SUPPORTED_YEAR,
            self::MAX_SUPPORTED_YEAR,
        );
    }

    /** @return array<int, mixed> */
    public static function yearRules(): array
    {
        return [
            'sometimes',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (! self::isSupportedYear($value)) {
                    $fail(self::yearValidationMessage());
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('year')) {
            $this->merge(['year' => now()->year]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'year' => self::yearRules(),
        ];
    }
}
