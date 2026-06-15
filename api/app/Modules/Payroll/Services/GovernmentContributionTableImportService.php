<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

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
            throw new RuntimeException("CSV not readable at {$path}.");
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
            $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
            $missing = array_diff(self::REQUIRED_HEADER, $header);
            if ($missing) {
                return $this->blankResult([
                    'row' => 1,
                    'message' => 'Missing column(s): '.implode(', ', $missing),
                ]);
            }
            $idx = array_flip($header);

            $imported = 0;
            $updated  = 0;
            $skipped  = 0;
            $errors   = [];
            $rowNum   = 1;
            $total    = 0;
            $maxEffective = null;

            DB::transaction(function () use (
                $agency, $stream, $idx, $deactivatePrior,
                &$imported, &$updated, &$skipped, &$errors, &$rowNum, &$total, &$maxEffective,
            ) {
                while (($row = fgetcsv($stream)) !== false) {
                    $rowNum++;
                    if (count(array_filter($row, fn ($v) => $v !== null && $v !== '')) === 0) {
                        continue; // blank line
                    }
                    $total++;

                    try {
                        $payload = [
                            'bracket_min'    => (float) $row[$idx['bracket_min']],
                            'bracket_max'    => (float) $row[$idx['bracket_max']],
                            'ee_amount'      => (float) $row[$idx['ee_amount']],
                            'er_amount'      => (float) $row[$idx['er_amount']],
                            'effective_date' => Carbon::parse((string) $row[$idx['effective_date']])->toDateString(),
                        ];
                        if ($payload['bracket_max'] < $payload['bracket_min']) {
                            throw new RuntimeException('bracket_max < bracket_min');
                        }
                        if (! $maxEffective || strcmp($payload['effective_date'], $maxEffective) > 0) {
                            $maxEffective = $payload['effective_date'];
                        }

                        $existing = GovernmentContributionTable::query()
                            ->where('agency', $agency->value)
                            ->where('bracket_min', $payload['bracket_min'])
                            ->where('bracket_max', $payload['bracket_max'])
                            ->where('effective_date', $payload['effective_date'])
                            ->first();

                        if ($existing) {
                            $existing->update([
                                'ee_amount' => $payload['ee_amount'],
                                'er_amount' => $payload['er_amount'],
                                'is_active' => true,
                            ]);
                            $updated++;
                        } else {
                            GovernmentContributionTable::create(array_merge(
                                $payload,
                                ['agency' => $agency->value, 'is_active' => true],
                            ));
                            $imported++;
                        }
                    } catch (\Throwable $e) {
                        $skipped++;
                        $errors[] = ['row' => $rowNum, 'message' => $e->getMessage()];
                    }
                }
            });

            $deactivatedPrior = 0;
            if ($deactivatePrior && $maxEffective) {
                $deactivatedPrior = GovernmentContributionTable::query()
                    ->where('agency', $agency->value)
                    ->where('effective_date', '<', $maxEffective)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
            }

            $this->bustCache($agency);

            return [
                'total'             => $total,
                'imported'          => $imported,
                'updated'           => $updated,
                'skipped'           => $skipped,
                'deactivated_prior' => $deactivatedPrior,
                'errors'            => $errors,
            ];
        } finally {
            fclose($stream);
        }
    }

    private function bustCache(ContributionAgency $agency): void
    {
        // Match GovernmentContributionTableService::bust() cache-key scheme.
        Cache::forget("gov_table:{$agency->value}:active");
    }

    private function blankResult(array $error): array
    {
        return [
            'total' => 0, 'imported' => 0, 'updated' => 0, 'skipped' => 0,
            'deactivated_prior' => 0, 'errors' => [$error],
        ];
    }
}
