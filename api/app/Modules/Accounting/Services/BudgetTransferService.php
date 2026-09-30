<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Support\Money;
use App\Modules\Accounting\Enums\BudgetTransferStatus;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLineItem;
use App\Modules\Accounting\Models\BudgetTransfer;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Line-to-line virement inside one fiscal year.
 *
 * Allocation lives in the monthly jan..dec buckets (annual_total is a
 * stored generated column), so a transfer names the month it moves in.
 * Header totals are derived from lines at read time
 * (BudgetConsumptionService::hydrate), so moving buckets is immediately
 * consistent everywhere; the stored header cache catches up on sync.
 *
 * Cap: the amount must fit the source line's annual headroom
 * (annual_total − actual_total) AND the source month bucket (which the
 * nonnegative-months check constraint would otherwise reject). actual_total
 * is sync-maintained, so headroom shares the KPI's sync drift.
 */
class BudgetTransferService
{
    /** @var array<int, string> */
    private const MONTHS = [
        'jan', 'feb', 'mar', 'apr', 'may', 'jun',
        'jul', 'aug', 'sep', 'oct', 'nov', 'dec',
    ];

    public function __construct(
        private readonly DocumentSequenceService $sequences,
    ) {}

    /**
     * @param  array{from_line_item_id:int,to_line_item_id:int,month:string,amount:string,reason:string}  $data
     */
    public function request(array $data, int $userId): BudgetTransfer
    {
        return DB::transaction(function () use ($data, $userId): BudgetTransfer {
            $month = strtolower((string) ($data['month'] ?? ''));
            if (! in_array($month, self::MONTHS, true)) {
                throw new BusinessRuleException('The transfer month must be one of jan..dec.');
            }
            $amount = (string) ($data['amount'] ?? '');
            if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount) || ! Money::gt($amount, '0')) {
                throw new BusinessRuleException('The transfer amount must be a positive decimal with at most two fractional digits.');
            }
            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '' || mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) {
                throw new BusinessRuleException('A transfer reason between 5 and 1000 characters is required.');
            }

            $fromId = (int) $data['from_line_item_id'];
            $toId = (int) $data['to_line_item_id'];
            if ($fromId === $toId) {
                throw new BusinessRuleException('A transfer needs two different budget lines.');
            }

            [$from, $to] = $this->lockedLines($fromId, $toId);
            $this->assertMovablePair($from, $to);
            $this->assertHeadroom($from, $month, $amount);

            return BudgetTransfer::create([
                'transfer_number' => $this->sequences->generate('budget_transfer'),
                'from_line_item_id' => $from->getKey(),
                'to_line_item_id' => $to->getKey(),
                'month' => $month,
                'amount' => Money::round2($amount),
                'reason' => $reason,
                'requested_by' => $userId,
            ])->load(['fromLine.account', 'fromLine.budget', 'toLine.account', 'toLine.budget', 'requester']);
        });
    }

    /** Approve a pending transfer and apply the bucket movement atomically. */
    public function approve(BudgetTransfer $transfer, int $userId): BudgetTransfer
    {
        return DB::transaction(function () use ($transfer, $userId): BudgetTransfer {
            $locked = BudgetTransfer::query()->lockForUpdate()->findOrFail($transfer->getKey());
            if ((string) $locked->status !== BudgetTransferStatus::Pending->value) {
                throw new BusinessRuleException('Only pending transfers can be approved.');
            }
            if ((int) $locked->requested_by === $userId) {
                throw new BusinessRuleException('The user who requested a transfer cannot approve it.');
            }
            // Same maker-checker-by-role rule as BudgetService::approve().
            $requester = User::query()->find($locked->requested_by);
            $approver = User::query()->findOrFail($userId);
            if ($requester !== null && (int) $requester->role_id === (int) $approver->role_id) {
                throw new BusinessRuleException('Transfer approval requires a different role than the requesting role.');
            }

            [$from, $to] = $this->lockedLines((int) $locked->from_line_item_id, (int) $locked->to_line_item_id);
            $this->assertMovablePair($from, $to);
            $this->assertHeadroom($from, (string) $locked->month, (string) $locked->amount);

            $month = (string) $locked->month;
            $amount = Money::round2((string) $locked->amount);
            $from->fill([$month => Money::sub((string) $from->{$month}, $amount)])->save();
            $to->fill([$month => Money::add((string) $to->{$month}, $amount)])->save();

            $locked->forceFill([
                'status' => BudgetTransferStatus::Approved->value,
                'approved_by' => $userId,
                'approved_at' => now(),
            ])->save();

            return $locked->fresh()->load(['fromLine.account', 'fromLine.budget', 'toLine.account', 'toLine.budget', 'requester', 'approver']);
        });
    }

    /** Reject a pending transfer; nothing moves. */
    public function reject(BudgetTransfer $transfer, int $userId): BudgetTransfer
    {
        return DB::transaction(function () use ($transfer, $userId): BudgetTransfer {
            $locked = BudgetTransfer::query()->lockForUpdate()->findOrFail($transfer->getKey());
            if ((string) $locked->status !== BudgetTransferStatus::Pending->value) {
                throw new BusinessRuleException('Only pending transfers can be rejected.');
            }

            $locked->forceFill([
                'status' => BudgetTransferStatus::Rejected->value,
                'approved_by' => $userId,
                'approved_at' => now(),
            ])->save();

            return $locked->fresh()->load(['fromLine.account', 'fromLine.budget', 'toLine.account', 'toLine.budget', 'requester', 'approver']);
        });
    }

    /**
     * @return array{0:BudgetLineItem,1:BudgetLineItem} locked in id order
     *         (from, to) so concurrent approvals cannot deadlock.
     */
    private function lockedLines(int $fromId, int $toId): array
    {
        $ordered = [$fromId, $toId];
        sort($ordered);
        $lines = BudgetLineItem::query()
            ->whereIn('id', $ordered)
            ->lockForUpdate()
            ->orderBy('id')
            ->get()
            ->keyBy('id');
        $from = $lines->get($fromId);
        $to = $lines->get($toId);
        if ($from === null || $to === null) {
            throw new BusinessRuleException('Every transfer line must reference an existing budget line item.');
        }

        return [$from, $to];
    }

    private function assertMovablePair(BudgetLineItem $from, BudgetLineItem $to): void
    {
        $fromBudget = Budget::query()->lockForUpdate()->findOrFail($from->budget_id);
        $toBudget = $from->budget_id === $to->budget_id
            ? $fromBudget
            : Budget::query()->lockForUpdate()->findOrFail($to->budget_id);

        foreach (['source' => $fromBudget, 'destination' => $toBudget] as $side => $budget) {
            if (! in_array((string) $budget->status, ['approved', 'active'], true)) {
                throw new BusinessRuleException("The {$side} budget is not live; transfers move live allocations only.");
            }
        }
        if ((int) $fromBudget->fiscal_year_id !== (int) $toBudget->fiscal_year_id) {
            throw new BusinessRuleException('A transfer must stay inside one fiscal year.');
        }
        if ((string) $fromBudget->budget_type !== (string) $toBudget->budget_type) {
            throw new BusinessRuleException('A transfer must stay inside one budget type.');
        }
    }

    private function assertHeadroom(BudgetLineItem $from, string $month, string $amount): void
    {
        $headroom = Money::sub((string) $from->annual_total, (string) $from->actual_total);
        if (! Money::lte($amount, $headroom)) {
            throw new BusinessRuleException('The transfer amount exceeds the source line annual headroom of '.$headroom.'.');
        }
        if (! Money::lte($amount, (string) $from->{$month})) {
            throw new BusinessRuleException('The transfer amount exceeds the source '.$month.' bucket of '.(string) $from->{$month}.'.');
        }
    }
}
