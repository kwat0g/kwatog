<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Exceptions\CapabilityStudyException;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Services\SpcService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Process capability (Cp / Cpk) endpoints.
 *
 * Replaces the capability half of the removed SpcController. The control-chart
 * endpoints (charts, data points, run-rule alerts) went with the scope cut —
 * see SpcService's class docblock for why. These two survive because they read
 * real inspection measurements and back two live screens: the Cp/Cpk panel on
 * the inspection-spec editor and the capability study page.
 */
class CapabilityController
{
    public function __construct(private readonly SpcService $spc) {}

    /**
     * Selectable spec items + the live Cpk interpretation thresholds.
     */
    public function options(): JsonResponse
    {
        $items = InspectionSpecItem::query()
            ->whereNotNull('tolerance_min')
            ->whereNotNull('tolerance_max')
            ->whereHas('spec', static fn (Builder $spec): Builder => $spec
                ->where('is_active', true)
                ->whereNull('inspection_specs.deleted_at'))
            ->with('spec:id,product_id')
            ->get(['id', 'inspection_spec_id', 'parameter_name', 'unit_of_measure']);

        return response()->json([
            'data' => [
                'spec_items' => $items->map(fn (InspectionSpecItem $i) => [
                    'id'             => $i->hash_id,
                    'parameter_name' => $i->parameter_name,
                    'unit'           => $i->unit_of_measure,
                ])->values(),
                'capability_thresholds' => $this->spc->capabilityThresholds(),
                'population_policy' => 'current_revision_only',
            ],
        ]);
    }

    /**
     * Run a capability study for one spec item, with a histogram.
     */
    public function capability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id'   => ['required', 'string'],
            'spec_item_id' => ['required', 'string'],
            'sample_size'  => ['nullable', 'integer', 'min:5', 'max:500'],
        ]);

        $product  = Product::query()->whereKey($this->decode($validated['product_id']))->firstOrFail();
        $specItem = InspectionSpecItem::query()
            ->with('spec')
            ->whereKey($this->decode($validated['spec_item_id']))
            ->firstOrFail();

        $spec = $specItem->spec;
        if ($spec === null || ! $spec->is_active || $spec->deleted_at !== null) {
            throw new CapabilityStudyException(
                'The selected inspection specification is archived or unavailable.',
                'quality_capability_spec_unavailable',
            );
        }
        if ((int) $spec->product_id !== (int) $product->id) {
            throw new CapabilityStudyException(
                'The selected dimension does not belong to the selected product.',
                'quality_capability_spec_product_mismatch',
            );
        }

        $thresholds = $this->spc->capabilityThresholds();

        $result = $this->spc->computeCapabilityStudy(
            $product->id,
            $specItem->id,
            $validated['sample_size'] ?? 50,
        );

        if ($result === null) {
            throw new CapabilityStudyException(
                sprintf('At least %d completed inspection measurements with measurable variation are required for a capability study.', $thresholds['minimum_samples']),
                'quality_capability_insufficient_samples',
            );
        }

        return response()->json([
            'data' => $result,
            'meta' => [
                'thresholds'     => $thresholds,
                'parameter_name' => $specItem->parameter_name,
                'unit'           => $specItem->unit_of_measure,
                'population_policy' => 'current_revision_only',
                'revision_id' => $specItem->revision?->hash_id,
                'revision_version' => $specItem->revision?->version !== null
                    ? (int) $specItem->revision->version
                    : null,
            ],
        ]);
    }

    private function decode(string $hashId): int
    {
        $decoded = app('hashids')->decode($hashId);
        abort_if($decoded === [], 404);

        return (int) $decoded[0];
    }
}
