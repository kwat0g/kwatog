<?php

declare(strict_types=1);

namespace Tests\Unit\Quality;

use App\Modules\Quality\Enums\InspectionMode;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Support\LotDefectCounter;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The lot verdict arithmetic, pinned once. `complete()` and the CoC evidence
 * guard both derived this separately; a divergence between them let a lot pass
 * inspection and then be refused a certificate.
 */
class LotDefectCounterTest extends TestCase
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: Inspection, 1: Collection<int, InspectionMeasurement>}
     */
    private function inspection(string $mode, ?int $reportedDefects, array $rows): array
    {
        $inspection = (new Inspection)->forceFill([
            'inspection_mode' => $mode,
            'sample_defect_count' => $reportedDefects,
        ]);

        $measurements = new Collection;
        foreach ($rows as $index => $row) {
            $measurements->push((new InspectionMeasurement)->forceFill(array_merge([
                'id' => $index + 1,
                'inspection_id' => 1,
                'sample_index' => $index + 1,
                'parameter_name' => 'Shaft OD',
                'is_pass' => null,
                'is_critical' => false,
                'tolerance_min' => null,
                'tolerance_max' => null,
            ], $row)));
        }

        return [$inspection, $measurements];
    }

    public function test_reported_defect_count_is_used_when_no_piece_failed(): void
    {
        [$inspection, $rows] = $this->inspection(InspectionMode::LotChecklist->value, 2, [
            ['is_pass' => true, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1'],
            ['is_pass' => true, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1'],
        ]);

        $counted = LotDefectCounter::for($inspection, $rows);

        $this->assertSame(2, $counted['defects']);
        $this->assertFalse($counted['criticalFail']);
    }

    public function test_failed_pieces_win_when_they_exceed_the_reported_count(): void
    {
        [$inspection, $rows] = $this->inspection(InspectionMode::LotChecklist->value, 1, [
            ['is_pass' => false, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1', 'sample_index' => 1],
            ['is_pass' => false, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1', 'sample_index' => 2],
            ['is_pass' => false, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1', 'sample_index' => 3],
        ]);

        $counted = LotDefectCounter::for($inspection, $rows);

        $this->assertSame(3, $counted['defects']);
    }

    /**
     * Regression pin: `sample_index` identifies the PIECE in lot_checklist mode,
     * so several failing parameters on one piece are one defect, not several.
     * Counting them separately inflates the count past accept_count and
     * over-rejects a lot.
     */
    public function test_duplicate_checklist_rows_on_one_piece_count_once(): void
    {
        [$inspection, $rows] = $this->inspection(InspectionMode::LotChecklist->value, 0, [
            ['is_pass' => false, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1', 'sample_index' => 7],
            ['is_pass' => false, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1', 'sample_index' => 7],
        ]);

        $counted = LotDefectCounter::for($inspection, $rows);

        $this->assertSame(1, $counted['defects']);
    }

    /**
     * Regression pin: `Collection::where('is_pass', false)` matches null rows
     * because `null == false` is true in PHP. An unresolved row is not a defect.
     */
    public function test_unresolved_rows_are_not_defects(): void
    {
        [$inspection, $rows] = $this->inspection(InspectionMode::LotChecklist->value, 0, [
            ['is_pass' => null, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1', 'sample_index' => 1],
            ['is_pass' => null, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1', 'sample_index' => 2],
        ]);

        $counted = LotDefectCounter::for($inspection, $rows);

        $this->assertSame(0, $counted['defects']);
    }

    public function test_a_failed_checklist_row_is_not_a_piece_defect(): void
    {
        // Checklist rows (no tolerance) carry the lot-level verdict; only piece
        // rows contribute to the AQL defect count.
        [$inspection, $rows] = $this->inspection(InspectionMode::LotChecklist->value, 0, [
            ['is_pass' => false, 'sample_index' => 1],
        ]);

        $counted = LotDefectCounter::for($inspection, $rows);

        $this->assertSame(0, $counted['defects']);
    }

    public function test_a_failed_critical_row_anywhere_raises_the_critical_flag(): void
    {
        [$inspection, $rows] = $this->inspection(InspectionMode::LotChecklist->value, 0, [
            ['is_pass' => true, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1'],
            ['is_pass' => false, 'is_critical' => true, 'sample_index' => 1],
        ]);

        $counted = LotDefectCounter::for($inspection, $rows);

        $this->assertTrue($counted['criticalFail']);
    }

    /**
     * Regression pin: the commonest real-world case is a critical dimension
     * measured within tolerance. A critical row that PASSED must not raise the
     * flag, or a conforming lot is rejected.
     */
    public function test_a_conforming_critical_row_does_not_raise_the_critical_flag(): void
    {
        [$inspection, $rows] = $this->inspection(InspectionMode::LotChecklist->value, 0, [
            ['is_pass' => true, 'is_critical' => true, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1'],
            ['is_pass' => true, 'is_critical' => false, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1'],
        ]);

        $counted = LotDefectCounter::for($inspection, $rows);

        $this->assertFalse($counted['criticalFail']);
        $this->assertSame(0, $counted['defects']);
    }

    public function test_per_unit_mode_counts_distinct_failing_pieces(): void
    {
        [$inspection, $rows] = $this->inspection(InspectionMode::PerUnit->value, null, [
            ['is_pass' => false, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1', 'sample_index' => 2],
            ['is_pass' => false, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1', 'sample_index' => 2],
            ['is_pass' => false, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1', 'sample_index' => 4],
        ]);

        $counted = LotDefectCounter::for($inspection, $rows);

        $this->assertSame(2, $counted['defects']);
    }

    public function test_a_null_sample_defect_count_reads_as_zero(): void
    {
        [$inspection, $rows] = $this->inspection(InspectionMode::LotChecklist->value, null, [
            ['is_pass' => true, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1'],
        ]);

        $counted = LotDefectCounter::for($inspection, $rows);

        $this->assertSame(0, $counted['defects']);
    }
}
