<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\WorkOrder;
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
class EdgeScanResolverService
{
    public function resolve(string $barcode, array $context = []): array
    {
        $code = strtoupper(trim($barcode));
        if ($code === '') {
            return $this->unknown();
        }

        return match (true) {
            str_starts_with($code, 'WO-')  => $this->resolveWorkOrder($code, $context),
            str_starts_with($code, 'PO-')  => $this->resolvePurchaseOrder($code, $context),
            str_starts_with($code, 'GRN-') => $this->resolveGrn($code, $context),
            default                        => $this->resolveItem($code, $context),
        };
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

        $status  = $this->statusValue($wo->status);
        $actions = [];

        if (in_array($status, ['in_progress', 'released', 'confirmed'], true)) {
            $actions[] = [
                'action' => 'report_output',
                'label'  => 'Report output',
                'params' => ['wo_id' => $wo->hash_id],
            ];
            $actions[] = [
                'action' => 'report_defect',
                'label'  => 'Report defect',
                'params' => ['wo_id' => $wo->hash_id],
            ];
        }
        $actions[] = [
            'action' => 'view_wo',
            'label'  => 'View WO',
            'params' => ['id' => $wo->hash_id],
        ];

        return [
            'type'   => 'work_order',
            'entity' => [
                'id'                => $wo->hash_id,
                'wo_number'         => $wo->wo_number,
                'product'           => $wo->product?->name,
                'status'            => $status,
                'quantity_target'   => (int) $wo->quantity_target,
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

        $status  = $this->statusValue($po->status);
        $actions = [];

        if (in_array($status, ['approved', 'sent', 'partially_received'], true)) {
            $actions[] = [
                'action' => 'open_grn',
                'label'  => "Receive against {$po->po_number}",
                'params' => ['po_id' => $po->hash_id],
            ];
        }
        $actions[] = [
            'action' => 'view_po',
            'label'  => 'View PO',
            'params' => ['id' => $po->hash_id],
        ];

        return [
            'type'   => 'purchase_order',
            'entity' => [
                'id'        => $po->hash_id,
                'po_number' => $po->po_number,
                'vendor'    => $po->vendor?->name,
                'status'    => $status,
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
            'type'   => 'goods_receipt_note',
            'entity' => [
                'id'         => $grn->hash_id,
                'grn_number' => $grn->grn_number,
                'status'     => $this->statusValue($grn->status),
            ],
            'suggested_actions' => [[
                'action' => 'view_grn',
                'label'  => 'View GRN',
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

        // Context-aware: scanner bound to an active WO → suggest issue.
        $woHash = $context['wo_id'] ?? null;
        if (is_string($woHash) && $woHash !== '') {
            $woId = $this->decodeHashId($woHash);
            $wo   = $woId ? WorkOrder::query()->find($woId) : null;
            if ($wo && in_array($this->statusValue($wo->status), ['released', 'in_progress', 'confirmed'], true)) {
                $actions[] = [
                    'action' => 'issue_to_wo',
                    'label'  => "Issue to {$wo->wo_number}",
                    'params' => ['item_id' => $item->hash_id, 'wo_id' => $wo->hash_id],
                ];
            }
        }

        // Context-aware: scanner bound to a not-yet-accepted GRN → suggest add line.
        $grnHash = $context['grn_id'] ?? null;
        if (is_string($grnHash) && $grnHash !== '') {
            $grnId = $this->decodeHashId($grnHash);
            $grn   = $grnId ? GoodsReceiptNote::query()->find($grnId) : null;
            if ($grn && $this->statusValue($grn->status) === 'pending_qc') {
                $actions[] = [
                    'action' => 'add_to_grn',
                    'label'  => "Add to {$grn->grn_number}",
                    'params' => ['item_id' => $item->hash_id, 'grn_id' => $grn->hash_id],
                ];
            }
        }

        $actions[] = [
            'action' => 'view_item',
            'label'  => 'View item',
            'params' => ['id' => $item->hash_id],
        ];

        return [
            'type'   => 'item',
            'entity' => [
                'id'              => $item->hash_id,
                'code'            => $item->code,
                'name'            => $item->name,
                'item_type'       => $item->item_type instanceof \BackedEnum
                    ? $item->item_type->value
                    : $item->item_type,
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

    private function decodeHashId(string $hash): ?int
    {
        $decoded = app('hashids')->decode($hash);
        return empty($decoded) ? null : (int) $decoded[0];
    }
}
