<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Common\Services\ApprovalBoardService;
use App\Modules\Auth\Models\User;

/**
 * The caller's approval queue as a worklist.
 *
 * `approvals.pending` was a bare count, and it is the ONE widget every
 * approval-carrying role holds (`permission => null`; the resolver scopes to
 * the caller). `department_head` carries six approve-type grants and no bespoke
 * dashboard page, so this tile is its queue — and "7" does not say which of the
 * seven has been waiting three days.
 *
 * Deliberately delegates to ApprovalBoardService rather than re-querying
 * `approval_records`: that service already resolves the document number through
 * the polymorphic TYPE_MAP, and already widens the role match to cover active
 * approval DELEGATIONS (::roleSlugsFor). A hand-rolled `where('role_slug', $slug)`
 * here — which is what the scalar path does — would hide a delegate's queue
 * from the delegate while /approvals showed it. Read-only either way.
 */
final class ApprovalsWidgetAnalytics
{
    /** Rows on the tile. The board itself carries the rest. */
    private const LIMIT = 8;

    public function __construct(private readonly ApprovalBoardService $board) {}

    /** @return list<string> */
    public function handles(): array
    {
        return ['approvals.pending'];
    }

    /** @return array<string, mixed> */
    public function payload(string $key, User $user): array
    {
        if ($key !== 'approvals.pending') {
            return [];
        }

        $mine = $this->board->board($user)['my_action'];

        // Nothing to act on is a real, meaningful state — but an empty table
        // reads as a broken widget, so fall back to the scalar zero.
        if ($mine === []) {
            return [];
        }

        // Oldest first: the queue is a queue, and the tile's job is to name
        // what has been waiting longest, not what arrived last.
        usort($mine, fn (array $a, array $b): int => $b['age_hours'] <=> $a['age_hours']);

        return [
            'columns' => [
                ['key' => 'number', 'label' => 'Document', 'align' => 'left'],
                ['key' => 'type', 'label' => 'Type', 'align' => 'left'],
                ['key' => 'waiting_hours', 'label' => 'Waiting (h)', 'align' => 'right'],
            ],
            'rows' => array_map(fn (array $card): array => [
                'number' => (string) $card['number'],
                'type' => (string) $card['type'],
                'waiting_hours' => (int) $card['age_hours'],
            ], array_slice($mine, 0, self::LIMIT)),
            'total_count' => count($mine),
        ];
    }
}
