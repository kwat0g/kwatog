<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\StockCountItemStatus;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;

/**
 * T2.1 — Resolves a scanned barcode to a typed entity + state-aware
 * suggested actions. Dispatch by canonical prefix:
 *   WO-*  → work order, PO-*  → purchase order,
 *   GRN-* → goods receipt note, otherwise → item.code.
 *
 * Unknown barcodes return type='unknown' with no actions. The device
 * renders "Unrecognised barcode" on that response.
 */
class BarcodeScanResolverService
{
    public function resolve(string $barcode, array $context = []): array
    {
        $code = strtoupper(trim($barcode));
        if ($code === '') {
            return $this->unknown();
        }

        return match (true) {
            str_starts_with($code, 'WO-') => $this->resolveWorkOrder($code, $context),
            str_starts_with($code, 'PO-') => $this->resolvePurchaseOrder($code, $context),
            str_starts_with($code, 'GRN-') => $this->resolveGrn($code, $context),
            default => $this->resolveInventoryCode($code, $context),
        };
    }

    private function resolveInventoryCode(string $code, array $context): array
    {
        $item = $this->resolveItem($code, $context);
        if ($item['type'] !== 'unknown') {
            return $item;
        }

        $location = WarehouseLocation::query()->with('zone.warehouse')
            ->whereRaw('UPPER(code) = ?', [$code])->first();
        if (! $location) {
            return $this->unknown();
        }

        $actions = [[
            'action' => 'view_bin',
            'label' => 'View bin',
            'params' => ['location_id' => $location->id],
        ]];
        $sessionId = $this->decodeNumericOrHash($context['stock_count_session_id'] ?? null);
        if ($sessionId) {
            $countItem = StockCountItem::query()->where('session_id', $sessionId)
                ->where('location_id', $location->id)->first();
            if ($countItem) {
                $actions[] = [
                    'action' => 'record_count',
                    'label' => 'Record count for this bin',
                    'params' => ['stock_count_item_id' => $countItem->id],
                ];
            }
        }

        return [
            'type' => 'warehouse_location',
            'entity' => [
                'id' => $location->id,
                'code' => $location->code,
                'full_code' => $location->full_code,
                'warehouse' => $location->zone?->warehouse?->name,
                'zone' => $location->zone?->name,
            ],
            'suggested_actions' => $actions,
        ];
    }

    private function resolveWorkOrder(string $code, array $context): array
    {
        $wo = WorkOrder::query()
            ->with(['product:id,name'])
            ->where('wo_number', $code)
            ->first();
        if (! $wo) {
            return $this->unknown();
        }

        $status = $this->statusValue($wo->status);
        $actions = [];

        if (in_array($status, ['in_progress', 'released', 'confirmed'], true)) {
            $actions[] = [
                'action' => 'report_output',
                'label' => 'Report output',
                'params' => ['wo_id' => $wo->hash_id],
            ];
            $actions[] = [
                'action' => 'report_defect',
                'label' => 'Report defect',
                'params' => ['wo_id' => $wo->hash_id],
            ];
        }
        $actions[] = [
            'action' => 'view_wo',
            'label' => 'View WO',
            'params' => ['id' => $wo->hash_id],
        ];

        return [
            'type' => 'work_order',
            'entity' => [
                'id' => $wo->hash_id,
                'wo_number' => $wo->wo_number,
                'product' => $wo->product?->name,
                'status' => $status,
                'status_label' => $this->enumLabel(WorkOrderStatus::tryFrom($status ?? '')),
                'quantity_target' => (int) $wo->quantity_target,
                'quantity_produced' => (int) ($wo->quantity_produced ?? 0),
            ],
            'suggested_actions' => $actions,
        ];
    }

    private function resolvePurchaseOrder(string $code, array $context): array
    {
        $po = PurchaseOrder::query()
            ->with(['vendor:id,name'])
            ->where('po_number', $code)
            ->first();
        if (! $po) {
            return $this->unknown();
        }

        $status = $this->statusValue($po->status);
        $actions = [];

        if (in_array($status, ['approved', 'sent', 'partially_received'], true)) {
            $actions[] = [
                'action' => 'open_grn',
                'label' => "Receive against {$po->po_number}",
                'params' => ['po_id' => $po->hash_id],
            ];
        }
        $actions[] = [
            'action' => 'view_po',
            'label' => 'View PO',
            'params' => ['id' => $po->hash_id],
        ];

        return [
            'type' => 'purchase_order',
            'entity' => [
                'id' => $po->hash_id,
                'po_number' => $po->po_number,
                'vendor' => $po->vendor?->name,
                'status' => $status,
                'status_label' => $this->enumLabel(PurchaseOrderStatus::tryFrom($status ?? '')),
            ],
            'suggested_actions' => $actions,
        ];
    }

    private function resolveGrn(string $code, array $context): array
    {
        $grn = GoodsReceiptNote::query()
            ->where('grn_number', $code)
            ->first();
        if (! $grn) {
            return $this->unknown();
        }

        return [
            'type' => 'goods_receipt_note',
            'entity' => [
                'id' => $grn->hash_id,
                'grn_number' => $grn->grn_number,
                'status' => $grnStatus = $this->statusValue($grn->status),
                'status_label' => $this->enumLabel(GrnStatus::tryFrom($grnStatus ?? '')),
            ],
            'suggested_actions' => [[
                'action' => 'view_grn',
                'label' => 'View GRN',
                'params' => ['id' => $grn->hash_id],
            ]],
        ];
    }

    private function resolveItem(string $code, array $context): array
    {
        $item = Item::query()->where('code', $code)->first();
        if (! $item) {
            return $this->unknown();
        }

        $actions = [];

        $sessionId = $this->decodeNumericOrHash($context['stock_count_session_id'] ?? null);
        if ($sessionId) {
            $countItem = StockCountItem::query()->where('session_id', $sessionId)
                ->where('item_id', $item->id)
                ->whereIn('status', [StockCountItemStatus::Pending->value, StockCountItemStatus::Counted->value])
                ->first();
            if ($countItem) {
                $actions[] = [
                    'action' => 'record_count',
                    'label' => 'Record cycle count',
                    'params' => ['stock_count_item_id' => $countItem->id],
                ];
            }
        }

        $misId = $this->decodeNumericOrHash($context['material_issue_id'] ?? null);
        if ($misId) {
            $actions[] = [
                'action' => 'pick_for_issue',
                'label' => 'Open material issue picking',
                'params' => ['material_issue_id' => $misId, 'item_id' => $item->hash_id],
            ];
        }

        // Context-aware: scanner bound to an active WO → suggest issue.
        $woHash = $context['wo_id'] ?? null;
        if (is_string($woHash) && $woHash !== '') {
            $woId = $this->decodeHashId($woHash);
            $wo = $woId ? WorkOrder::query()->find($woId) : null;
            if ($wo && in_array($this->statusValue($wo->status), ['released', 'in_progress', 'confirmed'], true)) {
                $actions[] = [
                    'action' => 'issue_to_wo',
                    'label' => "Issue to {$wo->wo_number}",
                    'params' => ['item_id' => $item->hash_id, 'wo_id' => $wo->hash_id],
                ];
            }
        }

        // Context-aware: scanner bound to a not-yet-accepted GRN → suggest add line.
        $grnHash = $context['grn_id'] ?? null;
        if (is_string($grnHash) && $grnHash !== '') {
            $grnId = $this->decodeHashId($grnHash);
            $grn = $grnId ? GoodsReceiptNote::query()->find($grnId) : null;
            if ($grn && $this->statusValue($grn->status) === 'pending_qc') {
                $actions[] = [
                    'action' => 'add_to_grn',
                    'label' => "Add to {$grn->grn_number}",
                    'params' => ['item_id' => $item->hash_id, 'grn_id' => $grn->hash_id],
                ];
            }
        }

        $actions[] = [
            'action' => 'view_item',
            'label' => 'View item',
            'params' => ['id' => $item->hash_id],
        ];

        return [
            'type' => 'item',
            'entity' => [
                'id' => $item->hash_id,
                'code' => $item->code,
                'name' => $item->name,
                'item_type' => $item->item_type instanceof \BackedEnum
                    ? $item->item_type->value
                    : $item->item_type,
                'item_type_label' => $this->enumLabel($item->item_type instanceof ItemType
                    ? $item->item_type
                    : ItemType::tryFrom((string) $item->item_type)),
                'unit_of_measure' => $item->unit_of_measure,
            ],
            'suggested_actions' => $actions,
        ];
    }

    private function unknown(): array
    {
        return ['type' => 'unknown', 'entity' => null, 'suggested_actions' => []];
    }

    private function statusValue(mixed $status): ?string
    {
        if ($status instanceof \BackedEnum) {
            return (string) $status->value;
        }

        return $status === null ? null : (string) $status;
    }

    private function enumLabel(?\BackedEnum $enum): ?string
    {
        return $enum !== null && method_exists($enum, 'label') ? $enum->label() : null;
    }

    private function decodeHashId(string $hash): ?int
    {
        $decoded = app('hashids')->decode($hash);

        return empty($decoded) ? null : (int) $decoded[0];
    }

    private function decodeNumericOrHash(mixed $value): ?int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }

        return is_string($value) && $value !== '' ? $this->decodeHashId($value) : null;
    }
}
