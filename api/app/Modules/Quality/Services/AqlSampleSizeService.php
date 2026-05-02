<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

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
        if ($batchQuantity < 2) {
            // Tiny batches: 100% inspection, zero acceptance.
            return ['code' => 'A', 'sample_size' => max(1, $batchQuantity), 'accept' => 0, 'reject' => 1];
        }

        // Sample plan resolved with AQL 0.65 arrow-rules pre-applied.
        // Format: [maxLot, code, sample, Ac, Re].
        $table = [
            [8,        'G', 32,   0, 1], // arrow from A
            [15,       'G', 32,   0, 1], // arrow from B
            [25,       'G', 32,   0, 1], // arrow from C
            [50,       'G', 32,   0, 1], // arrow from D
            [90,       'G', 32,   0, 1], // arrow from E
            [150,      'G', 32,   0, 1], // arrow from F
            [280,      'G', 32,   0, 1],
            [500,      'H', 50,   1, 2],
            [1200,     'J', 80,   1, 2],
            [3200,     'K', 125,  2, 3],
            [10000,    'L', 200,  3, 4],
            [35000,    'M', 315,  5, 6],
            [150000,   'N', 500,  7, 8],
            [500000,   'P', 800,  10, 11],
        ];

        foreach ($table as [$max, $code, $n, $ac, $re]) {
            if ($batchQuantity <= $max) {
                $sample = min($n, $batchQuantity); // never sample more than the lot
                return ['code' => $code, 'sample_size' => $sample, 'accept' => $ac, 'reject' => $re];
            }
        }

        return ['code' => 'Q', 'sample_size' => 1250, 'accept' => 14, 'reject' => 15];
    }
}
