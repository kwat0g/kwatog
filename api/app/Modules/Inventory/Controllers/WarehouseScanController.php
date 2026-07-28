<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Modules\Edge\Services\EdgeScanResolverService;
use App\Modules\Inventory\Models\WarehouseScanEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseScanController
{
    public function __construct(private readonly EdgeScanResolverService $resolver) {}

    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'barcode' => ['required', 'string', 'max:255'],
            'context' => ['sometimes', 'array'],
            'context.wo_id' => ['nullable', 'string'],
            'context.grn_id' => ['nullable', 'string'],
            'context.material_issue_id' => ['nullable'],
            'context.stock_count_session_id' => ['nullable'],
        ]);
        $result = $this->resolver->resolve($data['barcode'], $data['context'] ?? []);
        WarehouseScanEvent::query()->create([
            'user_id' => $request->user()->id,
            'barcode' => strtoupper(trim($data['barcode'])),
            'result_type' => $result['type'],
            'is_recognized' => $result['type'] !== 'unknown',
            'context' => $data['context'] ?? null,
        ]);
        $result['suggested_actions'] = array_map(function (array $action): array {
            $action['href'] = $this->href($action['action'], $action['params']);

            return $action;
        }, $result['suggested_actions']);

        return response()->json(['data' => $result]);
    }

    /** @param array<string, mixed> $params */
    private function href(string $action, array $params): ?string
    {
        return match ($action) {
            'view_item' => '/inventory/items/'.$params['id'],
            'view_grn' => '/inventory/grn/'.$params['id'],
            'open_grn' => '/inventory/grn/create?purchase_order_id='.$params['po_id'],
            'view_po' => '/purchasing/purchase-orders/'.$params['id'],
            'view_wo' => '/production/work-orders/'.$params['id'],
            'report_output' => '/production/work-orders/'.$params['wo_id'].'/record-output',
            'report_defect' => '/production/work-orders/'.$params['wo_id'],
            'issue_to_wo' => '/inventory/material-issues/create?work_order_id='.$params['wo_id'].'&item_id='.$params['item_id'],
            'add_to_grn' => '/inventory/grn/'.$params['grn_id'].'?item_id='.$params['item_id'],
            'pick_for_issue' => '/inventory/picking?material_issue_id='.$params['material_issue_id'].'&item_id='.$params['item_id'],
            'record_count' => '/inventory/stock-count?count_item_id='.$params['stock_count_item_id'],
            'view_bin' => '/inventory/warehouse-map?location_id='.$params['location_id'],
            default => null,
        };
    }
}
