<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Services\SpcService;
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
            ->with('inspectionSpec:id,product_id')
            ->get(['id', 'inspection_spec_id', 'parameter_name', 'unit_of_measure']);

        return response()->json([
            'data' => [
                'spec_items' => $items->map(fn (InspectionSpecItem $i) => [
                    'id'             => $i->hash_id,
                    'parameter_name' => $i->parameter_name,
                    'unit'           => $i->unit_of_measure,
                ])->values(),
                'capability_thresholds' => $this->spc->capabilityThresholds(),
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

        $product  = Product::where('id', $this->decode($validated['product_id']))->firstOrFail();
        $specItem = InspectionSpecItem::where('id', $this->decode($validated['spec_item_id']))->firstOrFail();

        $result = $this->spc->computeCapabilityStudy(
            $product->id,
            $specItem->id,
            $validated['sample_size'] ?? 50,
        );

        return response()->json([
            'data' => $result,
            'meta' => [
                'thresholds'     => $this->spc->capabilityThresholds(),
                'parameter_name' => $specItem->parameter_name,
                'unit'           => $specItem->unit_of_measure,
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
