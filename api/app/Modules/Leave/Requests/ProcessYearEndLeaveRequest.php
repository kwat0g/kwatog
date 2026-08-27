<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use JsonException;

class ProcessYearEndLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('leave.types.manage') ?? false;
    }

    public static function minSupportedYear(): int
    {
        return self::validationContract()['minimum_year'];
    }

    public static function maxSupportedYear(): int
    {
        return self::validationContract()['maximum_year'];
    }

    public static function canonicalYearPattern(): string
    {
        return self::validationContract()['string_pattern'];
    }

    public static function normalizeYear(mixed $year): ?int
    {
        if (! is_int($year) && ! is_string($year)) {
            return null;
        }

        $contract = self::validationContract();
        $yearString = (string) $year;

        // The D modifier makes the contract's `$` anchor absolute in PCRE,
        // matching JavaScript's whole-value check rather than accepting a
        // trailing newline.
        if (preg_match('~'.$contract['string_pattern'].'~D', $yearString) !== 1) {
            return null;
        }

        $normalized = (int) $yearString;

        if (! is_int($normalized)
            || $normalized < $contract['minimum_year']
            || $normalized > $contract['maximum_year']) {
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
            self::minSupportedYear(),
            self::maxSupportedYear(),
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
        // SanitizeInput trims all request strings before FormRequest hooks run.
        // Restore the raw JSON value so whitespace and decimal-looking input
        // cannot become a different, valid year before this contract runs.
        $rawPayload = json_decode($this->getContent(), true);
        if (is_array($rawPayload) && array_key_exists('year', $rawPayload)) {
            $this->merge(['year' => $rawPayload['year']]);

            return;
        }

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

    /** @return array{minimum_year: int, maximum_year: int, string_pattern: string} */
    private static function validationContract(): array
    {
        static $contract;

        if ($contract !== null) {
            return $contract;
        }

        $path = resource_path('contracts/leave-year-end-validation.json');
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \LogicException('The leave year-end validation contract is missing.');
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \LogicException('The leave year-end validation contract is invalid.', 0, $exception);
        }

        $minimumYear = is_array($decoded) ? ($decoded['minimum_year'] ?? null) : null;
        $maximumYear = is_array($decoded) ? ($decoded['maximum_year'] ?? null) : null;
        $canonicalInput = is_array($decoded) ? ($decoded['canonical_input'] ?? null) : null;
        $stringPattern = is_array($canonicalInput) ? ($canonicalInput['string_pattern'] ?? null) : null;

        if (! is_int($minimumYear)
            || ! is_int($maximumYear)
            || $minimumYear > $maximumYear
            || ! is_string($stringPattern)
            || $stringPattern === '') {
            throw new \LogicException('The leave year-end validation contract is malformed.');
        }

        $contract = [
            'minimum_year' => $minimumYear,
            'maximum_year' => $maximumYear,
            'string_pattern' => $stringPattern,
        ];

        return $contract;
    }
}
