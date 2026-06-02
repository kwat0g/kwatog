<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Models\ApprovalRecord;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\ProfileUpdateRequest;
use App\Modules\Inventory\Models\Item;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderStatus;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\SupplyChain\Models\Delivery;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Polish Task S2 — Sidebar badge count system.
 *
 * Single source of truth for every numeric badge in the sidebar. Each entry is
 * a [permissions, counter] pair so a missing column / model never poisons the
 * whole payload — the failing key is just dropped from the response.
 *
 * Per-user cache TTL is short (30s) because counts back UI hints; the SPA
 * also polls every 60s and the WebSocket layer will eventually invalidate.
 */
class BadgeService
{
    /**
     * Build the per-slot badge map for the given user.
     *
     * Cached per-user for the configured TTL, keyed by a global version integer
     * so that a single call to touch() immediately invalidates every user's
     * cached map without needing to enumerate user IDs.
     *
     * @return array<string, array{count: int, severity: string}>
     */
    public function for(User $user): array
    {
        $ttl = (int) config('badges.cache_ttl', 30);
        $version = self::version();

        return Cache::remember(
            "badges.user.{$user->id}.v{$version}",
            $ttl,
            fn () => $this->compute($user),
        );
    }

    /** Current global cache version (defaults to 1). */
    public static function version(): int
    {
        return (int) Cache::get('badges.version', 1);
    }

    /**
     * Invalidate every user's cached badge map instantly by bumping the
     * global version. Called whenever badge-affecting data changes.
     */
    public static function touch(): void
    {
        if (Cache::has('badges.version')) {
            Cache::increment('badges.version');
        } else {
            Cache::forever('badges.version', 2); // 1 was the implicit default
        }
    }

    /** @return array<string, array{count: int, severity: string}> */
    private function compute(User $user): array
    {
        $out = [];
        foreach ($this->definitions($user) as $key => $def) {
            if (! $this->userHasAny($user, $def['permissions'])) {
                continue;
            }
            try {
                $count = (int) ($def['counter'])();
            } catch (Throwable $e) {
                Log::debug("BadgeService.{$key} skipped: {$e->getMessage()}");
                continue;
            }
            $out[$key] = ['count' => $count, 'severity' => $this->severity($count)];
        }
        return $out;
    }

    /**
     * @return array<string, array{permissions: array<int, string>, counter: Closure}>
     */
    private function definitions(User $user): array
    {
        $roleSlug = $user->role?->slug;

        return [
            // Overview > Approvals — every pending approval-record row routed to this role.
            'approvals' => [
                'permissions' => ['approvals.board.view'],
                'counter'     => fn (): int => $roleSlug === null ? 0
                    : ApprovalRecord::query()
                        ->where('action', 'pending')
                        ->where('role_slug', $roleSlug)
                        ->count(),
            ],

            // Procurement > Purchase requests — pending PRs routed to this role.
            'purchase_requests' => [
                'permissions' => ['purchasing.pr.approve'],
                'counter'     => fn (): int => $roleSlug === null ? 0
                    : ApprovalRecord::query()
                        ->where('approvable_type', (new PurchaseRequest)->getMorphClass())
                        ->where('action', 'pending')
                        ->where('role_slug', $roleSlug)
                        ->whereHas('approvable', fn ($q) => $q->where('status', PurchaseRequestStatus::Pending->value))
                        ->count(),
            ],

            // HR > Leave management — pending requests visible to this approver tier.
            'leaves' => [
                'permissions' => ['leave.approve_dept', 'leave.approve_hr'],
                'counter'     => function () use ($user): int {
                    $statuses = [];
                    if ($user->hasPermission('leave.approve_dept')) {
                        $statuses[] = LeaveRequestStatus::PendingDept->value;
                    }
                    if ($user->hasPermission('leave.approve_hr')) {
                        $statuses[] = LeaveRequestStatus::PendingHr->value;
                    }
                    if ($statuses === []) {
                        return 0;
                    }
                    return LeaveRequest::query()->whereIn('status', $statuses)->count();
                },
            ],

            // Payroll > Overtime — pending OT requests.
            'overtime' => [
                'permissions' => ['attendance.ot.approve'],
                'counter'     => fn (): int => OvertimeRequest::query()
                    ->where('status', 'pending')
                    ->count(),
            ],

            // Maintenance > Maintenance WOs — open + in-progress work orders.
            'maintenance_wo' => [
                'permissions' => ['maintenance.view'],
                'counter'     => fn (): int => MaintenanceWorkOrder::query()
                    ->whereIn('status', [
                        MaintenanceWorkOrderStatus::Open->value,
                        MaintenanceWorkOrderStatus::Assigned->value,
                        MaintenanceWorkOrderStatus::InProgress->value,
                    ])
                    ->count(),
            ],

            // Warehouse > Stock levels — items at/below reorder point.
            // Outer `reorder_point > 0` filter is intentional: items with no
            // configured reorder threshold (0) should never appear as low-stock.
            'low_stock' => [
                'permissions' => ['inventory.view'],
                'counter'     => fn (): int => Item::query()
                    ->where('reorder_point', '>', 0)
                    ->whereRaw('(SELECT COALESCE(SUM(quantity - reserved_quantity), 0) FROM stock_levels sl WHERE sl.item_id = items.id) <= items.reorder_point')
                    ->count(),
            ],

            // Quality > NCRs — open / not-yet-closed reports.
            'ncrs' => [
                'permissions' => ['quality.ncr.view'],
                'counter'     => fn (): int => NonConformanceReport::query()
                    ->whereNotIn('status', ['closed', 'cancelled'])
                    ->count(),
            ],

            // HR > Profile change requests — pending review queue.
            'profile_requests' => [
                'permissions' => ['hr.employees.view'],
                'counter'     => fn (): int => ProfileUpdateRequest::query()
                    ->where('status', 'pending')
                    ->count(),
            ],

            // Payroll > Periods awaiting HR/Finance action (draft or processing).
            // PayrollPeriodStatus has: draft, processing, approved, finalized, disbursed.
            // "Awaiting action" = draft (not yet submitted) + processing (computing / under review).
            'payroll' => [
                'permissions' => ['payroll.view'],
                'counter'     => fn (): int => PayrollPeriod::query()
                    ->whereIn('status', ['draft', 'processing'])
                    ->count(),
            ],

            // Production > Work orders — overdue (planned_end passed, not done).
            'work_orders' => [
                'permissions' => ['production.work_orders.view'],
                'counter'     => fn (): int => WorkOrder::query()
                    ->whereIn('status', ['confirmed', 'in_progress'])
                    ->whereNotNull('planned_end')
                    ->where('planned_end', '<', now())
                    ->count(),
            ],

            // Supply chain > Deliveries in transit needing an update.
            'deliveries' => [
                'permissions' => ['supply_chain.view'],
                'counter'     => fn (): int => Delivery::query()
                    ->whereIn('status', ['loading', 'in_transit'])
                    ->count(),
            ],
        ];
    }

    /** @param  array<int, string>  $permissions */
    private function userHasAny(User $user, array $permissions): bool
    {
        foreach ($permissions as $slug) {
            if ($user->hasPermission($slug)) {
                return true;
            }
        }
        return false;
    }

    private function severity(int $count): string
    {
        $danger  = (int) config('badges.severity.danger', 20);
        $warning = (int) config('badges.severity.warning', 0);

        if ($count > $danger)  return 'danger';
        if ($count > $warning) return 'warning';
        return 'neutral';
    }
}
