<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\BudgetLineItem;
use App\Modules\Accounting\Models\BudgetTransfer;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;

class BudgetTransferService
{
    /**
     * REC-02 — SoD override. The requester of a transfer may not approve it
     * unless they hold this permission (system_admin always does, since
     * hasPermission() short-circuits for admin). Withheld from finance_officer.
     */
    private const SELF_APPROVE_OVERRIDE_PERMISSION = 'budgeting.transfers.self_approve_override';
    /**
     * Request a budget transfer between line items.
     */
    public function request(int $fromLineId, int $toLineId, float $amount, string $reason, int $requestedBy): BudgetTransfer
    {
        return DB::transaction(function () use ($fromLineId, $toLineId, $amount, $reason, $requestedBy): BudgetTransfer {
            $fromLine = BudgetLineItem::findOrFail($fromLineId);
            $toLine   = BudgetLineItem::findOrFail($toLineId);

            // Calculate available amount in source line (annual_total - actual_total)
            $available = $fromLine->annual_total - $fromLine->actual_total;
            if ($amount > $available) {
                throw new \RuntimeException("Insufficient available budget in source line item. Available: {$available}, Requested: {$amount}");
            }

            return BudgetTransfer::create([
                'from_budget_line_id' => $fromLineId,
                'to_budget_line_id'   => $toLineId,
                'amount'              => $amount,
                'reason'              => $reason,
                'status'              => 'pending',
                'requested_by'        => $requestedBy,
            ]);
        });
    }

    /**
     * Approve a pending transfer — adjusts both line items.
     */
    public function approve(BudgetTransfer $transfer, int $approvedBy): BudgetTransfer
    {
        // REC-02 — maker-checker: the requester cannot approve their own transfer.
        $this->assertNotSelfApproving($transfer, $approvedBy);

        return DB::transaction(function () use ($transfer, $approvedBy): BudgetTransfer {
            if ($transfer->status !== 'pending') {
                throw new \RuntimeException('Transfer is not pending.');
            }

            $fromLine = $transfer->fromLineItem;
            $toLine   = $transfer->toLineItem;

            // Deduct from source by distributing across months proportionally.
            // Cast: `amount` is a decimal:2 cast (string) — adjustLineItemAmount wants float.
            $this->adjustLineItemAmount($fromLine, -(float) $transfer->amount);

            // Add to target
            $this->adjustLineItemAmount($toLine, (float) $transfer->amount);

            $transfer->update([
                'status'      => 'approved',
                'approved_by' => $approvedBy,
                'approved_at' => now(),
            ]);

            return $transfer->fresh();
        });
    }

    /**
     * Reject a pending transfer.
     */
    public function reject(BudgetTransfer $transfer): BudgetTransfer
    {
        $transfer->update(['status' => 'rejected']);
        return $transfer->fresh();
    }

    /**
     * REC-02 — block the requester from approving their own transfer.
     * A null requested_by (legacy) cannot trigger the guard.
     */
    private function assertNotSelfApproving(BudgetTransfer $transfer, int $approvedBy): void
    {
        if ($transfer->requested_by === null || (int) $transfer->requested_by !== $approvedBy) {
            return; // different approver, or unknown requester — allowed.
        }

        $actor = User::find($approvedBy);
        if ($actor && $actor->hasPermission(self::SELF_APPROVE_OVERRIDE_PERMISSION)) {
            return; // explicit override.
        }

        abort(403, 'You cannot approve a budget transfer you requested. A different user must approve it (segregation of duties).');
    }

    /**
     * Distribute an amount change across all 12 months proportionally.
     */
    private function adjustLineItemAmount(BudgetLineItem $line, float $delta): void
    {
        $total = $line->annual_total;
        if ($total <= 0) {
            // Spread evenly if no annual total yet
            $monthlyDelta = $delta / 12;
            foreach (['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'] as $col) {
                $line->increment($col, round($monthlyDelta, 2));
            }
        } else {
            foreach (['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'] as $col) {
                $proportion = $line->{$col} / $total;
                $line->increment($col, round($delta * $proportion, 2));
            }
        }
    }
}
