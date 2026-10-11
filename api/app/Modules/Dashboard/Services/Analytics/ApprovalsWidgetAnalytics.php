<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Common\Services\ApprovalBoardService;
use App\Common\Services\SettingsService;
use App\Common\Support\ApprovalTypeRegistry;
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
 * the shared approval type registry, and already widens the role match to cover active
 * approval DELEGATIONS (::roleSlugsFor). A hand-rolled `where('role_slug', $slug)`
 * here — which is what the scalar path does — would hide a delegate's queue
 * from the delegate while /approvals showed it. Read-only either way.
 */
final class ApprovalsWidgetAnalytics
{
    /** Rows on the tile. The board itself carries the rest. */
    private const LIMIT = 8;

    public function __construct(
        private readonly ApprovalBoardService $board,
        private readonly SettingsService $settings,
    ) {}

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

        // Same clock the board's options endpoint reports
        // (`approvals.reminder_hours`): past it, a card is SLA-overdue. A
        // missing setting must not blank the tile, so default rather than
        // require — the board itself already refuses to run without it.
        $slaHours = max(1, (int) $this->settings->get('approvals.reminder_hours', 24));

        $rows = array_map(function (array $card) use ($slaHours): array {
            // A delegation put the caller on this step but the record sits
            // outside their module row scope: the board redacts it to
            // 'Restricted', and the tile passes that through with a badge and
            // NO link. Read-only either way — act lives on the entity
            // controllers, and a redacted row must not offer a way in.
            $redacted = $card['number'] === 'Restricted';
            $waitingHours = (int) $card['age_hours'];

            return [
                'document' => (string) $card['number'],
                'type' => (string) $card['type'],
                'type_label' => ApprovalTypeRegistry::forKind((string) $card['type'])['label'] ?? (string) $card['type'],
                'waiting_h' => $waitingHours,
                'overdue_hours' => max(0, $waitingHours - $slaHours),
                'link' => $redacted ? null : (string) $card['link'],
                'delegation_badge' => $redacted ? 'Delegated' : null,
            ];
        }, $mine);

        // Most SLA-overdue first, then longest waiting: the tile's job is to
        // name what needs opening now, not what arrived last.
        usort($rows, fn (array $a, array $b): int => [$b['overdue_hours'], $b['waiting_h']] <=> [$a['overdue_hours'], $a['waiting_h']]);

        return [
            'columns' => [
                ['key' => 'document', 'label' => 'Document', 'align' => 'left'],
                ['key' => 'type', 'label' => 'Type', 'align' => 'left'],
                ['key' => 'waiting_h', 'label' => 'Waiting', 'align' => 'right', 'numeric' => true],
            ],
            'rows' => array_slice($rows, 0, self::LIMIT),
            'total_count' => count($mine),
            'sla_hours' => $slaHours,
        ];
    }
}
