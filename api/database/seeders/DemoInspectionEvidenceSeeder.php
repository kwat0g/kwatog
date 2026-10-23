<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoInspectionEvidenceSeeder extends Seeder
{
    public function run(): void
    {
        if (! config('app.seed_demo_data')) {
            return;
        }

        $inspections = DB::table('inspections')
            ->where('stage', 'outgoing')
            ->where('status', 'passed')
            ->whereNotNull('inspection_spec_id')
            ->whereNotNull('inspection_spec_revision_id')
            ->orderBy('id')
            ->get(['id', 'inspection_spec_id', 'inspection_spec_revision_id', 'batch_quantity', 'sample_size', 'inspected_quantity', 'sample_defect_count']);
        $repaired = 0;

        foreach ($inspections as $inspection) {
            $sampleSize = (int) $inspection->sample_size;
            if ($sampleSize < 1 || $sampleSize > (int) $inspection->batch_quantity || $sampleSize > 1000) {
                continue;
            }

            $items = DB::table('inspection_spec_items')
                ->where('inspection_spec_id', $inspection->inspection_spec_id)
                ->where('inspection_spec_revision_id', $inspection->inspection_spec_revision_id)
                ->orderBy('sort_order')
                ->get();
            if ($items->isEmpty()) {
                continue;
            }

            DB::transaction(function () use ($inspection, $items, $sampleSize, &$repaired): void {
                $measurements = DB::table('inspection_measurements')
                    ->where('inspection_id', $inspection->id)
                    ->get()
                    ->keyBy(fn (object $row): string => $row->sample_index.':'.$row->inspection_spec_item_id);
                $timestamp = now();

                for ($sampleIndex = 1; $sampleIndex <= $sampleSize; $sampleIndex++) {
                    foreach ($items as $item) {
                        $key = $sampleIndex.':'.$item->id;
                        $measurement = $measurements->get($key);
                        $measuredValue = $this->measuredValue($item, (int) $inspection->id, $sampleIndex);

                        if ($measurement !== null) {
                            $updates = [];
                            if ($measurement->is_pass === null || $measurement->item_result === null
                                || trim((string) $measurement->acceptance_criterion) === '') {
                                $measuredValue = $measurement->measured_value ?? $measuredValue;
                                $updates = [
                                    'measured_value' => $measuredValue,
                                    'is_pass' => $measurement->is_pass ?? $this->isWithinTolerance($item, $measuredValue),
                                    'item_result' => $measurement->item_result ?? 'passed',
                                    'acceptance_criterion' => trim((string) $measurement->acceptance_criterion) !== ''
                                        ? $measurement->acceptance_criterion
                                        : $this->acceptanceCriterion($item),
                                    'updated_at' => $timestamp,
                                ];
                            }

                            if ($updates !== []) {
                                $updates['notes'] = $measurement->notes ?: 'Demo fixture evidence completed by seeder.';
                                DB::table('inspection_measurements')->where('id', $measurement->id)->update($updates);
                                $repaired++;
                            }

                            continue;
                        }

                        DB::table('inspection_measurements')->insert([
                            'inspection_id' => $inspection->id,
                            'inspection_spec_item_id' => $item->id,
                            'sample_index' => $sampleIndex,
                            'parameter_name' => $item->parameter_name,
                            'parameter_type' => $item->parameter_type,
                            'unit_of_measure' => $item->unit_of_measure,
                            'nominal_value' => $item->nominal_value,
                            'tolerance_min' => $item->tolerance_min,
                            'tolerance_max' => $item->tolerance_max,
                            'measured_value' => $measuredValue,
                            'is_critical' => $item->is_critical,
                            'is_pass' => $this->isWithinTolerance($item, $measuredValue),
                            'item_result' => 'passed',
                            'acceptance_criterion' => $this->acceptanceCriterion($item),
                            'notes' => 'Synthetic demo sample measurement.',
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ]);
                        $measurements->put($key, (object) ['id' => null, 'is_pass' => true]);
                        $repaired++;
                    }
                }

                if ((int) ($inspection->inspected_quantity ?? 0) < $sampleSize
                    || $inspection->sample_defect_count === null) {
                    DB::table('inspections')->where('id', $inspection->id)->update([
                        'inspected_quantity' => $sampleSize,
                        'sample_defect_count' => $inspection->sample_defect_count ?? 0,
                        'updated_at' => $timestamp,
                    ]);
                }
            });
        }

        $this->command?->info("Completed {$repaired} missing demo inspection evidence row(s).");
    }

    private function measuredValue(object $item, int $inspectionId, int $sampleIndex): ?string
    {
        if ($item->tolerance_min === null || $item->tolerance_max === null) {
            return $item->nominal_value !== null ? (string) $item->nominal_value : null;
        }

        $minimum = (float) $item->tolerance_min;
        $maximum = (float) $item->tolerance_max;
        $span = max($maximum - $minimum, 0.0001);
        $fraction = ((($inspectionId + $sampleIndex) % 3) + 1) / 4;

        return number_format($minimum + ($span * $fraction), 4, '.', '');
    }

    private function isWithinTolerance(object $item, ?string $measuredValue): bool
    {
        if ($measuredValue === null || $item->tolerance_min === null || $item->tolerance_max === null) {
            return true;
        }

        return (float) $measuredValue >= (float) $item->tolerance_min
            && (float) $measuredValue <= (float) $item->tolerance_max;
    }

    private function acceptanceCriterion(object $item): string
    {
        if (trim((string) ($item->acceptance_criterion ?? '')) !== '') {
            return $item->acceptance_criterion;
        }

        if ($item->tolerance_min !== null && $item->tolerance_max !== null) {
            return "Measured value within {$item->tolerance_min}–{$item->tolerance_max} {$item->unit_of_measure}";
        }

        return 'Meets the approved inspection specification.';
    }
}
