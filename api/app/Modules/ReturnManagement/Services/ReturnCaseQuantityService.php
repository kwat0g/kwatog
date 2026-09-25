<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Services;

use App\Modules\ReturnManagement\Models\ReturnCaseLine;
use App\Modules\ReturnManagement\Models\ReturnRequestSourceAllocation;

/** Claims and physical RMAs share a quantity budget, without counting their handoff twice. */
class ReturnCaseQuantityService
{
    public function reserved(array $deliveryLineIds = [], array $grnLineIds = [], ?int $exceptCase = null): string
    {
        $lines = ReturnCaseLine::query()->with('returnCase.returnRequest')
            ->where(fn ($q) => $q->whereIn('source_delivery_item_id', $deliveryLineIds)->orWhereIn('source_grn_item_id', $grnLineIds))
            ->when($exceptCase, fn ($q) => $q->where('return_case_id', '!=', $exceptCase))
            ->whereHas('returnCase', fn ($q) => $q->where('status', '!=', 'withdrawn'))->get();
        $total = '0.000';
        foreach ($lines as $line) {
            $case = $line->returnCase;
            $missing = (string) ($line->verified_missing_quantity ?? $line->missing_quantity);
            $defective = (string) ($line->verified_defective_quantity ?? $line->defective_quantity);
            $allocated = '0';
            if ($case->returnRequest && ! in_array($case->returnRequest->status->value, ['rejected', 'cancelled'], true)) {
                $allocated = (string) ReturnRequestSourceAllocation::query()
                    ->whereHas('returnRequestItem', fn ($q) => $q->where('return_request_id', $case->return_request_id))
                    ->where('source_kind', $line->source_delivery_item_id ? 'delivery_item' : 'grn_item')
                    ->where('source_id', $line->source_delivery_item_id ?: $line->source_grn_item_id)
                    ->whereNull('released_at')->sum('quantity');
            }
            $unallocated = bcsub($defective, $allocated, 3);
            $total = bcadd($total, bcadd($line->source_delivery_item_id ? $missing : '0', bccomp($unallocated, '0', 3) > 0 ? $unallocated : '0', 3), 3);
        }
        return $total;
    }

    public function outstandingShortage(int $poLineId): string
    {
        $lines = ReturnCaseLine::query()->where('source_po_item_id', $poLineId)
            ->whereHas('returnCase', fn ($q) => $q->whereNotIn('status', ['withdrawn', 'resolved']))
            ->withSum('receiptAllocations', 'quantity')->get();
        return $lines->reduce(function (string $sum, ReturnCaseLine $line): string {
            // Accepted redeliveries fulfill the shortage first; a still-open
            // physical return must not reserve quantities already delivered.
            $remaining = bcsub((string) ($line->verified_missing_quantity ?? $line->missing_quantity), (string) ($line->receipt_allocations_sum_quantity ?? '0'), 3);
            return bcadd($sum, bccomp($remaining, '0', 3) > 0 ? $remaining : '0', 3);
        }, '0.000');
    }
}
