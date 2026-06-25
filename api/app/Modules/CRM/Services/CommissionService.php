<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\CommissionStatus;
use App\Modules\CRM\Models\CommissionEarning;
use App\Modules\CRM\Models\CommissionRate;
use App\Modules\CRM\Models\SalesOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CommissionService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = CommissionEarning::query()
            ->with(['employee:id,first_name,last_name', 'salesOrder:id,so_number']);

        if (! empty($filters['employee_id'])) {
            $q->where('employee_id', $filters['employee_id']);
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['period_start'])) {
            $q->where('period_start', '>=', $filters['period_start']);
        }
        if (! empty($filters['period_end'])) {
            $q->where('period_end', '<=', $filters['period_end']);
        }

        return $q->orderByDesc('created_at')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function calculateForOrder(SalesOrder $so): ?CommissionEarning
    {
        if (! $so->sales_rep_id) {
            return null;
        }

        $date = $so->date?->toDateString() ?? now()->toDateString();

        $rate = CommissionRate::query()
            ->where('employee_id', $so->sales_rep_id)
            ->activeOn($date)
            ->orderByRaw('product_id IS NULL ASC')
            ->first();

        if (! $rate) {
            return null;
        }

        $total = (float) $so->total_amount;
        $amount = round($total * (float) $rate->rate, 2);

        return DB::transaction(function () use ($so, $rate, $total, $amount) {
            $earning = new CommissionEarning();
            $earning->fill([
                'sales_order_id'  => $so->id,
                'employee_id'     => $so->sales_rep_id,
                'order_total'     => number_format($total, 2, '.', ''),
                'commission_rate' => $rate->rate,
                'commission_amount' => number_format($amount, 2, '.', ''),
            ]);
            $earning->status = CommissionStatus::Pending;
            $earning->save();

            return $earning;
        });
    }

    public function approve(CommissionEarning $earning, User $by): CommissionEarning
    {
        $beneficiaryUserId = \App\Modules\Auth\Models\User::where('employee_id', $earning->employee_id)->value('id');
        if ($beneficiaryUserId && (int) $beneficiaryUserId === $by->id) {
            throw new \RuntimeException('Cannot approve your own commission earning.');
        }

        return DB::transaction(function () use ($earning, $by) {
            $earning->forceFill([
                'status'      => CommissionStatus::Approved->value,
                'approved_by' => $by->id,
                'approved_at' => now(),
            ])->save();

            return $earning->fresh();
        });
    }

    public function markPaid(array $earningIds, User $by): int
    {
        return DB::transaction(function () use ($earningIds, $by) {
            return CommissionEarning::query()
                ->whereIn('id', $earningIds)
                ->where('status', CommissionStatus::Approved->value)
                ->update([
                    'status'  => CommissionStatus::Paid->value,
                    'paid_at' => now(),
                ]);
        });
    }

    public function ratesList(array $filters): LengthAwarePaginator
    {
        $q = CommissionRate::query()
            ->with(['employee:id,first_name,last_name', 'product:id,code,name']);

        if (! empty($filters['employee_id'])) {
            $q->where('employee_id', $filters['employee_id']);
        }

        return $q->orderByDesc('effective_from')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function setRate(array $data): CommissionRate
    {
        return DB::transaction(function () use ($data) {
            return CommissionRate::create($data);
        });
    }
}
