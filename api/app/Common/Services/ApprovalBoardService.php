<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Models\ApprovalDelegation;
use App\Common\Support\ApprovalSourceScope;
use App\Common\Support\ApprovalTypeRegistry;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetTransfer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Services\OvertimeDecisionPolicy;
use App\Modules\Auth\Models\User;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Policies\LoanAccessPolicy;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Series F — Task F2. Approvals Kanban board.
 *
 * Reads `approval_records` for supported workflows and
 * buckets each
 * approvable into one of four columns from the current user's perspective:
 *
 *   - my_action       : an approval step is pending and the user's role
 *                       slug matches that step's role_slug
 *   - awaiting_others : an approval step is pending but a different role
 *                       must act
 *   - approved        : last terminal action was 'approved' within the
 *                       configured recent approval history window
 *   - rejected        : last terminal action was 'rejected' within the
 *                       configured recent approval history window
 *
 * The actual approve/reject mutations remain on the per-entity controllers
 * (leave, PR, PO, loan, payroll) that already enforce per-type permission
 * checks. This service is read-only.
 */
class ApprovalBoardService
{
    /** @return array<int, array{value:string,label:string}> */
    public function kindOptions(?User $user = null): array
    {
        return ApprovalTypeRegistry::kindOptions($user);
    }

    /**
     * @return array{
     *   my_action: array<int, array<string, mixed>>,
     *   awaiting_others: array<int, array<string, mixed>>,
     *   approved: array<int, array<string, mixed>>,
     *   rejected: array<int, array<string, mixed>>,
     *   summary: array<string, int>,
     *   meta: array<string, int|bool>,
     * }
     */
    public function board(
        User $user,
        ?string $kindFilter = null,
        int $pendingLimit = 100,
        int $historyLimit = 50,
        int $pendingPage = 1,
        int $historyPage = 1,
    ): array {
        return $this->boardPage($user, $kindFilter, $pendingLimit, $historyLimit, $pendingPage, $historyPage, true);
    }

    private function boardPage(
        User $user,
        ?string $kindFilter = null,
        int $pendingLimit = 100,
        int $historyLimit = 50,
        int $pendingPage = 1,
        int $historyPage = 1,
        bool $includeTotal = true,
    ): array {
        $pendingLimit = max(1, min($pendingLimit, 500));
        $historyLimit = max(1, min($historyLimit, 500));
        $pendingPage = max(1, $pendingPage);
        $historyPage = max(1, $historyPage);
        // Delegated slugs kept separately from the merged list: only a
        // DELEGATED step earns the masked fallback card below. A user's own
        // role being an out-of-scope step means the record is another
        // department's business (the module and the approve endpoint both
        // refuse it), so it must not appear at all — not even redacted.
        $delegatedSlugs = ApprovalDelegation::actsForRoles($user->id, now());
        $userRoleSlugs = $this->roleSlugsFor($user, $delegatedSlugs);

        // Pull pending approvals (open columns).
        $pendingTypeMeta = array_filter(
            ApprovalTypeRegistry::all(),
            static fn (array $meta): bool => $kindFilter === null || $meta['kind'] === $kindFilter,
        );
        $pendingKeys = DB::table('approval_records')
            ->select(['approvable_type', 'approvable_id'])
            ->where('action', 'pending')
            ->where('is_current', true)
            ->whereIn('approvable_type', array_keys($pendingTypeMeta))
            ->groupBy('approvable_type', 'approvable_id')
            ->orderBy('approvable_type')
            ->orderBy('approvable_id');
        $pendingKeyRows = $pendingKeys
            ->offset(($pendingPage - 1) * $pendingLimit)
            ->limit($pendingLimit + 1)
            ->get();
        $pendingTruncated = $pendingKeyRows->count() > $pendingLimit;
        $pendingIdsByType = $pendingKeyRows->take($pendingLimit)
            ->groupBy('approvable_type')
            ->map(static fn (Collection $rows): array => $rows->pluck('approvable_id')->map(static fn ($id): int => (int) $id)->all())
            ->all();
        $pending = collect();
        if ($pendingIdsByType !== []) {
            $pending = DB::table('approval_records')
                ->select(['id', 'approvable_type', 'approvable_id', 'step_order', 'role_slug', 'created_at'])
                ->where('action', 'pending')
                ->where('is_current', true)
                ->where(function ($query) use ($pendingIdsByType): void {
                    foreach ($pendingIdsByType as $type => $ids) {
                        $query->orWhere(function ($typed) use ($type, $ids): void {
                            $typed->where('approvable_type', $type)->whereIn('approvable_id', $ids);
                        });
                    }
                })
                ->orderBy('approvable_type')
                ->orderBy('approvable_id')
                ->orderBy('step_order')
                ->orderBy('id')
                ->get();
        }

        // Pull recently-actioned approvals (closed columns).
        // A resubmission can leave more than one terminal step for an
        // approvable. Fetch a bounded multiple before de-duplicating, then
        // cap the visible cards below.
        $actionedTruncated = false;
        $actioned = collect();
        if ($includeTotal) {
            $recentDays = app(SettingsService::class)->requiredInt('approvals.recent_history_days', 1, 3650);
            $historySince = Carbon::now()->subDays($recentDays);
            $actionedQuery = DB::table('approval_records')
                ->select(['id', 'approvable_type', 'approvable_id', 'step_order', 'role_slug', 'action', 'remarks', 'acted_at', 'approver_id'])
                ->whereIn('action', ['approved', 'rejected'])
                ->where('is_current', true)
                ->where('acted_at', '>=', $historySince)
                ->orderByDesc('acted_at');
            if ($kindFilter !== null) {
                $types = [];
                foreach (ApprovalTypeRegistry::all() as $class => $meta) {
                    if ($meta['kind'] === $kindFilter) {
                        $types[] = $class;
                    }
                }
                $actionedQuery->whereIn('approvable_type', $types);
            }
            $actionedRows = $actionedQuery
                ->offset(($historyPage - 1) * ($historyLimit * 4))
                ->limit(($historyLimit * 4) + 1)
                ->get();
            $actionedTruncated = $actionedRows->count() > ($historyLimit * 4);
            $actioned = $actionedRows->take($historyLimit * 4)->values();
        }

        // For each approvable, find the earliest pending step (the active step).
        $activeStepByApprovable = [];
        foreach ($pending as $row) {
            $key = $row->approvable_type.'#'.$row->approvable_id;
            if (! isset($activeStepByApprovable[$key])) {
                $activeStepByApprovable[$key] = $row;
            }
        }

        /*
         * Row-level visibility per approvable class, resolved ONCE for both
         * the open columns and the history columns below. A card may only
         * render a record the owning module's own list endpoint would show
         * this user (ApprovalSourceScope reuses each module's row scope
         * verbatim). Kinds without a row scope (payroll) fall back to the
         * classic permission gate.
         */
        $idsByClass = [];
        foreach ($activeStepByApprovable as $row) {
            $idsByClass[(string) $row->approvable_type][] = (int) $row->approvable_id;
        }
        foreach ($actioned as $row) {
            $idsByClass[(string) $row->approvable_type][] = (int) $row->approvable_id;
        }
        $visibleByClass = [];
        foreach ($idsByClass as $class => $ids) {
            $meta = ApprovalTypeRegistry::forClass($class);
            if ($meta === null) {
                continue;
            }
            if (! ApprovalSourceScope::hasScopeFor($class, $user)) {
                $visibleByClass[$class] = ApprovalTypeRegistry::userCanView($user, $meta)
                    ? array_fill_keys(array_values(array_unique($ids)), true)
                    : [];

                continue;
            }
            $visibleByClass[$class] = ApprovalSourceScope::visibleIds($class, $ids, $user);
        }

        // ADV4 — batch-load source rows for active steps so we can also
        // surface the requester's role on each card without N+1.
        $activePrefetch = [];
        $sourceIdsByTable = [];
        $creatorIds = [];
        foreach ($activeStepByApprovable as $key => $row) {
            $meta = ApprovalTypeRegistry::forClass((string) $row->approvable_type);
            if (! $meta) {
                continue;
            }
            $activePrefetch[$key] = ['row' => $row, 'meta' => $meta];
            $sourceIdsByTable[$meta['table']][] = (int) $row->approvable_id;
        }
        $sourcesByTable = [];
        foreach ($sourceIdsByTable as $table => $ids) {
            $sourcesByTable[$table] = DB::table($table)
                ->whereIn('id', array_values(array_unique($ids)))
                ->get()
                ->keyBy('id');
        }
        foreach ($activePrefetch as $key => $entry) {
            $meta = $entry['meta'];
            $source = $sourcesByTable[$meta['table']]
                ->get((int) $entry['row']->approvable_id);
            if (! $source) {
                unset($activePrefetch[$key]);

                continue;
            }
            $activePrefetch[$key]['source'] = $source;
            if (property_exists($source, 'created_by') && $source->created_by) {
                $creatorIds[] = (int) $source->created_by;
            }
        }
        $creators = $this->loadUsersWithRole($creatorIds);

        // ADV4 — batch-load approvers for actioned cards (one query, with role).
        $approverIds = $actioned->pluck('approver_id')->filter()->map(fn ($v) => (int) $v)->unique()->all();
        $approvers = $this->loadUsersWithRole($approverIds);
        $actionedSources = $this->loadSourcesForRows($actioned);

        $myAction = [];
        $awaitingOthers = [];

        foreach ($activePrefetch as $entry) {
            $meta = $entry['meta'];
            $class = (string) $entry['row']->approvable_type;
            $inScope = isset($visibleByClass[$class][(int) $entry['row']->approvable_id]);
            $isDelegatedStep = in_array($entry['row']->role_slug, $delegatedSlugs, true);
            // Full card: the module would show this user the record.
            // Masked card: a delegation put the user on this step, so they
            // must know work waits on them — but the record is outside their
            // module row scope, so no field of it is rendered.
            if (! $inScope && ! $isDelegatedStep) {
                continue;
            }
            $card = $this->cardForActive(
                $entry['row'],
                $meta,
                $entry['source'],
                $creators,
                redacted: ! $inScope,
            );
            if ($card === null) {
                continue;
            }
            if ($kindFilter !== null && $card['type'] !== $kindFilter) {
                continue;
            }

            if (in_array($entry['row']->role_slug, $userRoleSlugs, true)) {
                $myAction[] = $card;
            } else {
                $awaitingOthers[] = $card;
            }
        }

        if ($kindFilter === null || $kindFilter === 'payroll') {
            [$payrollAction, $payrollAwaitingOthers, $payrollHasMore] = $this->payrollCards($user, $pendingLimit, $pendingPage);
            array_push($myAction, ...$payrollAction);
            array_push($awaitingOthers, ...$payrollAwaitingOthers);
            $pendingTruncated = $pendingTruncated || $payrollHasMore;
        }

        [$customAction, $customAwaitingOthers, $customHasMore] = $this->customCards($user, $kindFilter, $pendingLimit, $pendingPage);
        array_push($myAction, ...$customAction);
        array_push($awaitingOthers, ...$customAwaitingOthers);
        $pendingTruncated = $pendingTruncated || $customHasMore;

        // Approved/Rejected: take the last action per approvable.
        $approved = [];
        $rejected = [];
        $seen = [];
        foreach ($actioned as $row) {
            $key = $row->approvable_type.'#'.$row->approvable_id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $meta = ApprovalTypeRegistry::forClass((string) $row->approvable_type);
            if ($meta === null) {
                continue;
            }
            // History obeys the same row scope as the open columns — an
            // out-of-scope record does not become readable once actioned.
            if (! isset($visibleByClass[(string) $row->approvable_type][(int) $row->approvable_id])) {
                continue;
            }
            $card = $this->cardForActioned($row, $meta, $actionedSources, $approvers);
            if ($card === null) {
                continue;
            }
            if ($kindFilter !== null && $card['type'] !== $kindFilter) {
                continue;
            }

            if ($row->action === 'approved') {
                $approved[] = $card;
            } else {
                $rejected[] = $card;
            }
        }

        // Sort my_action by oldest first (most urgent).
        usort($myAction, fn ($a, $b) => strcmp((string) $a['since'], (string) $b['since']));
        $myActionTotal = count($myAction);
        $pendingTruncated = $pendingTruncated
            || count($myAction) > $pendingLimit
            || count($awaitingOthers) > $pendingLimit;
        if ($includeTotal && ($pendingPage > 1 || $pendingTruncated)) {
            $myActionTotal = $this->countAllMyActions($user, $kindFilter);
        }
        $myAction = array_slice($myAction, 0, $pendingLimit);
        $awaitingOthers = array_slice($awaitingOthers, 0, $pendingLimit);

        $approved = array_slice($approved, 0, $historyLimit);
        $rejected = array_slice($rejected, 0, $historyLimit);

        return [
            'my_action' => $myAction,
            'awaiting_others' => $awaitingOthers,
            'approved' => $approved,
            'rejected' => $rejected,
            'summary' => [
                'my_action' => $myActionTotal,
                'awaiting_others' => count($awaitingOthers),
                'approved' => count($approved),
                'rejected' => count($rejected),
            ],
            'meta' => [
                'pending_limit' => $pendingLimit,
                'history_limit' => $historyLimit,
                'pending_page' => $pendingPage,
                'history_page' => $historyPage,
                'pending_truncated' => $pendingTruncated,
                'history_truncated' => $actionedTruncated,
                'pending_has_more' => $pendingTruncated,
                'history_has_more' => $actionedTruncated,
            ],
        ];
    }

    private function countAllMyActions(User $user, ?string $kindFilter): int
    {
        $total = 0;
        $page = 1;

        do {
            $board = $this->boardPage($user, $kindFilter, 500, 1, $page, 1, false);
            $total += $board['summary']['my_action'];
            $hasMore = $board['meta']['pending_has_more'];
            $page++;
        } while ($hasMore);

        return $total;
    }

    /** @param array<int, string> $delegatedSlugs @return array<int, string> */
    private function roleSlugsFor(User $user, array $delegatedSlugs): array
    {
        // U2/R1: a user has a single role_id. Return its slug as a list for
        // forward compat with multi-role assignment.
        $user->loadMissing('role');
        $slug = $user->role?->slug;
        $slugs = $slug ? [$slug] : [];

        return array_values(array_unique(array_merge($slugs, $delegatedSlugs)));
    }

    /**
     * Payroll periods use a maker-checker state machine instead of
     * approval_records. Keep its cards sourced from computed periods and apply
     * the same view, approve, and maker-exclusion rules as PayrollPeriodService.
     *
     * @return array{0:array<int, array<string, mixed>>,1:array<int, array<string, mixed>>,2:bool}
     */
    private function payrollCards(User $user, int $limit, int $page): array
    {
        if (! $user->hasPermission('payroll.periods.view')) {
            return [[], [], false];
        }

        $canApprove = ApprovalDelegation::canActForPermission($user, 'payroll.periods.approve');
        $mayApproveOwn = $canApprove && $user->hasPermission('payroll.periods.self_approve_override');
        $makerIds = $user->hasPermission('payroll.periods.approve')
            ? [(int) $user->id]
            : ApprovalDelegation::delegatorsForPermission($user, 'payroll.periods.approve')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $myAction = [];
        $hasMore = false;
        if ($canApprove) {
            $pending = DB::table('payroll_periods')
                ->where('status', PayrollPeriodStatus::Computed->value);
            if (! $mayApproveOwn) {
                $pending->where(function ($query) use ($makerIds): void {
                    $query->whereNull('created_by')->orWhereNotIn('created_by', $makerIds);
                })->where(function ($query) use ($makerIds): void {
                    $query->whereNull('computed_by')->orWhereNotIn('computed_by', $makerIds);
                });
            }

            $rows = $pending->orderBy('updated_at')
                ->offset(($page - 1) * $limit)
                ->limit($limit + 1)
                ->get(['id', 'period_start', 'period_end', 'created_by', 'computed_by', 'created_at', 'updated_at']);
            $hasMore = $rows->count() > $limit;
            $myAction = $rows->take($limit)->map(fn ($period): array => $this->payrollCard($period))->all();
        }
        $awaitingOthers = [];

        if (! $mayApproveOwn) {
            $awaitingOthers = DB::table('payroll_periods')
                ->where('status', PayrollPeriodStatus::Computed->value)
                ->where(function ($query) use ($user, $makerIds): void {
                    $query->where('created_by', $user->id)->orWhere('computed_by', $user->id);
                    if ($makerIds !== []) {
                        $query->orWhereIn('created_by', $makerIds)->orWhereIn('computed_by', $makerIds);
                    }
                })
                ->orderBy('updated_at')
                ->offset(($page - 1) * $limit)
                ->limit($limit)
                ->get(['id', 'period_start', 'period_end', 'created_by', 'computed_by', 'created_at', 'updated_at'])
                ->map(fn ($period): array => $this->payrollCard($period))
                ->all();
        }

        return [$myAction, $awaitingOthers, $hasMore];
    }

    /** @return array<string, mixed> */
    private function payrollCard(object $period): array
    {
        $hashId = app('hashids')->encode((int) $period->id);
        $since = $period->updated_at ?: $period->created_at;
        $created = $since ? Carbon::parse((string) $since) : Carbon::now();

        return [
            'id' => $hashId,
            'type' => 'payroll',
            'number' => Carbon::parse((string) $period->period_start)->toDateString()
                .' – '.Carbon::parse((string) $period->period_end)->toDateString(),
            'link' => '/payroll/periods/'.$hashId,
            'step_order' => 1,
            'role_slug' => 'payroll.periods.approve',
            'since' => $created->toIso8601String(),
            'age_hours' => (int) abs(Carbon::now()->diffInHours($created)),
            'amount' => null,
            'summary' => 'Computed payroll period waiting for checker review',
            'requester' => null,
        ];
    }

    /**
     * Custom approval state machines do not write approval_records. Read only
     * their module queues, preserving each list permission, row scope and maker guard.
     *
     * @return array{0:array<int, array<string, mixed>>,1:array<int, array<string, mixed>>,2:bool}
     */
    private function customCards(User $user, ?string $kindFilter, int $limit, int $page): array
    {
        $offset = ($page - 1) * $limit;
        $id = (int) $user->id;
        $myAction = [];
        $awaitingOthers = [];
        $hasMore = false;

        $selected = static fn (string $kind): bool => $kindFilter === null || $kindFilter === $kind;
        $makerOrAction = static function ($query, string $column, bool $canAct) use ($id): void {
            $query->where(function ($rows) use ($column, $canAct, $id): void {
                $rows->where($column, $id);
                if ($canAct) {
                    $rows->orWhere($column, '<>', $id)->orWhereNull($column);
                }
            });
        };
        $append = function (
            string $kind,
            string $label,
            Collection $rows,
            callable $isMaker,
            ?string $numberField,
            string $link,
            string $permission,
            string $summary,
            string $sinceField = 'created_at',
        ) use (&$myAction, &$awaitingOthers, &$hasMore, $user, $limit): void {
            $hasMore = $hasMore || $rows->count() > $limit;
            foreach ($rows->take($limit) as $row) {
                $isWaitingOnMaker = $isMaker($row);
                $hashId = app('hashids')->encode((int) $row->id);
                $since = $row->{$sinceField} ?? $row->updated_at ?? $row->created_at ?? now();
                $created = Carbon::parse((string) $since);
                $card = [
                    'id' => $hashId,
                    'type' => $kind,
                    'number' => $numberField && ! empty($row->{$numberField})
                        ? (string) $row->{$numberField}
                        : $label.' '.$hashId,
                    'link' => str_contains($link, '{id}') ? str_replace('{id}', $hashId, $link) : $link,
                    'step_order' => 1,
                    'role_slug' => $permission,
                    'since' => $created->toIso8601String(),
                    'age_hours' => (int) abs(Carbon::now()->diffInHours($created)),
                    'amount' => null,
                    'summary' => $summary,
                    'requester' => null,
                ];
                if ($isWaitingOnMaker) {
                    $awaitingOthers[] = $card;
                } elseif (ApprovalDelegation::canActForPermission($user, $permission, match ($kind) {
                    'overtime' => array_values(array_filter([(int) ($row->employee?->user?->id ?? 0)])),
                    'stock_adjustment' => [(int) $row->requested_by],
                    'stock_count_variance' => array_values(array_filter([(int) $row->counted_by, (int) $row->session_created_by])),
                    'payroll_adjustment' => [(int) $row->created_by],
                    'budget' => [(int) $row->submitted_by],
                    'budget_transfer' => [(int) $row->requested_by],
                    'invoice_prebill' => array_values(array_filter([(int) $row->created_by])),
                    'loan_write_off' => array_values(array_filter([(int) $row->write_off_requested_by])),
                    default => [],
                })) {
                    $myAction[] = $card;
                }
            }
        };

        if ($selected('overtime') && $user->hasPermission('attendance.view')) {
            $canAct = ApprovalDelegation::canActForPermission($user, 'attendance.ot.approve');
            $query = OvertimeRequest::query()
                ->with('employee.user')
                ->where('status', 'pending');
            app(OvertimeDecisionPolicy::class)->visibleTo($query, $user);
            $query->where(function ($rows) use ($id, $canAct): void {
                $rows->whereHas('employee.user', static fn ($users) => $users->where('users.id', $id));
                if ($canAct) {
                    $rows->orWhereDoesntHave('employee.user')
                        ->orWhereHas('employee.user', static fn ($users) => $users->where('users.id', '<>', $id));
                }
            });
            $rows = $query->orderBy('created_at')->offset($offset)->limit($limit + 1)->get();
            $append('overtime', 'Overtime', $rows,
                static fn ($row): bool => (int) ($row->employee?->user?->id ?? 0) === $id,
                'date', '/hr/attendance/overtime/{id}', 'attendance.ot.approve',
                'Overtime request waiting for review');
        }

        if ($selected('stock_adjustment') && ($user->hasPermission('inventory.adjust') || $user->hasPermission('inventory.adjust.approve'))) {
            $canAct = ApprovalDelegation::canActForPermission($user, 'inventory.adjust.approve');
            $query = DB::table('stock_adjustments')->where('status', 'pending')->whereNull('stock_movement_id');
            $makerOrAction($query, 'requested_by', $canAct);
            $rows = $query->orderBy('created_at')->offset($offset)->limit($limit + 1)->get();
            $append('stock_adjustment', 'Stock adjustment', $rows,
                static fn ($row): bool => (int) $row->requested_by === $id,
                null, '/inventory/stock-adjustments', 'inventory.adjust.approve',
                'Stock adjustment waiting for approval');
        }

        if ($selected('item_cost_review') && $user->hasPermission('inventory.costs.review') && ApprovalDelegation::canActForPermission($user, 'inventory.costs.approve')) {
            $rows = DB::table('item_cost_reviews')->where('status', 'pending')
                ->orderBy('created_at')->offset($offset)->limit($limit + 1)->get();
            $append('item_cost_review', 'Cost review', $rows, static fn (): bool => false,
                null, '/crm/price-agreements/cost-reviews', 'inventory.costs.approve',
                'Supplier cost review waiting for approval');
        }

        if ($selected('stock_count_variance') && $user->hasPermission('inventory.stock_count.view')) {
            $canAct = ApprovalDelegation::canActForPermission($user, 'inventory.stock_count.manage');
            $tolerance = app(SettingsService::class)->requiredFloat('inventory.stock_count.variance_tolerance_pct', 0);
            $query = DB::table('stock_count_items')
                ->join('stock_count_sessions', 'stock_count_sessions.id', '=', 'stock_count_items.session_id')
                ->where('stock_count_sessions.status', 'in_progress')
                ->where('stock_count_items.status', 'counted')
                ->whereRaw('ABS(stock_count_items.variance_percent) > ?', [$tolerance]);
            $query->where(function ($rows) use ($id, $canAct): void {
                $rows->where('stock_count_items.counted_by', $id)
                    ->orWhere('stock_count_sessions.created_by', $id);
                if ($canAct) {
                    $rows->orWhere(function ($other) use ($id): void {
                        $other->where(function ($notCounter) use ($id): void {
                            $notCounter->whereNull('stock_count_items.counted_by')->orWhere('stock_count_items.counted_by', '<>', $id);
                        })->where(function ($notCreator) use ($id): void {
                            $notCreator->whereNull('stock_count_sessions.created_by')->orWhere('stock_count_sessions.created_by', '<>', $id);
                        });
                    });
                }
            });
            $rows = $query->select([
                'stock_count_items.id', 'stock_count_items.counted_by', 'stock_count_items.created_at',
                'stock_count_sessions.created_by as session_created_by',
            ])->orderBy('stock_count_items.created_at')->offset($offset)->limit($limit + 1)->get();
            $append('stock_count_variance', 'Stock-count variance', $rows,
                static fn ($row): bool => (int) $row->counted_by === $id || (int) $row->session_created_by === $id,
                null, '/inventory/stock-count', 'inventory.stock_count.manage',
                'Stock-count variance waiting for approval');
        }

        if ($selected('payroll_adjustment') && $user->hasPermission('payroll.adjustments.view')) {
            $canAct = ApprovalDelegation::canActForPermission($user, 'payroll.adjustments.approve');
            $query = DB::table('payroll_adjustments')->where('status', 'pending');
            $makerOrAction($query, 'created_by', $canAct);
            $rows = $query->orderBy('created_at')->offset($offset)->limit($limit + 1)->get();
            $append('payroll_adjustment', 'Payroll adjustment', $rows,
                static fn ($row): bool => (int) $row->created_by === $id,
                null, '/payroll/adjustments', 'payroll.adjustments.approve',
                'Payroll adjustment waiting for approval');
        }

        if ($selected('budget') && ($user->hasPermission('budgeting.view') || ApprovalDelegation::canActForPermission($user, 'budgeting.approve'))) {
            $canAct = ApprovalDelegation::canActForPermission($user, 'budgeting.approve');
            $canView = $user->hasPermission('budgeting.view');
            $query = DB::table('budgets')->where('status', 'submitted')
                ->where(function ($rows) use ($id, $canView, $canAct, $user): void {
                    if ($canView) {
                        $rows->where('submitted_by', $id);
                    }
                    if ($canAct) {
                        $actionable = Budget::query()->awaitingApprovalFor($user)->select('budgets.id');
                        if ($canView) {
                            $rows->orWhereIn('budgets.id', $actionable);
                        } else {
                            $rows->whereIn('budgets.id', $actionable);
                        }
                    }
                });
            $rows = $query->orderBy('submitted_at')->offset($offset)->limit($limit + 1)->get();
            $append('budget', 'Budget', $rows,
                static fn ($row): bool => (int) $row->submitted_by === $id,
                'name', '/budgeting/{id}', 'budgeting.approve',
                'Submitted budget waiting for approval', 'submitted_at');
        }

        if ($selected('budget_transfer') && ($user->hasPermission('budgeting.view') || ApprovalDelegation::canActForPermission($user, 'budgeting.approve'))) {
            $canAct = ApprovalDelegation::canActForPermission($user, 'budgeting.approve');
            $canView = $user->hasPermission('budgeting.view');
            $query = DB::table('budget_transfers')->where('status', 'pending')
                ->where(function ($rows) use ($id, $canView, $canAct, $user): void {
                    if ($canView) {
                        $rows->where('requested_by', $id);
                    }
                    if ($canAct) {
                        $actionable = BudgetTransfer::query()->awaitingApprovalFor($user)->select('budget_transfers.id');
                        if ($canView) {
                            $rows->orWhereIn('budget_transfers.id', $actionable);
                        } else {
                            $rows->whereIn('budget_transfers.id', $actionable);
                        }
                    }
                });
            $rows = $query->orderBy('created_at')->offset($offset)->limit($limit + 1)->get();
            $append('budget_transfer', 'Budget transfer', $rows,
                static fn ($row): bool => (int) $row->requested_by === $id,
                'transfer_number', '/budgeting/transfers', 'budgeting.approve',
                'Budget transfer waiting for approval');
        }

        if ($selected('invoice_prebill') && $user->hasPermission('accounting.invoices.view')) {
            $canAct = ApprovalDelegation::canActForPermission($user, 'accounting.invoices.prebill_approve');
            $rows = DB::table('invoices')->where('status', 'draft')->where('lifecycle_type', 'prebill')
                ->whereNotNull('prebill_reason')->whereRaw("TRIM(prebill_reason) <> ''")
                ->whereNull('prebill_approved_by')->whereNull('prebill_approved_at')
                ->where(function ($query) use ($id, $canAct): void {
                    $query->where('created_by', $id);
                    if ($canAct) {
                        $query->orWhere('created_by', '<>', $id)->orWhereNull('created_by');
                    }
                })->orderBy('created_at')->get();
            $myInvoiceIds = $rows->filter(fn ($row): bool => (int) ($row->created_by ?? 0) !== $id)->pluck('id')->map(fn ($value): int => (int) $value)->all();
            $logsByInvoice = [];
            if ($canAct && $myInvoiceIds !== []) {
                $morph = (new Invoice)->getMorphClass();
                $logs = DB::table('audit_logs')->where('model_type', $morph)->where('action', 'updated')
                    ->whereIn('model_id', $myInvoiceIds)->orderBy('id')->get(['id', 'model_id', 'user_id', 'new_values']);
                foreach ($logs as $log) {
                    $changes = is_array($log->new_values) ? $log->new_values : (json_decode((string) $log->new_values, true) ?: []);
                    $invoiceId = (int) $log->model_id;
                    if (($changes['prebill_approved_by'] ?? null) !== null) {
                        $logsByInvoice[$invoiceId]['last_approval_id'] = (int) $log->id;
                    }
                    $logsByInvoice[$invoiceId]['updates'][] = $log;
                }
            }
            $eligible = $rows->filter(function ($row) use ($id, $canAct, $logsByInvoice): bool {
                if ((int) $row->created_by === $id || ! $canAct) {
                    return true;
                }
                $history = $logsByInvoice[(int) $row->id] ?? [];
                $lastApprovalId = (int) ($history['last_approval_id'] ?? 0);
                foreach ($history['updates'] ?? [] as $update) {
                    if ((int) $update->id > $lastApprovalId && (int) $update->user_id === $id) {
                        return false;
                    }
                }

                return true;
            })->values();
            $hasMore = $hasMore || $eligible->count() > $limit;
            $eligible = $eligible->slice($offset, $limit + 1)->values();
            $append('invoice_prebill', 'Invoice prebill', $eligible,
                static fn ($row): bool => (int) $row->created_by === $id,
                'invoice_number', '/accounting/invoices/{id}', 'accounting.invoices.prebill_approve',
                'Prebill invoice waiting for approval');
        }

        if ($selected('supplier_listing') && $user->hasPermission('purchasing.supplier_listings.view')) {
            $rows = DB::table('supplier_item_listings')->where('status', 'pending')
                ->orderBy('submitted_at')->offset($offset)->limit($limit + 1)->get();
            $append('supplier_listing', 'Supplier listing', $rows, static fn (): bool => false,
                'supplier_item_code', '/purchasing/supplier-listings', 'purchasing.supplier_listings.review',
                'Supplier item listing waiting for review', 'submitted_at');
        }

        if ($selected('loan_write_off') && $user->hasPermission('loans.view')) {
            $canAct = ApprovalDelegation::canActForPermission($user, 'loans.write_off.approve');
            $query = EmployeeLoan::query()->where('status', 'write_off_pending')
                ->whereNotNull('write_off_reason')->whereRaw("TRIM(write_off_reason) <> ''")
                ->whereNotNull('write_off_evidence')->whereRaw("TRIM(write_off_evidence) <> ''")
                ->where(function ($rows) use ($id, $canAct): void {
                    $rows->where('write_off_requested_by', $id);
                    if ($canAct) {
                        $rows->orWhere('write_off_requested_by', '<>', $id)->orWhereNull('write_off_requested_by');
                    }
                });
            app(LoanAccessPolicy::class)->visibleTo($query, $user);
            $rows = $query->orderBy('write_off_requested_at')->offset($offset)->limit($limit + 1)->get();
            $append('loan_write_off', 'Loan write-off', $rows,
                static fn ($row): bool => (int) $row->write_off_requested_by === $id,
                'loan_no', '/hr/loans/{id}', 'loans.write_off.approve',
                'Loan write-off waiting for approval', 'write_off_requested_at');
        }

        return [$myAction, $awaitingOthers, $hasMore];
    }

    /**
     * @param  object  $row  Active pending approval_records row.
     * @param  array<string, mixed>  $meta  Pre-resolved type metadata.
     * @param  object  $source  Pre-fetched approvable source row.
     * @param  Collection<int, User>  $creators  Pre-loaded users keyed by id.
     * @param  bool  $redacted  Mask module data when the record is outside the
     *                          caller's module row scope but their role (own or
     *                          delegated) is the pending step.
     * @return array<string, mixed>|null
     */
    private function cardForActive(object $row, array $meta, object $source, Collection $creators, bool $redacted = false): ?array
    {
        $hashId = app('hashids')->encode((int) $row->approvable_id);
        $number = $meta['number'] ? (string) ($source->{$meta['number']} ?? $hashId) : $hashId;
        $created = $row->created_at ? Carbon::parse((string) $row->created_at) : Carbon::now();

        // ADV4 — surface the requester (creator of the approvable) along with
        // their role chip when the underlying table has a `created_by` column.
        $requester = null;
        if (property_exists($source, 'created_by') && $source->created_by) {
            $u = $creators->get((int) $source->created_by);
            if ($u) {
                $requester = [
                    'name' => $u->name,
                    'role' => $u->role ? [
                        'name' => $u->role->name,
                        'slug' => $u->role->slug,
                    ] : null,
                ];
            }
        }

        if ($redacted) {
            return [
                'id' => $hashId,
                'type' => $meta['kind'],
                'number' => 'Restricted',
                'link' => '/approvals',
                'step_order' => (int) $row->step_order,
                'role_slug' => (string) $row->role_slug,
                'since' => $created->toIso8601String(),
                'age_hours' => (int) abs(Carbon::now()->diffInHours($created)),
                'amount' => null,
                'summary' => 'Approval assigned to your delegated role',
                'requester' => null,
            ];
        }

        return [
            'id' => $hashId,
            'type' => $meta['kind'],
            'number' => $number,
            'link' => $this->linkForSource((string) $row->approvable_type, $hashId, $source),
            'step_order' => (int) $row->step_order,
            'role_slug' => (string) $row->role_slug,
            'since' => $created->toIso8601String(),
            'age_hours' => (int) abs(Carbon::now()->diffInHours($created)),
            'amount' => $this->extractAmount($source),
            'summary' => $this->summaryFor($meta['kind'], $source),
            'requester' => $requester,
        ];
    }

    /**
     * @param  object  $row  Approved/rejected approval_records row.
     * @param  Collection<int, User>  $approvers  Pre-loaded users keyed by id.
     * @return array<string, mixed>|null
     */
    private function cardForActioned(object $row, array $meta, array $sources, Collection $approvers): ?array
    {
        $source = $sources[$row->approvable_type.'#'.$row->approvable_id] ?? null;
        if (! $source) {
            return null;
        }

        $hashId = app('hashids')->encode((int) $row->approvable_id);
        $number = $meta['number'] ? (string) ($source->{$meta['number']} ?? $hashId) : $hashId;

        // ADV4 — surface the approver actor with their role chip.
        $actor = null;
        if ($row->approver_id) {
            $u = $approvers->get((int) $row->approver_id);
            if ($u) {
                $actor = [
                    'id' => app('hashids')->encode((int) $row->approver_id),
                    'name' => $u->name,
                    'role' => $u->role ? [
                        'name' => $u->role->name,
                        'slug' => $u->role->slug,
                    ] : null,
                ];
            }
        }

        return [
            'id' => $hashId,
            'type' => $meta['kind'],
            'number' => $number,
            'link' => $this->linkForSource((string) $row->approvable_type, $hashId, $source),
            'action' => (string) $row->action,
            'acted_at' => $row->acted_at,
            'remarks' => (string) ($row->remarks ?? ''),
            'amount' => $this->extractAmount($source),
            'summary' => $this->summaryFor($meta['kind'], $source),
            'actor' => $actor,
        ];
    }

    /** @return array<string, object> */
    private function loadSourcesForRows(Collection $rows): array
    {
        $idsByTable = [];
        foreach ($rows as $row) {
            $meta = ApprovalTypeRegistry::forClass((string) $row->approvable_type);
            if ($meta === null) {
                continue;
            }
            $idsByTable[$meta['table']][] = (int) $row->approvable_id;
        }

        $sources = [];
        foreach (ApprovalTypeRegistry::all() as $class => $meta) {
            $ids = array_values(array_unique($idsByTable[$meta['table']] ?? []));
            if ($ids === []) {
                continue;
            }
            // Qualify the source projection before joins. An unqualified `*`
            // lets a vendor/customer join overwrite or omit the source `id`
            // on the stdClass, which becomes a warning and therefore an API
            // 500 when PHP's debug error handler is active.
            $query = DB::table($meta['table'])
                ->select($meta['table'].'.*')
                ->whereIn($meta['table'].'.id', $ids);
            // PS-07 — PO cards summarized the RAW vendor_id ("vendor #42"),
            // both a HashID-rule violation and a useless label. Carry the
            // vendor's name onto the source row so the summary reads a name.
            if ($meta['table'] === 'purchase_orders') {
                $query->leftJoin('vendors', 'vendors.id', '=', 'purchase_orders.vendor_id')
                    ->addSelect('vendors.name as vendor_name');
            }
            foreach ($query->get() as $source) {
                $sources[$class.'#'.$source->id] = $source;
            }
        }

        return $sources;
    }

    /**
     * ADV4 — batch-load Users (with their role) for a list of ids.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, User>
     */
    private function loadUsersWithRole(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) {
            return collect();
        }

        return User::with('role:id,name,slug')->whereIn('id', $ids)->get()->keyBy('id');
    }

    private function linkForSource(string $class, string $hashId, object $source): string
    {
        $meta = ApprovalTypeRegistry::forClass($class);
        if ($meta === null) {
            return '/approvals';
        }

        $parentId = isset($meta['link_parent'])
            ? ($source->{$meta['link_parent'].'_id'} ?? null)
            : null;
        $parentHashId = $parentId ? app('hashids')->encode((int) $parentId) : null;

        return ApprovalTypeRegistry::linkFor($class, $hashId, $parentHashId);
    }

    /**
     * PS-07 — the board card shows a vendor NAME, not the raw vendor_id.
     *
     * @param  object  $source  stdClass row from loadSourcesForRows (carries
     *                          vendor_name via the purchase_orders join when present)
     */
    private function poSummary(object $source): string
    {
        $vendorName = property_exists($source, 'vendor_name') && $source->vendor_name !== null
            ? (string) $source->vendor_name
            : null;

        return 'Purchase order'.($vendorName !== null ? ' — '.$vendorName : '');
    }

    private function extractAmount(object $source): ?string
    {
        // disposal_request_amount first: for an asset awaiting disposal
        // approval the executed disposal_amount is still null, and once the
        // JE has posted the proposal columns are cleared in the same save.
        foreach (['disposal_request_amount', 'total_amount', 'principal', 'amount', 'disposal_amount', 'refund_amount'] as $col) {
            if (property_exists($source, $col) && $source->{$col} !== null) {
                return (string) $source->{$col};
            }
        }

        return null;
    }

    private function summaryFor(string $kind, object $source): string
    {
        return match ($kind) {
            'po' => $this->poSummary($source),
            'leave' => 'Leave request — '.((string) ($source->start_date ?? '')).' to '.((string) ($source->end_date ?? '')),
            'pr' => 'Purchase request',
            'loan' => ucfirst((string) ($source->loan_type ?? 'loan')).' — '.app(CurrencyDisplayService::class)->format($source->principal ?? 0),
            'payroll' => 'Payroll period '.((string) ($source->period_start ?? '')).' to '.((string) ($source->period_end ?? '')),
            'salary_adjustment' => 'Salary adjustment — '.((string) ($source->effective_date ?? '')),
            'asset_disposal' => 'Disposal of '.((string) ($source->name ?? '')),
            'return_request' => ucfirst(str_replace('_', ' ', (string) ($source->type ?? 'return'))).' — '.((string) ($source->rma_number ?? '')),
            'bill_payment' => 'Bill payment request',
            default => '',
        };
    }
}
