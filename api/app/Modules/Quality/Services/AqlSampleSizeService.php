<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;

/**
 * Sprint 7 — Task 60. ANSI/ASQ Z1.4 sample-size calculator at AQL 0.65,
 * General Inspection Level II — the level we use for outgoing batch QC.
 *
 * The table follows MIL-STD-105E (replaced by Z1.4 with identical layout).
 * For codes A-F at AQL 0.65 the standard arrows down to code G; we encode
 * that arrow by collapsing those rows directly to the G result.
 */
final class AqlSampleSizeService
{
    /**
     * @return array{code: string, sample_size: int, accept: int, reject: int}
     */
    public static function forBatch(int $batchQuantity): array
    {
        if ($batchQuantity < 1) {
            throw new BusinessRuleException('AQL sampling requires a positive batch quantity.');
        }
        /** @var array<string, mixed> $plan */
        $plan = app(SettingsService::class)->get('quality.aql.sample_plan', []);
        $tiny = is_array($plan['tiny_batch'] ?? null) ? $plan['tiny_batch'] : null;
        $rows = is_array($plan['rows'] ?? null) ? $plan['rows'] : [];
        $overflow = is_array($plan['overflow'] ?? null) ? $plan['overflow'] : null;
        if (! $tiny || $rows === [] || ! $overflow) {
            throw new BusinessRuleException('Required quality.aql.sample_plan setting is missing or invalid.');
        }

        if ($batchQuantity < 2) {
            // Tiny batches: 100% inspection, zero acceptance.
            return ['code' => (string) $tiny['code'], 'sample_size' => $batchQuantity, 'accept' => (int) $tiny['accept'], 'reject' => (int) $tiny['reject']];
        }

        // Sample plan resolved with AQL 0.65 arrow-rules pre-applied.
        // Format: [maxLot, code, sample, Ac, Re].
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['max_lot'], $row['code'], $row['sample_size'], $row['accept'], $row['reject'])) continue;
            if ($batchQuantity <= (int) $row['max_lot']) {
                $sample = min((int) $row['sample_size'], $batchQuantity); // never sample more than the lot
                return ['code' => (string) $row['code'], 'sample_size' => $sample, 'accept' => (int) $row['accept'], 'reject' => (int) $row['reject']];
            }
        }

        return ['code' => (string) $overflow['code'], 'sample_size' => (int) $overflow['sample_size'], 'accept' => (int) $overflow['accept'], 'reject' => (int) $overflow['reject']];
    }
}
