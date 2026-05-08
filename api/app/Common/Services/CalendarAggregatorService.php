<?php

declare(strict_types=1);

namespace App\Common\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Series F — Task F1. Cross-module calendar aggregator.
 *
 * Reads existing tables (holidays, leave_requests, deliveries,
 * maintenance_work_orders, payroll_periods, work_orders) and emits a
 * normalized list of CalendarEvent rows for the SPA. Per-layer
 * permission filtering is applied here — a user without the
 * appropriate permission does NOT receive that layer's events even if
 * they request it.
 *
 * Why direct DB queries: this is a read-only aggregator hit by an
 * interactive UI. Eloquent hydration would be wasteful — we only need
 * a handful of columns per row and no relations beyond names already
 * embedded via subqueries.
 */
class CalendarAggregatorService
{
    /** Maximum days that may be requested in a single call. */
    public const MAX_RANGE_DAYS = 90;

    /** Layer key => permission slug required to read that layer. */
    private const LAYER_PERMISSIONS = [
        'holiday'     => null, // public to any authenticated user
        'leave'       => 'leave.view',
        'delivery'    => 'supply_chain.view',
        'maintenance' => 'maintenance.view',
        'payroll'     => 'payroll.view',
        'wo_due'      => 'production.view',
    ];

    /**
     * @param  array<int, string>  $layers   Layer keys to fetch (intersection with permissions).
     * @return array<int, array<string, mixed>>
     */
    public function events(
        Carbon $from,
        Carbon $to,
        array $layers,
        ?int $departmentId,
        $user,
    ): array {
        $allowedLayers = $this->filterByPermission($layers, $user);

        $events = [];
        if (in_array('holiday', $allowedLayers, true)) {
            array_push($events, ...$this->holidays($from, $to));
        }
        if (in_array('leave', $allowedLayers, true)) {
            array_push($events, ...$this->leaves($from, $to, $departmentId));
        }
        if (in_array('delivery', $allowedLayers, true)) {
            array_push($events, ...$this->deliveries($from, $to));
        }
        if (in_array('maintenance', $allowedLayers, true)) {
            array_push($events, ...$this->maintenance($from, $to));
        }
        if (in_array('payroll', $allowedLayers, true)) {
            array_push($events, ...$this->payroll($from, $to));
        }
        if (in_array('wo_due', $allowedLayers, true)) {
            array_push($events, ...$this->workOrders($from, $to));
        }

        usort($events, fn ($a, $b) => strcmp((string) $a['start'], (string) $b['start']));

        return $events;
    }

    private function hash(int $id): string
    {
        return app('hashids')->encode($id);
    }

    /** @param array<int, string> $requested */
    private function filterByPermission(array $requested, $user): array
    {
        $out = [];
        foreach ($requested as $layer) {
            if (! array_key_exists($layer, self::LAYER_PERMISSIONS)) {
                continue;
            }
            $perm = self::LAYER_PERMISSIONS[$layer];
            if ($perm === null || ($user && $user->can($perm))) {
                $out[] = $layer;
            }
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function holidays(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('holidays')
            ->select(['id', 'name', 'date', 'type'])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get();

        return $rows->map(fn ($r) => [
            'id'             => 'holiday-'.$r->id,
            'type'           => 'holiday',
            'title'          => (string) $r->name,
            'start'          => (string) $r->date,
            'end'            => (string) $r->date,
            'all_day'        => true,
            'color_variant'  => 'info',
            'link'           => '/hr/attendance/holidays',
            'meta'           => ['holiday_type' => (string) $r->type],
        ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function leaves(Carbon $from, Carbon $to, ?int $departmentId): array
    {
        $q = DB::table('leave_requests as lr')
            ->join('employees as e', 'lr.employee_id', '=', 'e.id')
            ->leftJoin('leave_types as lt', 'lr.leave_type_id', '=', 'lt.id')
            ->leftJoin('departments as d', 'e.department_id', '=', 'd.id')
            ->select([
                'lr.id', 'lr.start_date', 'lr.end_date',
                'e.id as eid', 'e.first_name', 'e.last_name',
                'lt.name as leave_type', 'lt.code as leave_code',
                'd.name as department_name',
            ])
            ->where('lr.status', 'approved')
            ->where('lr.start_date', '<=', $to->toDateString())
            ->where('lr.end_date', '>=', $from->toDateString());

        if ($departmentId !== null) {
            $q->where('e.department_id', $departmentId);
        }

        return $q->orderBy('lr.start_date')->limit(500)->get()->map(fn ($r) => [
            'id'             => 'leave-'.$this->hash((int) $r->id),
            'type'           => 'leave',
            'title'          => trim((string) $r->first_name.' '.(string) $r->last_name).' — '.(string) ($r->leave_code ?? $r->leave_type ?? 'Leave'),
            'start'          => (string) $r->start_date,
            'end'            => (string) $r->end_date,
            'all_day'        => true,
            'color_variant'  => 'neutral',
            'link'           => '/hr/leaves/'.$this->hash((int) $r->id),
            'meta'           => [
                'employee_id'      => $this->hash((int) $r->eid),
                'leave_type'       => (string) ($r->leave_type ?? ''),
                'department_name'  => (string) ($r->department_name ?? ''),
            ],
        ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function deliveries(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('deliveries as dl')
            ->leftJoin('customers as c', 'dl.customer_id', '=', 'c.id')
            ->select(['dl.id', 'dl.delivery_note_number', 'dl.scheduled_date', 'dl.status', 'c.name as customer_name'])
            ->whereBetween('dl.scheduled_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('dl.scheduled_date')
            ->limit(500)
            ->get();

        return $rows->map(fn ($r) => [
            'id'             => 'delivery-'.$this->hash((int) $r->id),
            'type'           => 'delivery',
            'title'          => (string) $r->delivery_note_number.' — '.(string) ($r->customer_name ?? ''),
            'start'          => (string) $r->scheduled_date,
            'end'            => (string) $r->scheduled_date,
            'all_day'        => true,
            'color_variant'  => 'info',
            'link'           => '/supply-chain/deliveries/'.$this->hash((int) $r->id),
            'meta'           => ['status' => (string) $r->status],
        ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function maintenance(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('maintenance_work_orders')
            ->select(['id', 'description', 'priority', 'status', 'started_at', 'completed_at', 'created_at'])
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('started_at', [$from, $to])
                  ->orWhereBetween('completed_at', [$from, $to])
                  ->orWhere(function ($q2) use ($from, $to) {
                      $q2->whereNull('completed_at')
                         ->whereBetween('created_at', [$from, $to]);
                  });
            })
            ->orderBy('started_at')
            ->limit(300)
            ->get();

        return $rows->map(function ($r) {
            $start = (string) ($r->started_at ?? $r->created_at);
            $end   = (string) ($r->completed_at ?? $r->started_at ?? $r->created_at);
            return [
                'id'             => 'maint-'.$this->hash((int) $r->id),
                'type'           => 'maintenance',
                'title'          => mb_strimwidth((string) $r->description, 0, 60, '…'),
                'start'          => substr($start, 0, 10),
                'end'            => substr($end, 0, 10),
                'all_day'        => true,
                'color_variant'  => 'warning',
                'link'           => '/maintenance/work-orders/'.$this->hash((int) $r->id),
                'meta'           => ['priority' => (string) $r->priority, 'status' => (string) $r->status],
            ];
        })->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function payroll(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('payroll_periods')
            ->select(['id', 'period_start', 'period_end', 'payroll_date', 'status', 'is_first_half'])
            ->whereBetween('payroll_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('payroll_date')
            ->get();

        return $rows->map(fn ($r) => [
            'id'             => 'payroll-'.$this->hash((int) $r->id),
            'type'           => 'payroll',
            'title'          => 'Payroll cutoff — '.(string) $r->period_start.' to '.(string) $r->period_end,
            'start'          => (string) $r->payroll_date,
            'end'            => (string) $r->payroll_date,
            'all_day'        => true,
            'color_variant'  => 'success',
            'link'           => '/payroll/periods/'.$this->hash((int) $r->id),
            'meta'           => ['status' => (string) $r->status, 'is_first_half' => (bool) $r->is_first_half],
        ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function workOrders(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('work_orders as wo')
            ->leftJoin('products as p', 'wo.product_id', '=', 'p.id')
            ->select(['wo.id', 'wo.wo_number', 'wo.planned_end', 'wo.status', 'p.name as product_name'])
            ->whereBetween('wo.planned_end', [$from, $to])
            ->orderBy('wo.planned_end')
            ->limit(500)
            ->get();

        return $rows->map(fn ($r) => [
            'id'             => 'wo-'.$this->hash((int) $r->id),
            'type'           => 'wo_due',
            'title'          => (string) $r->wo_number.' — '.(string) ($r->product_name ?? ''),
            'start'          => substr((string) $r->planned_end, 0, 10),
            'end'            => substr((string) $r->planned_end, 0, 10),
            'all_day'        => true,
            'color_variant'  => 'warning',
            'link'           => '/production/work-orders/'.$this->hash((int) $r->id),
            'meta'           => ['status' => (string) $r->status],
        ])->all();
    }
}
