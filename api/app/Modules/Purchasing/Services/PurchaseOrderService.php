<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Services\ApprovalService;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\SettingsService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PurchaseOrderService
{
    private const VAT_RATE = '0.12';

    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly ApprovalService $approvals,
        private readonly SettingsService $settings,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = PurchaseOrder::query()->with([
            'vendor:id,name', 'creator:id,name,role_id',
            'purchaseRequest:id,pr_number',
        ]);
        if (! empty($filters['status']))   $q->where('status', $filters['status']);
        if (! empty($filters['vendor_id'])) {
            $vid = HashIdFilter::decode($filters['vendor_id'], Vendor::class);
            if ($vid) $q->where('vendor_id', $vid);
        }
        if (isset($filters['requires_vp_approval']) && $filters['requires_vp_approval'] !== '') {
            $q->where('requires_vp_approval', filter_var($filters['requires_vp_approval'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filters['from'])) $q->whereDate('date', '>=', $filters['from']);
        if (! empty($filters['to']))   $q->whereDate('date', '<=', $filters['to']);
        if (! empty($filters['search'])) {
            $q->where('po_number', 'ilike', '%'.$filters['search'].'%');
        }
        return $q->orderByDesc('date')->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(PurchaseOrder $po): PurchaseOrder
    {
        return $po->load([
            'vendor', 'purchaseRequest:id,pr_number',
            'items.item:id,code,name,unit_of_measure',
            'approvalRecords',
            'goodsReceiptNotes:id,grn_number,received_date,status,purchase_order_id',
            'bills:id,bill_number,total_amount,balance,status,purchase_order_id',
            'creator:id,name,role_id', 'approver:id,name,role_id',
        ]);
    }

    /** Create an ad-hoc PO directly. */
    public function create(array $data, User $by): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $by) {
            $vendorId = HashIdFilter::decode($data['vendor_id'], Vendor::class)
                ?? (int) $data['vendor_id'];
            $isVatable = (bool) ($data['is_vatable'] ?? true);

            [$lines, $subtotal] = $this->normalizeLines($data['items'] ?? []);
            $vat = $isVatable ? Money::mul($subtotal, self::VAT_RATE) : Money::zero();
            $total = Money::add($subtotal, $vat);
            $threshold = (float) $this->settings->get('approval.po.vp_threshold', 50000.0);

            $po = PurchaseOrder::create([
                'po_number'            => $this->sequences->generate('purchase_order'),
                'vendor_id'            => $vendorId,
                'purchase_request_id'  => null,
                'date'                 => $data['date'] ?? now()->toDateString(),
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'subtotal'             => $subtotal,
                'vat_amount'           => $vat,
                'total_amount'         => $total,
                'is_vatable'           => $isVatable,
                'status'               => PurchaseOrderStatus::Draft,
                'requires_vp_approval' => (float) $total >= $threshold,
                'created_by'           => $by->id,
                'remarks'              => $data['remarks'] ?? null,
            ]);
            foreach ($lines as $row) {
                PurchaseOrderItem::create(array_merge($row, ['purchase_order_id' => $po->id]));
            }
            return $this->show($po);
        });
    }

    /** Convert an approved PR into one or more POs (grouped by vendor). */
    public function convertFromPr(PurchaseRequest $pr, array $vendorMap, User $by): array
    {
        if ($pr->status !== PurchaseRequestStatus::Approved) {
            throw new RuntimeException('Only approved PRs can be converted to POs.');
        }
        // vendorMap: { pr_item_id => vendor_id }
        return DB::transaction(function () use ($pr, $vendorMap, $by) {
            $byVendor = [];
            foreach ($pr->items as $line) {
                $vendorId = $vendorMap[$line->id] ?? null;
                if (! $vendorId) {
                    throw new RuntimeException("PR line {$line->id} has no vendor assignment.");
                }
                $byVendor[$vendorId][] = $line;
            }
            $created = [];
            foreach ($byVendor as $vendorId => $lines) {
                $itemPayload = [];
                foreach ($lines as $line) {
                    $unitPrice = $line->estimated_unit_price ?? '0.00';
                    $itemPayload[] = [
                        'item_id'                  => $line->item_id,
                        'purchase_request_item_id' => $line->id,
                        'description'              => $line->description,
                        'quantity'                 => (string) $line->quantity,
                        'unit'                     => $line->unit,
                        'unit_price'               => (string) $unitPrice,
                    ];
                }
                $po = $this->create([
                    'vendor_id' => $vendorId,
                    'date'      => now()->toDateString(),
                    'is_vatable'=> true,
                    'remarks'   => "Auto-converted from PR {$pr->pr_number}",
                    'items'     => $itemPayload,
                ], $by);
                $po->purchase_request_id = $pr->id;
                $po->save();
                $created[] = $po;
            }
            $pr->update(['status' => PurchaseRequestStatus::Converted]);
            return $created;
        });
    }

    public function update(PurchaseOrder $po, array $data): PurchaseOrder
    {
        if ($po->status !== PurchaseOrderStatus::Draft) {
            throw new RuntimeException('Only draft POs can be edited.');
        }
        return DB::transaction(function () use ($po, $data) {
            $isVatable = (bool) ($data['is_vatable'] ?? $po->is_vatable);
            [$lines, $subtotal] = $this->normalizeLines($data['items'] ?? []);
            $vat = $isVatable ? Money::mul($subtotal, self::VAT_RATE) : Money::zero();
            $total = Money::add($subtotal, $vat);
            $threshold = (float) $this->settings->get('approval.po.vp_threshold', 50000.0);

            $po->update([
                'date'                 => $data['date'] ?? $po->date,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? $po->expected_delivery_date,
                'subtotal'             => $subtotal,
                'vat_amount'           => $vat,
                'total_amount'         => $total,
                'is_vatable'           => $isVatable,
                'requires_vp_approval' => (float) $total >= $threshold,
                'remarks'              => $data['remarks'] ?? $po->remarks,
            ]);

            $po->items()->delete();
            foreach ($lines as $row) {
                PurchaseOrderItem::create(array_merge($row, ['purchase_order_id' => $po->id]));
            }
            return $this->show($po->fresh());
        });
    }

    public function submit(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== PurchaseOrderStatus::Draft) {
            throw new RuntimeException('Only draft POs can be submitted.');
        }
        return DB::transaction(function () use ($po) {
            $this->approvals->submit($po, 'purchase_order', (float) $po->total_amount);
            $po->update(['status' => PurchaseOrderStatus::PendingApproval]);
            return $po->fresh();
        });
    }

    public function approve(PurchaseOrder $po, User $by, ?string $remarks = null): PurchaseOrder
    {
        if (! in_array($po->status, [PurchaseOrderStatus::PendingApproval, PurchaseOrderStatus::Draft], true)) {
            throw new RuntimeException('PO is not in an approvable state.');
        }
        return DB::transaction(function () use ($po, $by, $remarks) {
            $this->approvals->approve($po, $by, $remarks);
            if ($this->approvals->isFullyApproved($po)) {
                $po->update([
                    'status'      => PurchaseOrderStatus::Approved,
                    'approved_by' => $by->id,
                    'approved_at' => now(),
                ]);
                // Update last_price on approved_suppliers per line.
                foreach ($po->items as $line) {
                    ApprovedSupplier::query()->updateOrCreate(
                        ['item_id' => $line->item_id, 'vendor_id' => $po->vendor_id],
                        ['last_price' => $line->unit_price, 'last_price_at' => now()]
                    );
                }
            }
            return $po->fresh();
        });
    }

    public function reject(PurchaseOrder $po, User $by, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $by, $reason) {
            $this->approvals->reject($po, $by, $reason);
            $po->update(['status' => PurchaseOrderStatus::Cancelled]);
            return $po->fresh();
        });
    }

    public function markAsSent(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== PurchaseOrderStatus::Approved) {
            throw new RuntimeException('Only approved POs can be marked as sent.');
        }
        $po->update(['status' => PurchaseOrderStatus::Sent, 'sent_to_supplier_at' => now()]);
        return $po->fresh();
    }

    public function cancel(PurchaseOrder $po, string $reason): PurchaseOrder
    {
        if (in_array($po->status, [PurchaseOrderStatus::Received, PurchaseOrderStatus::Closed], true)) {
            throw new RuntimeException('Cannot cancel a fully received or closed PO.');
        }
        if ($po->goodsReceiptNotes()->exists()) {
            throw new RuntimeException('Cannot cancel a PO with GRNs.');
        }
        $po->update([
            'status'  => PurchaseOrderStatus::Cancelled,
            'remarks' => trim(($po->remarks ? $po->remarks."\n" : '').'Cancelled: '.$reason),
        ]);
        return $po->fresh();
    }

    public function close(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== PurchaseOrderStatus::Received) {
            throw new RuntimeException('Only fully received POs can be closed.');
        }
        $po->update(['status' => PurchaseOrderStatus::Closed]);
        return $po->fresh();
    }

    public function delete(PurchaseOrder $po): void
    {
        if ($po->status !== PurchaseOrderStatus::Draft) {
            throw new RuntimeException('Only draft POs can be deleted.');
        }
        $po->delete();
    }

    /**
     * @param array<int, array> $rows
     * @return array{0: array<int, array>, 1: string}
     */
    private function normalizeLines(array $rows): array
    {
        $lines = [];
        $subtotal = '0';
        foreach ($rows as $r) {
            $itemId = HashIdFilter::decode($r['item_id'] ?? null, Item::class) ?? (int) ($r['item_id'] ?? 0);
            if (! $itemId) {
                throw new RuntimeException('Each PO line must reference an item.');
            }
            $qty   = (string) ($r['quantity']   ?? '0');
            $price = (string) ($r['unit_price'] ?? '0');
            $total = Money::mul($qty, $price);
            $lines[] = [
                'item_id'                  => $itemId,
                'purchase_request_item_id' => $r['purchase_request_item_id'] ?? null,
                'description'              => $r['description'],
                'quantity'                 => $qty,
                'unit'                     => $r['unit'] ?? null,
                'unit_price'               => $price,
                'total'                    => $total,
            ];
            $subtotal = Money::add($subtotal, $total);
        }
        return [$lines, $subtotal];
    }
}
