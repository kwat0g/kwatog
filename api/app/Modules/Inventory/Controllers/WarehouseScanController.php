<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Modules\Inventory\Enums\WarehouseScanContext;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Models\WarehouseScanEvent;
use App\Modules\Inventory\Services\BarcodeScanResolverService;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WarehouseScanController
{
    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'contexts' => array_map(
                static fn (WarehouseScanContext $context): array => ['value' => $context->value, 'label' => $context->label()],
                WarehouseScanContext::cases(),
            ),
        ]]);
    }

    public function __construct(private readonly BarcodeScanResolverService $resolver) {}

    public function records(Request $request): JsonResponse
    {
        $data = $request->validate([
            'context' => ['required', Rule::enum(WarehouseScanContext::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $context = WarehouseScanContext::from($data['context']);
        [$model, $number, $statuses, $permissions] = match ($context) {
            WarehouseScanContext::Grn => [GoodsReceiptNote::class, 'grn_number', ['pending_qc'], ['inventory.grn.create']],
            WarehouseScanContext::MaterialIssue => [MaterialIssueSlip::class, 'slip_number', ['issued'], ['inventory.issue.create', 'inventory.picking.view']],
            WarehouseScanContext::StockCount => [StockCountSession::class, 'session_number', ['in_progress'], ['inventory.stock_count.manage']],
            WarehouseScanContext::WorkOrder => [WorkOrder::class, 'wo_number', ['confirmed', 'in_progress', 'paused'], ['inventory.issue.create']],
            WarehouseScanContext::Lookup => abort(422, 'General lookup does not require a context record.'),
        };
        foreach ($permissions as $permission) {
            abort_unless($request->user()->hasPermission($permission), 403);
        }
        $query = $model::query()->whereIn('status', $statuses);
        $search = trim($data['search'] ?? '');
        if ($search !== '') {
            $query->where($number, 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%');
        }

        return response()->json(['data' => $query->orderByDesc('id')->limit(25)->get()->map(
            static fn ($record): array => ['value' => $record->hash_id, 'label' => $record->{$number}],
        )]);
    }

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
        $result = $this->resolver->resolve($data['barcode'], $data['context'] ?? [], $request->user());
        $result['type_label'] = $result['type'] === 'unknown'
            ? '—'
            : Str::headline((string) $result['type']);
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
            'open_grn' => '/inventory/grn/create?po_id='.$params['po_id'],
            'view_po' => '/purchasing/purchase-orders/'.$params['id'],
            'view_wo' => '/production/work-orders/'.$params['id'],
            'report_output' => '/production/work-orders/'.$params['wo_id'].'/record-output',
            'report_defect' => '/production/work-orders/'.$params['wo_id'],
            'issue_to_wo' => '/inventory/material-issues/create?work_order_id='.$params['wo_id'].'&item_id='.$params['item_id'],
            'add_to_grn' => '/inventory/grn/'.$params['grn_id'].'?item_id='.$params['item_id'],
            'pick_for_issue' => '/inventory/picking?material_issue_id='.app('hashids')->encode((int) $params['material_issue_id']).'&item_id='.$params['item_id'],
            'record_count' => $this->countHref((int) $params['stock_count_item_id']),
            'view_bin' => '/inventory/warehouse-map?location_id='.app('hashids')->encode((int) $params['location_id']),
            default => null,
        };
    }

    private function countHref(int $itemId): ?string
    {
        $item = StockCountItem::query()->find($itemId);

        return $item ? '/inventory/stock-count?session_id='.app('hashids')->encode((int) $item->session_id).'&count_item_id='.$item->hash_id : null;
    }
}
