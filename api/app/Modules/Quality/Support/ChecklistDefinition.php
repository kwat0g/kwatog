<?php

declare(strict_types=1);

namespace App\Modules\Quality\Support;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Quality\Enums\InspectionParameterType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ChecklistDefinition
{
    public static function assertEnabled(): void
    {
        if (! config('inspection.checklist_capture_enabled')) {
            throw new BusinessRuleException('PASS/FAIL checklist publication is paused.');
        }
    }

    /** Build instructions only from the published specification, never from assumed measurements. */
    public static function criterion(array $row): string
    {
        $written = trim((string) ($row['acceptance_criterion'] ?? '')) ?: trim((string) ($row['notes'] ?? ''));
        if ($written !== '') {
            return $written;
        }
        if (($row['tolerance_min'] ?? null) !== null || ($row['tolerance_max'] ?? null) !== null) {
            return 'Verify compliance with the specification limits shown.';
        }

        return '';
    }

    public static function requireInstructions(array $items, string $key): void
    {
        if (count($items) < 1 || count($items) > 100) {
            throw ValidationException::withMessages([
                $key => 'Add between 1 and 100 inspection checks.',
            ]);
        }

        $seen = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    "$key.$index" => 'Each inspection check must be an object.',
                ]);
            }

            $name = preg_replace('/\s+/u', ' ', trim((string) ($item['parameter_name'] ?? '')));
            $normalized = mb_strtolower($name);
            if ($name === '' || isset($seen[$normalized])) {
                throw ValidationException::withMessages([
                    "$key.$index.parameter_name" => 'Check names must be nonblank and unique within a revision.',
                ]);
            }
            $seen[$normalized] = true;

            if (self::criterion($item) === '') {
                throw ValidationException::withMessages([
                    "$key.$index.acceptance_criterion" => 'Every inspection check needs an acceptance criterion or approved reference.',
                ]);
            }
        }
    }

    public static function validate(array $items, string $key = 'items', bool $specification = false): array
    {
        if ($specification) {
            $items = array_map(static fn ($row) => is_array($row)
                ? [...$row, 'acceptance_criterion' => self::criterion($row)] : $row, $items);
        }
        $referenceRules = $specification ? [
            "$key.*.unit_of_measure" => ['nullable', 'string', 'max:20'],
            "$key.*.nominal_value" => ['nullable', 'regex:/^-?\\d{1,8}(?:\\.\\d{1,4})?$/'],
            "$key.*.tolerance_min" => ['nullable', 'regex:/^-?\\d{1,8}(?:\\.\\d{1,4})?$/'],
            "$key.*.tolerance_max" => ['nullable', 'regex:/^-?\\d{1,8}(?:\\.\\d{1,4})?$/'],
            "$key.*.notes" => ['nullable', 'string', 'max:500'],
        ] : [];
        $data = Validator::make([$key => $items], [
            ...$referenceRules,
            $key => ['required', 'array', 'min:1', 'max:100'],
            "$key.*.parameter_name" => ['required', 'string', 'max:150'],
            "$key.*.acceptance_criterion" => ['required', 'string', 'max:500'],
            "$key.*.parameter_type" => ['nullable', Rule::enum(InspectionParameterType::class)],
            "$key.*.is_critical" => ['sometimes', 'boolean'],
            "$key.*.sort_order" => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ])->validate();
        $seen = [];
        $checks = [];
        foreach ($data[$key] as $i => $row) {
            $name = preg_replace('/\s+/u', ' ', trim($row['parameter_name']));
            $criterion = trim($row['acceptance_criterion']);
            $normalized = mb_strtolower($name);
            if ($name === '' || isset($seen[$normalized])) {
                throw ValidationException::withMessages(["$key.$i.parameter_name" => 'Check names must be nonblank and unique within a revision.']);
            }
            if ($criterion === '') {
                throw ValidationException::withMessages(["$key.$i.acceptance_criterion" => 'Enter the acceptance criterion or approved reference.']);
            }
            foreach ($specification ? ['measured_value'] : ['nominal_value', 'tolerance_min', 'tolerance_max', 'measured_value', 'unit_of_measure'] as $field) {
                if (array_key_exists($field, $items[$i])) {
                    throw ValidationException::withMessages(["$key.$i.$field" => 'PASS/FAIL checklists do not accept measurement fields.']);
                }
            }
            if ($specification) {
                $nominal = $row['nominal_value'] ?? null;
                $minimum = $row['tolerance_min'] ?? null;
                $maximum = $row['tolerance_max'] ?? null;
                $hasNumeric = $nominal !== null || $minimum !== null || $maximum !== null;
                if (($row['parameter_type'] ?? null) === 'visual' && $hasNumeric) {
                    throw ValidationException::withMessages(["$key.$i.parameter_type" => 'Visual checks cannot define numeric limits.']);
                }
                if (($row['parameter_type'] ?? null) === 'dimensional') {
                    $missing = [];
                    foreach (['unit_of_measure' => 'Choose a measurement unit.', 'nominal_value' => 'Enter the measurement target.',
                        'tolerance_min' => 'Enter the lowest allowed value.', 'tolerance_max' => 'Enter the highest allowed value.'] as $field => $message) {
                        if (trim((string) ($row[$field] ?? '')) === '') {
                            $missing["$key.$i.$field"] = $message;
                        }
                    }
                    if ($missing !== []) {
                        throw ValidationException::withMessages($missing);
                    }
                }
                if ($nominal !== null && $minimum === null && $maximum === null) {
                    throw ValidationException::withMessages(["$key.$i.nominal_value" => 'A nominal value needs an acceptance limit.']);
                }
                if ($minimum !== null && $maximum !== null && bccomp((string) $minimum, (string) $maximum, 4) > 0) {
                    throw ValidationException::withMessages(["$key.$i.tolerance_min" => 'Minimum must not exceed maximum.']);
                }
                if ($nominal !== null && (($minimum !== null && bccomp((string) $nominal, (string) $minimum, 4) < 0)
                    || ($maximum !== null && bccomp((string) $nominal, (string) $maximum, 4) > 0))) {
                    throw ValidationException::withMessages(["$key.$i.nominal_value" => 'Nominal must be within the acceptance limits.']);
                }
            }
            $seen[$normalized] = true;
            $checks[] = [...$row, 'parameter_name' => $name, 'acceptance_criterion' => $criterion,
                'parameter_type' => $row['parameter_type'] ?? null, 'is_critical' => (bool) ($row['is_critical'] ?? false),
                'nominal_value' => $row['nominal_value'] ?? null, 'tolerance_min' => $row['tolerance_min'] ?? null,
                'tolerance_max' => $row['tolerance_max'] ?? null, 'unit_of_measure' => $row['unit_of_measure'] ?? null];
        }

        return $checks;
    }
}
