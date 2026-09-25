<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\HashIdFilter;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\ReturnManagement\Models\ReturnCaseLine;
use App\Modules\ReturnManagement\Models\ReturnCaseReceiptAllocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Allocates accepted supply once, across receipts and cases, in base units. */
class ReturnCaseRedeliveryService
{
    public function add(ReturnCase $case, array $hashes, User $by): void
    {
        DB::transaction(function () use ($case, $hashes, $by): void {
            $case = ReturnCase::query()->lockForUpdate()->findOrFail($case->id);
            if ($case->type->value !== 'supplier' || $case->resolution?->value !== 'redelivery'
                || ! in_array($case->status->value, ['action_agreed', 'in_progress'], true)) {
                throw new BusinessRuleException('Agree supplier redelivery before allocating its accepted receipts.');
            }
            $case->load('lines', 'returnRequest', 'receiptAllocations');
            $ids = [];
            foreach ($hashes as $hash) {
                $id = HashIdFilter::decode($hash, GoodsReceiptNote::class);
                if (! $id) {
                    throw ValidationException::withMessages(['resolution_goods_receipt_note_ids' => 'Choose valid redelivery receipts.']);
                }
                $ids[] = $id;
            }
            $ids = array_values(array_unique($ids));
            sort($ids);
            // All allocation writers lock receipt headers, then receipt lines,
            // in ID order. Different cases competing for a receipt serialize here.
            $receipts = GoodsReceiptNote::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if (count($ids) !== $receipts->count() || $ids === []) {
                throw ValidationException::withMessages(['resolution_goods_receipt_note_ids' => 'Choose valid redelivery receipts.']);
            }
            $items = GrnItem::query()->whereIn('goods_receipt_note_id', $ids)->orderBy('id')->lockForUpdate()->get();
            foreach ($receipts as $receipt) {
                $receipt->setRelation('items', $items->where('goods_receipt_note_id', $receipt->id)->values());
                if (! $this->eligible($case, $receipt)) {
                    throw new BusinessRuleException('Choose an accepted redelivery receipt for this supplier and the original or authorized replacement PO, created after the case.');
                }
            }
            $plan = $this->plan($case, $receipts);
            if ($plan === [] && $case->receiptAllocations->whereIn('goods_receipt_note_id', $ids)->isEmpty()) {
                throw new BusinessRuleException('These receipts have no unallocated accepted quantity for the remaining case lines. Refresh the receipts and try again.');
            }
            foreach ($plan as $row) {
                $allocation = ReturnCaseReceiptAllocation::query()->where('return_case_line_id', $row['return_case_line_id'])
                    ->where('grn_item_id', $row['grn_item_id'])->first();
                if ($allocation) {
                    $allocation->forceFill(['quantity' => bcadd($allocation->quantity, $row['quantity'], 3)])->save();
                } else {
                    (new ReturnCaseReceiptAllocation)->forceFill($row + ['return_case_id' => $case->id, 'created_by' => $by->id])->save();
                }
            }
            // Retain the first receipt as a backwards-compatible summary link.
            // Completion and display use the allocation ledger, never this pointer.
            if (! $case->resolution_goods_receipt_note_id) {
                $first = $case->receiptAllocations()->first();
                if ($first) {
                    $case->forceFill(['resolution_goods_receipt_note_id' => $first->goods_receipt_note_id])->save();
                }
            }
        }, 3);
    }

    public function options(ReturnCase $case): array
    {
        if ($case->type->value !== 'supplier' || $case->resolution?->value !== 'redelivery') {
            return [];
        }
        $case->loadMissing('lines', 'returnRequest', 'receiptAllocations');
        $receipts = GoodsReceiptNote::query()->where('vendor_id', $case->vendor_id)
            ->whereIn('purchase_order_id', array_filter([$case->purchase_order_id, $case->returnRequest?->replacement_purchase_order_id]))
            ->where('id', '!=', (int) $case->goods_receipt_note_id)
            ->where('created_at', '>=', $case->created_at)->whereIn('status', ['accepted', 'partial_accepted'])
            ->with('items')->orderBy('id')->get();

        return $receipts->filter(fn ($receipt) => $this->eligible($case, $receipt) && $this->plan($case, collect([$receipt])) !== [])
            ->map(fn ($receipt) => ['id' => $receipt->hash_id, 'label' => $receipt->grn_number])->values()->all();
    }

    public function assertComplete(ReturnCase $case): void
    {
        $case->load('lines', 'returnRequest', 'receiptAllocations.grnItem');
        $ids = $case->receiptAllocations->pluck('goods_receipt_note_id')->unique()->sort()->values();
        $receipts = GoodsReceiptNote::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->with('items')->get()->keyBy('id');
        if ($case->receiptAllocations->isEmpty()) {
            throw new BusinessRuleException('Add accepted redelivery receipts before resolving this case.');
        }
        $totals = ReturnCaseReceiptAllocation::query()->whereIn('grn_item_id', $case->receiptAllocations->pluck('grn_item_id'))
            ->selectRaw('grn_item_id, SUM(quantity) AS allocated')->groupBy('grn_item_id')->pluck('allocated', 'grn_item_id');
        foreach ($case->receiptAllocations as $allocation) {
            $receipt = $receipts->get($allocation->goods_receipt_note_id);
            $line = $case->lines->firstWhere('id', $allocation->return_case_line_id);
            $item = $receipt?->items->firstWhere('id', $allocation->grn_item_id);
            if (! $receipt || ! $line || ! $item || ! $this->eligible($case, $receipt) || ! $this->matches($case, $line, $receipt, $item)
                || bccomp((string) $totals->get($item->id, '0'), (string) $item->quantity_accepted, 3) > 0) {
                throw new BusinessRuleException('The linked receipt quantities or Quality status changed. Review their accepted quantities before resolving.');
            }
        }
        foreach ($case->lines as $line) {
            $allocated = $case->receiptAllocations->where('return_case_line_id', $line->id)
                ->reduce(fn ($sum, $row) => bcadd($sum, $row->quantity, 3), '0');
            if (bccomp($allocated, $this->required($line), 3) < 0) {
                throw new BusinessRuleException('Accepted redeliveries do not yet cover all verified quantities. Add the remaining receipts before resolving.');
            }
        }
    }

    private function eligible(ReturnCase $case, GoodsReceiptNote $receipt): bool
    {
        if ($case->type->value !== 'supplier' || (int) $receipt->vendor_id !== (int) $case->vendor_id
            || (int) $receipt->id === (int) $case->goods_receipt_note_id
            || $receipt->created_at->lt($case->created_at)
            || ! in_array($receipt->status->value, ['accepted', 'partial_accepted'], true)
            || ! in_array((int) $receipt->purchase_order_id, array_map('intval', array_filter([$case->purchase_order_id, $case->returnRequest?->replacement_purchase_order_id])), true)) {
            return false;
        }
        return $case->lines->contains(fn ($line) => bccomp($this->required($line), '0', 3) > 0
            && $receipt->items->contains(fn ($item) => $this->matches($case, $line, $receipt, $item) && bccomp((string) $item->quantity_accepted, '0', 3) > 0));
    }

    private function matches(ReturnCase $case, ReturnCaseLine $line, GoodsReceiptNote $receipt, GrnItem $item): bool
    {
        return $line->item_id !== null && (int) $item->item_id === (int) $line->item_id
            && ((int) $receipt->purchase_order_id !== (int) $case->purchase_order_id
                || (int) $item->purchase_order_item_id === (int) $line->source_po_item_id);
    }

    /** Plan against current accepted capacities; no inventory or accounting writes. */
    private function plan(ReturnCase $case, Collection $receipts): array
    {
        $used = ReturnCaseReceiptAllocation::query()->whereIn('grn_item_id', $receipts->flatMap->items->pluck('id'))
            ->selectRaw('grn_item_id, SUM(quantity) AS allocated')->groupBy('grn_item_id')->pluck('allocated', 'grn_item_id');
        $remaining = [];
        foreach ($case->lines as $line) {
            $allocated = $case->receiptAllocations->where('return_case_line_id', $line->id)
                ->reduce(fn ($sum, $row) => bcadd($sum, $row->quantity, 3), '0');
            $remaining[$line->id] = bcsub($this->required($line), $allocated, 3);
        }
        $plan = [];
        foreach ($receipts as $receipt) {
            foreach ($receipt->items->sortBy('id') as $item) {
                $available = bcsub((string) $item->quantity_accepted, (string) $used->get($item->id, '0'), 3);
                foreach ($case->lines->sortBy('id') as $line) {
                    if (! $this->matches($case, $line, $receipt, $item)) {
                        continue;
                    }
                    $quantity = bccomp($remaining[$line->id], $available, 3) < 0 ? $remaining[$line->id] : $available;
                    if (bccomp($quantity, '0', 3) <= 0) {
                        continue;
                    }
                    $plan[] = ['return_case_line_id' => $line->id, 'goods_receipt_note_id' => $receipt->id, 'grn_item_id' => $item->id, 'quantity' => $quantity];
                    $remaining[$line->id] = bcsub($remaining[$line->id], $quantity, 3);
                    $available = bcsub($available, $quantity, 3);
                }
            }
        }
        return $plan;
    }

    private function required(ReturnCaseLine $line): string
    {
        return bcadd((string) ($line->verified_missing_quantity ?? '0'), (string) ($line->verified_defective_quantity ?? '0'), 3);
    }
}
