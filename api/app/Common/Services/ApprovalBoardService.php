<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Models\ApprovalDelegation;
use App\Common\Support\ApprovalSourceScope;
use App\Common\Support\ApprovalTypeRegistry;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Series F — Task F2. Approvals Kanban board.
 *
 * Reads `approval_records` (polymorphic to leave_requests, purchase_requests,
 * purchase_orders, employee_loans, payroll_periods, return_requests) and
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
    public function kindOptions(): array
    {
        return ApprovalTypeRegistry::kindOptions();
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
    public function board(User $user, ?string $kindFilter = null, int $pendingLimit = 100, int $historyLimit = 50): array
    {
        $pendingLimit = max(1, min($pendingLimit, 500));
        $historyLimit = max(1, min($historyLimit, 500));
        // Delegated slugs kept separately from the merged list: only a
        // DELEGATED step earns the masked fallback card below. A user's own
        // role being an out-of-scope step means the record is another
        // department's business (the module and the approve endpoint both
        // refuse it), so it must not appear at all — not even redacted.
        $delegatedSlugs = ApprovalDelegation::actsForRoles($user->id, now());
        $userRoleSlugs = $this->roleSlugsFor($user, $delegatedSlugs);
        $recentDays = app(SettingsService::class)->requiredInt('approvals.recent_history_days', 1, 3650);
        $historySince = Carbon::now()->subDays($recentDays);

        // Pull pending approvals (open columns).
        $pendingQuery = DB::table('approval_records')
            ->select(['id', 'approvable_type', 'approvable_id', 'step_order', 'role_slug', 'created_at'])
            ->where('action', 'pending')
            ->where('is_current', true)
            ->orderBy('approvable_type')
            ->orderBy('approvable_id')
            ->orderBy('step_order');

        if ($kindFilter !== null) {
            $types = [];
            foreach (ApprovalTypeRegistry::all() as $class => $meta) {
                if ($meta['kind'] === $kindFilter) {
                    $types[] = $class;
                }
            }
            $pendingQuery->whereIn('approvable_type', $types);
        }

        $pendingRows = $pendingQuery->limit($pendingLimit + 1)->get();
        $pendingTruncated = $pendingRows->count() > $pendingLimit;
        $pending = $pendingRows->take($pendingLimit)->values();

        // Pull recently-actioned approvals (closed columns).
        // A resubmission can leave more than one terminal step for an
        // approvable. Fetch a bounded multiple before de-duplicating, then
        // cap the visible cards below.
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
            ->limit(($historyLimit * 4) + 1)
            ->get();
        $actionedTruncated = $actionedRows->count() > ($historyLimit * 4);
        $actioned = $actionedRows->take($historyLimit * 4)->values();

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
            if (! ApprovalSourceScope::hasScope($class)) {
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
            if (! $meta) continue;
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
            if ($card === null) continue;
            if ($kindFilter !== null && $card['type'] !== $kindFilter) continue;

            if (in_array($entry['row']->role_slug, $userRoleSlugs, true)) {
                $myAction[] = $card;
            } else {
                $awaitingOthers[] = $card;
            }
        }

        // Approved/Rejected: take the last action per approvable.
        $approved = [];
        $rejected = [];
        $seen = [];
        foreach ($actioned as $row) {
            $key = $row->approvable_type.'#'.$row->approvable_id;
            if (isset($seen[$key])) continue;
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
            if ($card === null) continue;
            if ($kindFilter !== null && $card['type'] !== $kindFilter) continue;

            if ($row->action === 'approved') $approved[] = $card;
            else $rejected[] = $card;
        }

        // Sort my_action by oldest first (most urgent).
        usort($myAction, fn ($a, $b) => strcmp((string) $a['since'], (string) $b['since']));

        $approved = array_slice($approved, 0, $historyLimit);
        $rejected = array_slice($rejected, 0, $historyLimit);

        return [
            'my_action'       => $myAction,
            'awaiting_others' => $awaitingOthers,
            'approved'        => $approved,
            'rejected'        => $rejected,
            'summary'         => [
                'my_action'       => count($myAction),
                'awaiting_others' => count($awaitingOthers),
                'approved'        => count($approved),
                'rejected'        => count($rejected),
            ],
            'meta' => [
                'pending_limit' => $pendingLimit,
                'history_limit' => $historyLimit,
                'pending_truncated' => $pendingTruncated,
                'history_truncated' => $actionedTruncated,
            ],
        ];
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
     * @param  object  $row      Active pending approval_records row.
     * @param  array<string, mixed>  $meta     Pre-resolved type metadata.
     * @param  object  $source   Pre-fetched approvable source row.
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
            'id'           => $hashId,
            'type'         => $meta['kind'],
            'number'       => $number,
            'link'         => $meta['link'].$hashId,
            'step_order'   => (int) $row->step_order,
            'role_slug'    => (string) $row->role_slug,
            'since'        => $created->toIso8601String(),
            'age_hours'    => (int) abs(Carbon::now()->diffInHours($created)),
            'amount'       => $this->extractAmount($source),
            'summary'      => $this->summaryFor($meta['kind'], $source),
            'requester'    => $requester,
        ];
    }

    /**
     * @param  object  $row        Approved/rejected approval_records row.
     * @param  Collection<int, User>  $approvers  Pre-loaded users keyed by id.
     * @return array<string, mixed>|null
     */
    private function cardForActioned(object $row, array $meta, array $sources, Collection $approvers): ?array
    {
        $source = $sources[$row->approvable_type.'#'.$row->approvable_id] ?? null;
        if (! $source) return null;

        $hashId = app('hashids')->encode((int) $row->approvable_id);
        $number = $meta['number'] ? (string) ($source->{$meta['number']} ?? $hashId) : $hashId;

        // ADV4 — surface the approver actor with their role chip.
        $actor = null;
        if ($row->approver_id) {
            $u = $approvers->get((int) $row->approver_id);
            if ($u) {
                $actor = [
                    'id'   => app('hashids')->encode((int) $row->approver_id),
                    'name' => $u->name,
                    'role' => $u->role ? [
                        'name' => $u->role->name,
                        'slug' => $u->role->slug,
                    ] : null,
                ];
            }
        }

        return [
            'id'        => $hashId,
            'type'      => $meta['kind'],
            'number'    => $number,
            'link'      => $meta['link'].$hashId,
            'action'    => (string) $row->action,
            'acted_at'  => $row->acted_at,
            'remarks'   => (string) ($row->remarks ?? ''),
            'amount'    => $this->extractAmount($source),
            'summary'   => $this->summaryFor($meta['kind'], $source),
            'actor'     => $actor,
        ];
    }

    /** @return array<string, object> */
    private function loadSourcesForRows(Collection $rows): array
    {
        $idsByTable = [];
        foreach ($rows as $row) {
            $meta = ApprovalTypeRegistry::forClass((string) $row->approvable_type);
            if ($meta === null) continue;
            $idsByTable[$meta['table']][] = (int) $row->approvable_id;
        }

        $sources = [];
        foreach (ApprovalTypeRegistry::all() as $class => $meta) {
            $ids = array_values(array_unique($idsByTable[$meta['table']] ?? []));
            if ($ids === []) continue;
            foreach (DB::table($meta['table'])->whereIn('id', $ids)->get() as $source) {
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
        if (empty($ids)) return collect();
        return User::with('role:id,name,slug')->whereIn('id', $ids)->get()->keyBy('id');
    }

    private function extractAmount(object $source): ?string
    {
<<<<<<< HEAD
        // disposal_request_amount first: for an asset awaiting disposal
        // approval the executed disposal_amount is still null, and once the
        // JE has posted the proposal columns are cleared in the same save.
        foreach (['disposal_request_amount', 'total_amount', 'principal', 'amount', 'disposal_amount'] as $col) {
=======
        foreach (['total_amount', 'principal', 'amount', 'refund_amount'] as $col) {
>>>>>>> origin/main
            if (property_exists($source, $col) && $source->{$col} !== null) {
                return (string) $source->{$col};
            }
        }
        return null;
    }

    private function summaryFor(string $kind, object $source): string
    {
        return match ($kind) {
            'leave'   => 'Leave request — '.((string) ($source->start_date ?? '')).' to '.((string) ($source->end_date ?? '')),
            'pr'      => 'Purchase request',
            'po'      => 'Purchase order — vendor #'.((string) ($source->vendor_id ?? '')),
            'loan'    => ucfirst((string) ($source->loan_type ?? 'loan')).' — '.app(CurrencyDisplayService::class)->format($source->principal ?? 0),
            'payroll' => 'Payroll period '.((string) ($source->period_start ?? '')).' to '.((string) ($source->period_end ?? '')),
<<<<<<< HEAD
            'asset_disposal' => 'Disposal of '.((string) ($source->name ?? '')),
=======
            'return_request' => ucfirst(str_replace('_', ' ', (string) ($source->type ?? 'return'))).' — '.((string) ($source->rma_number ?? '')),
>>>>>>> origin/main
            default   => '',
        };
    }
}
