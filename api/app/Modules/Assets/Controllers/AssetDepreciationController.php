<?php

declare(strict_types=1);

namespace App\Modules\Assets\Controllers;

use App\Modules\Assets\Models\AssetDepreciation;
use App\Modules\Assets\Services\DepreciationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetDepreciationController
{
    public function __construct(private readonly DepreciationService $depreciation) {}

    public function index(Request $request): JsonResponse
    {
        $q = AssetDepreciation::query()->with('asset:id,asset_code,name');
        if ($request->filled('asset_id')) {
            $q->where('asset_id', (int) $request->input('asset_id'));
        }
        if ($request->filled('year')) {
            $q->where('period_year', (int) $request->input('year'));
        }
        if ($request->filled('month')) {
            $q->where('period_month', (int) $request->input('month'));
        }
        $rows = $q->orderByDesc('period_year')->orderByDesc('period_month')->orderBy('asset_id')
            ->paginate(min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => $rows->getCollection()->map(fn (AssetDepreciation $d) => [
                'id'                  => $d->hash_id,
                'asset'               => $d->asset ? [
                    'id'         => $d->asset->hash_id,
                    'asset_code' => $d->asset->asset_code,
                    'name'       => $d->asset->name,
                ] : null,
                'period_year'         => (int) $d->period_year,
                'period_month'        => (int) $d->period_month,
                'depreciation_amount' => (string) $d->depreciation_amount,
                'accumulated_after'   => (string) $d->accumulated_after,
                'created_at'          => optional($d->created_at)?->toISOString(),
            ]),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page'    => $rows->lastPage(),
                'per_page'     => $rows->perPage(),
                'total'        => $rows->total(),
            ],
        ]);
    }

    public function runMonth(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('assets.depreciation.run'), 403);
        $data = $request->validate([
            'year'  => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);
        $result = $this->depreciation->runForMonth(
            (int) $data['year'],
            (int) $data['month'],
            $request->user(),
        );
        return response()->json(['data' => $result]);
    }
}
