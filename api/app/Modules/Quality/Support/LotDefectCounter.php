<?php

declare(strict_types=1);

namespace App\Modules\Quality\Support;

use App\Modules\Quality\Enums\InspectionMode;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use Illuminate\Support\Collection;

/**
 * The one implementation of the lot verdict arithmetic.
 *
 * A pass is `! criticalFail && defects <= accept_count`. Both the completion
 * path (InspectionService::complete) and the certificate evidence guard
 * (CoCService::assertEvidenceSupportsCertificate) read the count from here, so
 * a lot cannot pass inspection on one formula and be refused a certificate on
 * another.
 *
 * Every comparison against is_pass is strict (`=== false`). Loose comparison
 * is a trap here: `Collection::where('is_pass', false)` matches rows whose
 * is_pass is null, because null == false is true in PHP, and an unresolved
 * row would then be counted as a defect.
 */
final class LotDefectCounter
{
    /**
     * @param  Collection<int, InspectionMeasurement>  $rows
     * @return array{defects: int, criticalFail: bool}
     */
    public static function for(Inspection $inspection, Collection $rows): array
    {
        $criticalFail = $rows->contains(
            static fn (InspectionMeasurement $row): bool => (bool) $row->is_critical && $row->is_pass === false
        );

        $failed = static fn (InspectionMeasurement $row): bool => $row->is_pass === false;
        $hasTolerance = static fn (InspectionMeasurement $row): bool => $row->tolerance_min !== null || $row->tolerance_max !== null;

        if ($inspection->inspection_mode === InspectionMode::LotChecklist) {
            // Reported defects cover the whole AQL sample the inspector counted;
            // piece rows are the few dimensions actually measured. The larger of
            // the two is the lot's defect count.
            $reported = (int) ($inspection->sample_defect_count ?? 0);
            $failedPieces = $rows->filter($failed)->filter($hasTolerance)
                ->pluck('sample_index')->unique()->count();

            return ['defects' => max($reported, $failedPieces), 'criticalFail' => $criticalFail];
        }

        return [
            'defects' => $rows->filter($failed)->pluck('sample_index')->unique()->count(),
            'criticalFail' => $criticalFail,
        ];
    }
}
