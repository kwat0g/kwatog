<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Payroll\Enums\ContributionAgency;
use App\Modules\Payroll\Models\GovernmentContributionTable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GovernmentContributionTableImportService
{
    private const REQUIRED_HEADER = ['bracket_min', 'bracket_max', 'ee_amount', 'er_amount', 'effective_date'];

    /**
     * Validate the complete file before changing a single row. A statutory
     * schedule is one versioned set: partially importing it leaves payroll
     * with a mixture of old and new brackets that can produce wrong statutory
     * deductions while still looking active in the admin screen.
     *
     * @return array{
     *   total: int, imported: int, updated: int, skipped: int,
     *   deactivated_prior: int,
     *   errors: array<int, array{row:int, message:string}>
     * }
     */
    public function importFromPath(
        ContributionAgency $agency,
        string $path,
        bool $deactivatePrior = true,
    ): array {
        if (! is_readable($path)) {
            throw new BusinessRuleException("CSV not readable at {$path}.");
        }

        $stream = fopen($path, 'r');
        if ($stream === false) {
            throw new RuntimeException("Could not open {$path}.");
        }

        try {
            $header = fgetcsv($stream);
            if (! $header) {
                return $this->blankResult(['row' => 0, 'message' => 'Empty CSV.']);
            }

            $header = array_map(static fn ($value): string => strtolower(trim((string) $value)), $header);
            $missing = array_diff(self::REQUIRED_HEADER, $header);
            if ($missing) {
                return $this->blankResult([
                    'row' => 1,
                    'message' => 'Missing column(s): '.implode(', ', $missing),
                ]);
            }
            $duplicates = array_keys(array_filter(array_count_values($header), static fn (int $count): bool => $count > 1));
            if ($duplicates !== []) {
                return $this->blankResult([
                    'row' => 1,
                    'message' => 'Duplicate column(s): '.implode(', ', $duplicates),
                ]);
            }
            $idx = array_flip($header);

            $rows = [];
            $errors = [];
            $rowNum = 1;
            $total = 0;

            while (($row = fgetcsv($stream)) !== false) {
                $rowNum++;
                if (count(array_filter($row, static fn ($value): bool => $value !== null && trim((string) $value) !== '')) === 0) {
                    continue;
                }
                $total++;

                try {
                    $rows[] = [
                        'row' => $rowNum,
                        'payload' => $this->normalizeRow($row, $idx),
                    ];
                } catch (\Throwable $e) {
                    $errors[] = ['row' => $rowNum, 'message' => $e->getMessage()];
                }
            }

            if ($total === 0) {
                return $this->blankResult(['row' => 1, 'message' => 'CSV contains no data rows.']);
            }

            $this->validateSchedule($rows, $agency, $errors);

            // The import is atomic from the operator's point of view. Every
            // row is reported as skipped when validation fails because none of
            // the file was applied, including otherwise valid rows.
            if ($errors !== []) {
                return [
                    'total'             => $total,
                    'imported'          => 0,
                    'updated'           => 0,
                    'skipped'           => $total,
                    'deactivated_prior' => 0,
                    'errors'            => $errors,
                ];
            }

            $maxEffective = null;
            foreach ($rows as $entry) {
                $date = $entry['payload']['effective_date'];
                if ($maxEffective === null || strcmp($date, $maxEffective) > 0) {
                    $maxEffective = $date;
                }
            }

            $result = DB::transaction(function () use (
                $agency,
                $rows,
                $deactivatePrior,
                $maxEffective,
            ): array {
                $imported = 0;
                $updated = 0;

                foreach ($rows as $entry) {
                    $payload = $entry['payload'];
                    $existing = GovernmentContributionTable::query()
                        ->where('agency', $agency->value)
                        ->where('bracket_min', $payload['bracket_min'])
                        ->where('bracket_max', $payload['bracket_max'])
                        ->where('effective_date', $payload['effective_date'])
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        $existing->update([
                            'ee_amount' => $payload['ee_amount'],
                            'er_amount' => $payload['er_amount'],
                            'is_active' => true,
                        ]);
                        $updated++;
                    } else {
                        GovernmentContributionTable::create([
                            ...$payload,
                            'agency' => $agency->value,
                            'is_active' => true,
                        ]);
                        $imported++;
                    }
                }

                $deactivatedPrior = 0;
                if ($deactivatePrior && $maxEffective !== null) {
                    $deactivatedPrior = GovernmentContributionTable::query()
                        ->where('agency', $agency->value)
                        ->where('effective_date', '<', $maxEffective)
                        ->where('is_active', true)
                        ->update(['is_active' => false]);
                }

                return [
                    'imported' => $imported,
                    'updated' => $updated,
                    'deactivated_prior' => $deactivatedPrior,
                ];
            });

            $this->bustCache($agency);

            return [
                'total'             => $total,
                'imported'          => $result['imported'],
                'updated'           => $result['updated'],
                'skipped'           => 0,
                'deactivated_prior' => $result['deactivated_prior'],
                'errors'            => [],
            ];
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param array<int, mixed> $row
     * @param array<string, int> $idx
     * @return array{bracket_min:string, bracket_max:string, ee_amount:string, er_amount:string, effective_date:string}
     */
    private function normalizeRow(array $row, array $idx): array
    {
        foreach (self::REQUIRED_HEADER as $field) {
            if (! array_key_exists($idx[$field], $row)) {
                throw new BusinessRuleException("Missing value for {$field}.");
            }
        }

        $bracketMin = $this->decimal($row[$idx['bracket_min']], 2, 'bracket_min');
        $bracketMax = $this->decimal($row[$idx['bracket_max']], 2, 'bracket_max');
        $eeAmount = $this->decimal($row[$idx['ee_amount']], 4, 'ee_amount');
        $erAmount = $this->decimal($row[$idx['er_amount']], 4, 'er_amount');

        if (bccomp($bracketMax, $bracketMin, 2) < 0) {
            throw new BusinessRuleException('bracket_max < bracket_min');
        }

        $effectiveDate = trim((string) $row[$idx['effective_date']]);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
            throw new BusinessRuleException('effective_date must use YYYY-MM-DD.');
        }
        try {
            $parsedDate = Carbon::createFromFormat('!Y-m-d', $effectiveDate);
        } catch (\Throwable) {
            throw new BusinessRuleException('effective_date is not a valid calendar date.');
        }
        if (! $parsedDate || $parsedDate->format('Y-m-d') !== $effectiveDate) {
            throw new BusinessRuleException('effective_date is not a valid calendar date.');
        }

        return [
            'bracket_min' => $bracketMin,
            'bracket_max' => $bracketMax,
            'ee_amount' => $eeAmount,
            'er_amount' => $erAmount,
            'effective_date' => $effectiveDate,
        ];
    }

    /**
     * @param array<int, array{row:int, payload:array{bracket_min:string, bracket_max:string, ee_amount:string, er_amount:string, effective_date:string}}> $rows
     * @param array<int, array{row:int, message:string}> $errors
     */
    private function validateSchedule(array $rows, ContributionAgency $agency, array &$errors): void
    {
        $seen = [];
        $byDate = [];

        foreach ($rows as $entry) {
            $payload = $entry['payload'];
            $identity = implode('|', [
                $agency->value,
                $payload['effective_date'],
                $payload['bracket_min'],
                $payload['bracket_max'],
            ]);
            if (isset($seen[$identity])) {
                $errors[] = [
                    'row' => $entry['row'],
                    'message' => 'Duplicate bracket/effective-date row; the file was not imported.',
                ];
            }
            $seen[$identity] = true;
            $byDate[$payload['effective_date']][] = $entry;
        }

        foreach ($byDate as $dateRows) {
            usort($dateRows, static function (array $left, array $right): int {
                return bccomp(
                    $left['payload']['bracket_min'],
                    $right['payload']['bracket_min'],
                    2,
                );
            });

            $previous = null;
            foreach ($dateRows as $entry) {
                if ($previous !== null && bccomp(
                    $entry['payload']['bracket_min'],
                    $previous['payload']['bracket_max'],
                    2,
                ) <= 0) {
                    $errors[] = [
                        'row' => $entry['row'],
                        'message' => sprintf(
                            'Bracket overlaps row %d for effective date %s.',
                            $previous['row'],
                            $entry['payload']['effective_date'],
                        ),
                    ];
                }
                $previous = $entry;
            }
        }
    }

    private function decimal(mixed $value, int $scale, string $field): string
    {
        $raw = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
            throw new BusinessRuleException("{$field} must be a non-negative decimal.");
        }

        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        if (strlen($fraction) > $scale) {
            throw new BusinessRuleException("{$field} supports at most {$scale} decimal places.");
        }

        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;

        return $whole.'.'.str_pad($fraction, $scale, '0');
    }

    private function bustCache(ContributionAgency $agency): void
    {
        $key = $agency->value;
        Cache::forget("gov_table:{$key}:active");
        Cache::put("gov_table:{$key}:ver", ((int) Cache::get("gov_table:{$key}:ver", 1)) + 1, 86400);
    }

    /** @return array{total:int, imported:int, updated:int, skipped:int, deactivated_prior:int, errors:array<int, array{row:int, message:string}>} */
    private function blankResult(array $error): array
    {
        return [
            'total' => 0,
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'deactivated_prior' => 0,
            'errors' => [$error],
        ];
    }
}
