<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\JournalEntry;

/**
 * The only application-level lifecycle transitions for a journal entry.
 *
 * Reversal is an aggregate transition: the replacement entry must already be
 * posted and must identify the source entry before the source can become
 * Reversed. Database triggers enforce the same relationship for raw writers.
 */
final class JournalEntryStateMachine
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'draft' => ['posted'],
        'posted' => ['reversed'],
        'reversed' => [],
    ];

    public function transition(
        JournalEntry $entry,
        JournalEntryStatus $target,
        ?JournalEntry $reversal = null,
    ): void {
        $current = $entry->status instanceof JournalEntryStatus
            ? $entry->status
            : JournalEntryStatus::tryFrom((string) $entry->getRawOriginal('status'));

        if ($current === null) {
            throw new BusinessRuleException('Journal entry status is invalid; the lifecycle cannot continue.');
        }

        if (! in_array($target->value, self::TRANSITIONS[$current->value] ?? [], true)) {
            throw new BusinessRuleException(sprintf(
                'Journal entry cannot transition from %s to %s.',
                $current->label(),
                $target->label(),
            ));
        }

        if ($current === JournalEntryStatus::Posted && $target === JournalEntryStatus::Reversed) {
            $this->assertValidReversal($entry, $reversal);
            $entry->forceFill([
                'status' => $target,
                'reversed_by_entry_id' => $reversal->id,
            ])->save();

            return;
        }

        $entry->forceFill(['status' => $target])->save();
    }

    private function assertValidReversal(JournalEntry $entry, ?JournalEntry $reversal): void
    {
        if (! $reversal
            || ! $reversal->exists
            || (int) $reversal->id === (int) $entry->id
            || $reversal->status !== JournalEntryStatus::Posted
            || $reversal->reference_type !== 'journal_entry_reversal'
            || (int) $reversal->reference_id !== (int) $entry->id
            || $reversal->trashed()) {
            throw new BusinessRuleException(
                'A posted reversal linked to this journal entry is required before it can be marked reversed.',
            );
        }
    }
}
