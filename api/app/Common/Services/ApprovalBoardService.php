<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Modules\Auth\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Series F — Task F2. Approvals Kanban board.
 *
 * Reads `approval_records` (polymorphic to leave_requests, purchase_requests,
 * purchase_orders, employee_loans, payroll_periods) and buckets each
 * approvable into one of four columns from the current user's perspective:
 *
 *   - my_action       : an approval step is pending and the user's role
 *                       slug matches that step's role_slug
 *   - awaiting_others : an approval step is pending but a different role
 *                       must act
 *   - approved        : last terminal action was 'approved' within 30 days
 *   - rejected        : last terminal action was 'rejected' within 30 days
 *
 * The actual approve/reject mutations remain on the per-entity controllers
 * (leave, PR, PO, loan, payroll) that already enforce per-type permission
 * checks. This service is read-only.
 */
class ApprovalBoardService
{
    /** Approvable types we know how to display. */
    private const TYPE_MAP = [
        'App\\Modules\\Leave\\Models\\LeaveRequest'       => ['kind' => 'leave',    'table' => 'leave_requests',    'number' => 'leave_request_no', 'link' => '/hr/leaves/'],
        'App\\Modules\\Purchasing\\Models\\PurchaseRequest' => ['kind' => 'pr',     'table' => 'purchase_requests', 'number' => 'pr_number',        'link' => '/purchasing/purchase-requests/'],
        'App\\Modules\\Purchasing\\Models\\PurchaseOrder'   => ['kind' => 'po',     'table' => 'purchase_orders',   'number' => 'po_number',        'link' => '/purchasing/purchase-orders/'],
        'App\\Modules\\Loans\\Models\\EmployeeLoan'         => ['kind' => 'loan',   'table' => 'employee_loans',    'number' => 'loan_no',          'link' => '/hr/loans/'],
        'App\\Modules\\Payroll\\Models\\PayrollPeriod'      => ['kind' => 'payroll','table' => 'payroll_periods',   'number' => null,              'link' => '/payroll/periods/'],
    ];

    /**
     * @return array{
     *   my_action: array<int, array<string, mixed>>,
     *   awaiting_others: array<int, array<string, mixed>>,
     *   approved: array<int, array<string, mixed>>,
     *   rejected: array<int, array<string, mixed>>,
     *   summary: array<string, int>,
     * }
     */
    public function board(User $user, ?string $kindFilter = null): array
    {
        $userRoleSlugs = $this->roleSlugsFor($user);
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        // Pull pending approvals (open columns).
        $pending = DB::table('approval_records')
            ->select(['id', 'approvable_type', 'approvable_id', 'step_order', 'role_slug', 'created_at'])
            ->where('action', 'pending')
            ->orderBy('approvable_type')
            ->orderBy('approvable_id')
            ->orderBy('step_order')
            ->get();

        // Pull recently-actioned approvals (closed columns).
        $actioned = DB::table('approval_records')
            ->select(['id', 'approvable_type', 'approvable_id', 'step_order', 'role_slug', 'action', 'remarks', 'acted_at', 'approver_id'])
            ->whereIn('action', ['approved', 'rejected'])
            ->where('acted_at', '>=', $thirtyDaysAgo)
            ->orderByDesc('acted_at')
            ->limit(200)
            ->get();

        // For each approvable, find the earliest pending step (the active step).
        $activeStepByApprovable = [];
        foreach ($pending as $row) {
            $key = $row->approvable_type.'#'.$row->approvable_id;
            if (! isset($activeStepByApprovable[$key])) {
                $activeStepByApprovable[$key] = $row;
            }
        }

        $myAction = [];
        $awaitingOthers = [];

        foreach ($activeStepByApprovable as $key => $row) {
            $card = $this->cardForActive($row);
            if ($card === null) continue;
            if ($kindFilter !== null && $card['type'] !== $kindFilter) continue;

            if (in_array($row->role_slug, $userRoleSlugs, true)) {
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

            $card = $this->cardForActioned($row);
            if ($card === null) continue;
            if ($kindFilter !== null && $card['type'] !== $kindFilter) continue;

            if ($row->action === 'approved') $approved[] = $card;
            else $rejected[] = $card;
        }

        // Sort my_action by oldest first (most urgent).
        usort($myAction, fn ($a, $b) => strcmp((string) $a['since'], (string) $b['since']));

        return [
            'my_action'       => $myAction,
            'awaiting_others' => $awaitingOthers,
            'approved'        => array_slice($approved, 0, 50),
            'rejected'        => array_slice($rejected, 0, 50),
            'summary'         => [
                'my_action'       => count($myAction),
                'awaiting_others' => count($awaitingOthers),
                'approved'        => count($approved),
                'rejected'        => count($rejected),
            ],
        ];
    }

    /** @return array<int, string> */
    private function roleSlugsFor(User $user): array
    {
        // U2/R1: a user has a single role_id. Return its slug as a list for
        // forward compat with multi-role assignment.
        $user->loadMissing('role');
        $slug = $user->role?->slug;
        return $slug ? [$slug] : [];
    }

    /**
     * @param  object  $row  Active pending approval_records row.
     * @return array<string, mixed>|null
     */
    private function cardForActive(object $row): ?array
    {
        $meta = self::TYPE_MAP[$row->approvable_type] ?? null;
        if ($meta === null) return null;

        $source = DB::table($meta['table'])
            ->where('id', $row->approvable_id)
            ->first();
        if (! $source) return null;

        $hashId = app('hashids')->encode((int) $row->approvable_id);
        $number = $meta['number'] ? (string) ($source->{$meta['number']} ?? $hashId) : $hashId;
        $created = $row->created_at ? Carbon::parse((string) $row->created_at) : Carbon::now();

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
        ];
    }

    /**
     * @param  object  $row  Approved/rejected approval_records row.
     * @return array<string, mixed>|null
     */
    private function cardForActioned(object $row): ?array
    {
        $meta = self::TYPE_MAP[$row->approvable_type] ?? null;
        if ($meta === null) return null;

        $source = DB::table($meta['table'])
            ->where('id', $row->approvable_id)
            ->first();
        if (! $source) return null;

        $hashId = app('hashids')->encode((int) $row->approvable_id);
        $number = $meta['number'] ? (string) ($source->{$meta['number']} ?? $hashId) : $hashId;

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
        ];
    }

    private function extractAmount(object $source): ?string
    {
        foreach (['total_amount', 'principal', 'amount'] as $col) {
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
            'loan'    => ucfirst((string) ($source->loan_type ?? 'loan')).' — ₱'.((string) ($source->principal ?? '0.00')),
            'payroll' => 'Payroll period '.((string) ($source->period_start ?? '')).' to '.((string) ($source->period_end ?? '')),
            default   => '',
        };
    }
}
