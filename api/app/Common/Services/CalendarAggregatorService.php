<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Support\DepartmentScope;
use App\Modules\HR\Models\Department;
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
    /** Layer key => one or more permission slugs required to read that layer. */
    private const LAYER_PERMISSIONS = [
        'holiday'     => [], // public to any authenticated user
        'leave'       => ['leave.view'],
        'delivery'    => ['supply_chain.view', 'supply_chain.deliveries.view'],
        'maintenance' => ['maintenance.view'],
        'payroll'     => ['payroll.periods.view'],
        'wo_due'      => ['production.work_orders.view'],
    ];

    /** Existing caps are retained, but every capped response now reports it. */
    private const LAYER_LIMITS = [
        'holiday'     => 500,
        'leave'       => 500,
        'delivery'    => 500,
        'maintenance' => 300,
        'payroll'     => 500,
        'wo_due'      => 500,
    ];

    /**
     * Return the calendar layers available to the current user. Keeping the
     * labels and layer colours at the API boundary prevents each client from
     * maintaining a second, potentially stale taxonomy.
     *
     * @return array<int, array{value: string, label: string, variant: string}>
     */
    public function layerOptions($user): array
    {
        $labels = [
            'holiday'     => 'Holidays',
            'leave'       => 'Leaves',
            'delivery'    => 'Deliveries',
            'maintenance' => 'Maintenance',
            'payroll'     => 'Payroll',
            'wo_due'      => 'WO due',
        ];
        $variants = [
            'holiday'     => 'info',
            'leave'       => 'neutral',
            'delivery'    => 'info',
            'maintenance' => 'warning',
            'payroll'     => 'success',
            'wo_due'      => 'warning',
        ];

        $options = [];
        foreach (self::LAYER_PERMISSIONS as $value => $permission) {
            if (! $this->hasAnyPermission($user, $permission)) {
                continue;
            }
            $options[] = [
                'value' => $value,
                'label' => $labels[$value] ?? $value,
                'variant' => $variants[$value] ?? 'neutral',
            ];
        }

        return $options;
    }

    /**
     * Department filters are exposed only to users whose leave visibility is
     * already department-aware. The options are encoded at the API boundary so
     * the calendar never invites a caller to submit a raw primary key.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function departmentOptions($user): array
    {
        if (! $this->hasAnyPermission($user, ['leave.view'])) {
            return [];
        }

        if ($this->userCan($user, 'leave.approve_hr')) {
            return Department::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Department $department): array => [
                    'value' => $department->hash_id,
                    'label' => (string) $department->name,
                ])
                ->values()
                ->all();
        }

        if ($this->userCan($user, 'leave.approve_dept')) {
            $departmentId = DepartmentScope::departmentIdFor($user);
            if ($departmentId === null) {
                return [];
            }

            return Department::query()
                ->whereKey($departmentId)
                ->where('is_active', true)
                ->get(['id', 'name'])
                ->map(fn (Department $department): array => [
                    'value' => $department->hash_id,
                    'label' => (string) $department->name,
                ])
                ->values()
                ->all();
        }

        return [];
    }

    public function canFilterDepartment($user, int $departmentId): bool
    {
        if (! $this->hasAnyPermission($user, ['leave.view'])) {
            return false;
        }
        if ($this->userCan($user, 'leave.approve_hr')) {
            return true;
        }
        if (! $this->userCan($user, 'leave.approve_dept')) {
            return false;
        }

        return DepartmentScope::departmentIdFor($user) === $departmentId;
    }

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
        return $this->eventsWithMeta($from, $to, $layers, $departmentId, $user)['events'];
    }

    /**
     * @return array{
     *     events: array<int, array<string, mixed>>,
     *     meta: array{layers: array<int, string>, layer_counts: array<string, array{returned: int, truncated: bool}}
     * }
     */
    public function eventsWithMeta(
        Carbon $from,
        Carbon $to,
        array $layers,
        ?int $departmentId,
        $user,
    ): array {
        $allowedLayers = $this->filterByPermission($layers, $user);
        $layerCounts = array_fill_keys($allowedLayers, ['returned' => 0, 'truncated' => false]);

        $events = [];
        if (in_array('holiday', $allowedLayers, true)) {
            array_push($events, ...$this->holidays($from, $to, $user, $layerCounts));
        }
        if (in_array('leave', $allowedLayers, true)) {
            array_push($events, ...$this->leaves($from, $to, $departmentId, $user, $layerCounts));
        }
        if (in_array('delivery', $allowedLayers, true)) {
            array_push($events, ...$this->deliveries($from, $to, $user, $layerCounts));
        }
        if (in_array('maintenance', $allowedLayers, true)) {
            array_push($events, ...$this->maintenance($from, $to, $user, $layerCounts));
        }
        if (in_array('payroll', $allowedLayers, true)) {
            array_push($events, ...$this->payroll($from, $to, $user, $layerCounts));
        }
        if (in_array('wo_due', $allowedLayers, true)) {
            array_push($events, ...$this->workOrders($from, $to, $user, $layerCounts));
        }

        $typeLabels = [
            'holiday' => 'Holiday',
            'leave' => 'Leave',
            'delivery' => 'Delivery',
            'maintenance' => 'Maintenance',
            'payroll' => 'Payroll',
            'wo_due' => 'Work order due',
        ];
        $events = array_map(static function (array $event) use ($typeLabels): array {
            $event['type_label'] = $typeLabels[$event['type']] ?? \Illuminate\Support\Str::headline((string) $event['type']);
            return $event;
        }, $events);

        usort($events, fn ($a, $b) => strcmp((string) $a['start'], (string) $b['start']));

        return [
            'events' => $events,
            'meta' => [
                'layers' => $allowedLayers,
                'layer_counts' => $layerCounts,
            ],
        ];
    }

    private function hash(int $id): string
    {
        return app('hashids')->encode($id);
    }

    /** @param array<int, string> $requested */
    private function filterByPermission(array $requested, $user): array
    {
        $out = [];
        foreach (array_values(array_unique($requested)) as $layer) {
            if (! array_key_exists($layer, self::LAYER_PERMISSIONS)) {
                continue;
            }
            $perm = self::LAYER_PERMISSIONS[$layer];
            if ($this->hasAnyPermission($user, $perm)) {
                $out[] = $layer;
            }
        }
        return $out;
    }

    /** @param array<int, string> $permissions */
    private function hasAnyPermission($user, array $permissions): bool
    {
        if ($permissions === [] || $user === null) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($this->userCan($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function userCan($user, string $permission): bool
    {
        if ($user === null) {
            return true;
        }

        if (method_exists($user, 'hasPermission')) {
            return (bool) $user->hasPermission($permission);
        }

        return (bool) $user->can($permission);
    }

    /** @param array<string, array{returned: int, truncated: bool}> $layerCounts */
    private function recordLayerCount(string $layer, $rows, array &$layerCounts)
    {
        $limit = self::LAYER_LIMITS[$layer];
        $total = $rows->count();
        $truncated = $total > $limit;
        $layerCounts[$layer] = [
            'returned' => min($total, $limit),
            'truncated' => $truncated,
        ];

        return $rows->take($limit);
    }

    /** @return array<int, array<string, mixed>> */
    private function holidays(Carbon $from, Carbon $to, $user, array &$layerCounts): array
    {
        $rows = DB::table('holidays')
            ->select(['id', 'name', 'date', 'type'])
            ->whereNull('deleted_at')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->limit(self::LAYER_LIMITS['holiday'] + 1)
            ->get();
        $rows = $this->recordLayerCount('holiday', $rows, $layerCounts);

        return $rows->map(fn ($r) => [
            'id'             => 'holiday-'.$this->hash((int) $r->id),
            'type'           => 'holiday',
            'title'          => (string) $r->name,
            'start'          => (string) $r->date,
            'end'            => (string) $r->date,
            'all_day'        => true,
            'color_variant'  => 'info',
            'link'           => $this->hasAnyPermission($user, ['attendance.edit', 'attendance.holidays.manage']) ? '/hr/attendance/holidays' : null,
            'meta'           => ['holiday_type' => (string) $r->type],
        ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function leaves(Carbon $from, Carbon $to, ?int $departmentId, $user, array &$layerCounts): array
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
            ->whereNull('lr.deleted_at')
            ->whereNull('e.deleted_at')
            ->where('lr.start_date', '<=', $to->toDateString())
            ->where('lr.end_date', '>=', $from->toDateString());

        $canViewAll = $this->userCan($user, 'leave.approve_hr');
        $canViewDepartment = $this->userCan($user, 'leave.approve_dept');
        $employeeId = $user?->employee_id ? (int) $user->employee_id : null;
        $scopeDepartmentId = $canViewDepartment ? DepartmentScope::departmentIdFor($user) : null;

        if ($canViewAll) {
            if ($departmentId !== null) {
                $q->where('e.department_id', $departmentId);
            }
        } else {
            $q->where(function ($scope) use ($departmentId, $scopeDepartmentId, $employeeId) {
                $matched = false;
                if ($scopeDepartmentId !== null && ($departmentId === null || $departmentId === $scopeDepartmentId)) {
                    $scope->where('e.department_id', $scopeDepartmentId);
                    $matched = true;
                } elseif ($departmentId !== null) {
                    // A department filter outside the actor's scope must never
                    // turn into an unfiltered query.
                    $scope->whereRaw('1 = 0');
                    $matched = true;
                }

                if ($employeeId !== null && ($departmentId === null || $departmentId === $scopeDepartmentId)) {
                    $matched
                        ? $scope->orWhere('lr.employee_id', $employeeId)
                        : $scope->where('lr.employee_id', $employeeId);
                    $matched = true;
                }

                if (! $matched) {
                    $scope->whereRaw('1 = 0');
                }
            });
        }

        $rows = $q->orderBy('lr.start_date')->limit(self::LAYER_LIMITS['leave'] + 1)->get();
        $rows = $this->recordLayerCount('leave', $rows, $layerCounts);

        return $rows->map(function ($r) use ($user, $canViewAll, $canViewDepartment) {
            $isOwnLeave = $user?->employee_id !== null && (int) $user->employee_id === (int) $r->eid;
            $displayName = ($canViewAll || $canViewDepartment || $isOwnLeave)
                ? trim((string) $r->first_name.' '.(string) $r->last_name)
                : 'My leave';

            return [
            'id'             => 'leave-'.$this->hash((int) $r->id),
            'type'           => 'leave',
            'title'          => $displayName.' — '.(string) ($r->leave_code ?? $r->leave_type ?? 'Leave'),
            'start'          => (string) $r->start_date,
            'end'            => (string) $r->end_date,
            'all_day'        => true,
            'color_variant'  => 'neutral',
            'link'           => $this->leaveLink((int) $r->id, (int) $r->eid, $user),
            'meta'           => [
                'employee_id'      => $this->hash((int) $r->eid),
                'leave_type'       => (string) ($r->leave_type ?? ''),
                'department_name'  => (string) ($r->department_name ?? ''),
            ],
            ];
        })->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function deliveries(Carbon $from, Carbon $to, $user, array &$layerCounts): array
    {
        $rows = DB::table('deliveries as dl')
            ->leftJoin('sales_orders as so', 'dl.sales_order_id', '=', 'so.id')
            ->leftJoin('customers as c', 'so.customer_id', '=', 'c.id')
            ->select(['dl.id', 'dl.delivery_number', 'dl.scheduled_date', 'dl.status', 'c.name as customer_name'])
            ->whereNull('dl.deleted_at')
            ->whereBetween('dl.scheduled_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('dl.scheduled_date')
            ->limit(self::LAYER_LIMITS['delivery'] + 1)
            ->get();
        $rows = $this->recordLayerCount('delivery', $rows, $layerCounts);

        return $rows->map(fn ($r) => [
            'id'             => 'delivery-'.$this->hash((int) $r->id),
            'type'           => 'delivery',
            'title'          => (string) $r->delivery_number.' — '.(string) ($r->customer_name ?? ''),
            'start'          => (string) $r->scheduled_date,
            'end'            => (string) $r->scheduled_date,
            'all_day'        => true,
            'color_variant'  => 'info',
            'link'           => $this->hasAnyPermission($user, ['supply_chain.view', 'supply_chain.deliveries.view'])
                ? '/supply-chain/deliveries/'.$this->hash((int) $r->id)
                : null,
            'meta'           => ['status' => (string) $r->status],
        ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function maintenance(Carbon $from, Carbon $to, $user, array &$layerCounts): array
    {
        $rows = DB::table('maintenance_work_orders')
            ->select(['id', 'description', 'priority', 'status', 'started_at', 'completed_at', 'created_at'])
            ->where(function ($q) use ($from, $to) {
                // Treat the effective start as started_at, falling back to
                // created_at for open records that have not started yet. An
                // interval is visible when it overlaps the requested window.
                $q->whereRaw('COALESCE(started_at, created_at) <= ?', [$to])
                  ->where(function ($end) use ($from) {
                      $end->whereNull('completed_at')
                          ->orWhere('completed_at', '>=', $from);
                  });
            })
            ->orderBy('started_at')
            ->limit(self::LAYER_LIMITS['maintenance'] + 1)
            ->get();
        $rows = $this->recordLayerCount('maintenance', $rows, $layerCounts);

        return $rows->map(function ($r) use ($user) {
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
                'link'           => $this->userCan($user, 'maintenance.view')
                    ? '/maintenance/work-orders/'.$this->hash((int) $r->id)
                    : null,
                'meta'           => ['priority' => (string) $r->priority, 'status' => (string) $r->status],
            ];
        })->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function payroll(Carbon $from, Carbon $to, $user, array &$layerCounts): array
    {
        $rows = DB::table('payroll_periods')
            ->select(['id', 'period_start', 'period_end', 'payroll_date', 'status', 'is_first_half'])
            ->whereBetween('payroll_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('payroll_date')
            ->limit(self::LAYER_LIMITS['payroll'] + 1)
            ->get();
        $rows = $this->recordLayerCount('payroll', $rows, $layerCounts);

        return $rows->map(fn ($r) => [
            'id'             => 'payroll-'.$this->hash((int) $r->id),
            'type'           => 'payroll',
            'title'          => 'Payroll cutoff — '.(string) $r->period_start.' to '.(string) $r->period_end,
            'start'          => (string) $r->payroll_date,
            'end'            => (string) $r->payroll_date,
            'all_day'        => true,
            'color_variant'  => 'success',
            'link'           => $this->userCan($user, 'payroll.periods.view')
                ? '/payroll/periods/'.$this->hash((int) $r->id)
                : null,
            'meta'           => ['status' => (string) $r->status, 'is_first_half' => (bool) $r->is_first_half],
        ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function workOrders(Carbon $from, Carbon $to, $user, array &$layerCounts): array
    {
        $rows = DB::table('work_orders as wo')
            ->leftJoin('products as p', 'wo.product_id', '=', 'p.id')
            ->select(['wo.id', 'wo.wo_number', 'wo.planned_end', 'wo.status', 'p.name as product_name'])
            ->whereNull('wo.deleted_at')
            ->whereBetween('wo.planned_end', [$from, $to])
            ->orderBy('wo.planned_end')
            ->limit(self::LAYER_LIMITS['wo_due'] + 1)
            ->get();
        $rows = $this->recordLayerCount('wo_due', $rows, $layerCounts);

        return $rows->map(fn ($r) => [
            'id'             => 'wo-'.$this->hash((int) $r->id),
            'type'           => 'wo_due',
            'title'          => (string) $r->wo_number.' — '.(string) ($r->product_name ?? ''),
            'start'          => substr((string) $r->planned_end, 0, 10),
            'end'            => substr((string) $r->planned_end, 0, 10),
            'all_day'        => true,
            'color_variant'  => 'warning',
            'link'           => $this->userCan($user, 'production.work_orders.view')
                ? '/production/work-orders/'.$this->hash((int) $r->id)
                : null,
            'meta'           => ['status' => (string) $r->status],
        ])->all();
    }

    private function leaveLink(int $leaveId, int $employeeId, $user): ?string
    {
        if ($this->hasAnyPermission($user, ['leave.approve_dept', 'leave.approve_hr'])) {
            return '/hr/leaves/'.$this->hash($leaveId);
        }

        if ($user?->employee_id !== null && (int) $user->employee_id === $employeeId) {
            return '/self-service/leave';
        }

        return null;
    }
}
