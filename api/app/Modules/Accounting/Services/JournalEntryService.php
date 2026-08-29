<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\SearchOperator;
use App\Common\Support\TrashedFilter;

use App\Common\Services\DocumentSequenceService;
use App\Common\Services\SettingsService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Exceptions\UnbalancedJournalEntryException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Support\JournalEntryAuditContext;
use App\Modules\Accounting\Support\JournalEntryStateMachine;
use App\Modules\Auth\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class JournalEntryService
{
    /** OGAMI-002 — permission that lets a maker also post (checker) their own JE. */
    private const SELF_POST_OVERRIDE_PERMISSION = 'accounting.journal.self_post_override';

    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly AccountingPeriodService $periods,
        private readonly SettingsService $settings,
        private readonly PostingAccountResolver $accounts,
        private readonly JournalEntryStateMachine $stateMachine,
    ) {}

    /**
     * Filtered, paginated list ordered by date desc, id desc.
     */
    public function list(array $filters): LengthAwarePaginator
    {
        // role_id required so User's $with=['role'] eager-load can resolve.
        $q = JournalEntry::query()->with([
            'creator:id,name,email,role_id',
            'poster:id,name,email,role_id',
            'reversedBy:id,entry_number',
        ]);

        TrashedFilter::apply($q, $filters);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['from'])) {
            $q->whereDate('date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate('date', '<=', $filters['to']);
        }
        if (! empty($filters['reference_type'])) {
            $q->where('reference_type', $filters['reference_type']);
        }
        if (! empty($filters['account_id'])) {
            $accountId = HashIdFilter::decode($filters['account_id'], Account::class);
            if ($accountId) {
                $q->whereHas('lines', fn ($qq) => $qq->where('account_id', $accountId));
            }
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('entry_number', SearchOperator::like(), "%{$term}%")
                   ->orWhere('description', SearchOperator::like(), "%{$term}%");
            });
        }

        return $q->orderByDesc('date')->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(JournalEntry $je): JournalEntry
    {
        return $je->load([
            'lines.account:id,code,name,type,normal_balance',
            'creator:id,name,role_id', 'poster:id,name,role_id',
            'reversedBy:id,entry_number',
        ]);
    }

    /**
     * Create a draft entry.
     *
     * $data = [
     *   'date' => 'Y-m-d',
     *   'description' => string,
     *   'reference_type' => ?string,
     *   'reference_id'   => ?int,
     *   'lines' => [ ['account_id' => hash|int, 'debit' => '0.00', 'credit' => '0.00', 'description' => ?string], ... ],
     * ]
     */
    public function create(array $data, ?User $user = null): JournalEntry
    {
        SourceReferenceRegistry::assertValid(
            $data['reference_type'] ?? null,
            array_key_exists('reference_id', $data) && $data['reference_id'] !== null ? (int) $data['reference_id'] : null,
        );
        $transaction = function () use ($data, $user) {
            // OGAMI-001 — block posting/back-dating into a closed period.
            $this->periods->assertPostingAllowed($data['date']);

            [$lines, $totalDebit, $totalCredit] = $this->buildLines($data['lines'] ?? []);

            if (Money::cmp($totalDebit, $totalCredit) !== 0) {
                throw new UnbalancedJournalEntryException($totalDebit, $totalCredit);
            }
            if (count($lines) < 2) {
                throw new BusinessRuleException('A journal entry must have at least two lines.');
            }

            $entryNumber = $this->sequences->generate('journal_entry');

            $je = JournalEntry::create([
                'entry_number'   => $entryNumber,
                'date'           => $data['date'],
                'description'    => $data['description'] ?? '',
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id'   => $data['reference_id']   ?? null,
                'total_debit'    => $totalDebit,
                'total_credit'   => $totalCredit,
                'status'         => JournalEntryStatus::Draft,
                // Source references are only supplied by internal GL writers.
                // Keep their posting actor in posted_by, but do not let a
                // source-linked entry masquerade as a manual maker/checker
                // draft at the journal boundary.
                'created_by'     => empty($data['reference_type']) ? $user?->id : null,
            ]);

            foreach ($lines as $line) {
                $line['journal_entry_id'] = $je->id;
                JournalEntryLine::create($line);
            }

            return $je->load('lines.account');
        };

        if ($user !== null) {
            return JournalEntryAuditContext::run(
                (int) $user->id,
                'user',
                null,
                fn (): JournalEntry => DB::transaction($transaction),
            );
        }

        return DB::transaction($transaction);
    }

    /**
     * Manual API entry point. Source references are reserved for trusted
     * automated writers and are deliberately not accepted from the user form.
     */
    public function createManual(array $data, User $user): JournalEntry
    {
        if (array_key_exists('reference_type', $data) || array_key_exists('reference_id', $data)) {
            throw new BusinessRuleException('Manual journal entries cannot carry a source reference.');
        }

        return $this->create($data, $user);
    }

    public function update(JournalEntry $je, array $data, ?User $user = null): JournalEntry
    {
        $transaction = function () use ($je, $data): JournalEntry {
            // Re-read and lock the aggregate before checking Draft. The route
            // model may have been loaded before a concurrent post/reversal.
            $lockedJe = JournalEntry::query()
                ->lockForUpdate()
                ->findOrFail($je->getKey());
            if (! $lockedJe->isDraft()) {
                throw new BusinessRuleException('Only draft entries can be edited.');
            }

            // Header → lines → accounts is the journal aggregate lock order.
            // Lock the old line set before resolving the replacement payload so
            // a concurrent draft-line writer cannot interleave with the edit.
            $oldLines = $this->lockLines($lockedJe->id);

            $date = array_key_exists('date', $data)
                ? (string) $data['date']
                : $lockedJe->date->toDateString();
            $description = array_key_exists('description', $data)
                ? $data['description']
                : $lockedJe->description;
            $referenceType = array_key_exists('reference_type', $data)
                ? $data['reference_type']
                : $lockedJe->reference_type;
            $referenceId = array_key_exists('reference_id', $data)
                ? $data['reference_id']
                : $lockedJe->reference_id;
            SourceReferenceRegistry::assertValid(
                $referenceType,
                $referenceId === null ? null : (int) $referenceId,
            );

            [$lines, $totalDebit, $totalCredit] = $this->buildLines($data['lines'] ?? []);
            if (Money::cmp($totalDebit, $totalCredit) !== 0) {
                throw new UnbalancedJournalEntryException($totalDebit, $totalCredit);
            }
            if (count($lines) < 2) {
                throw new BusinessRuleException('A journal entry must have at least two lines.');
            }

            $this->lockAccountRows($oldLines);
            $this->accounts->lockIds(array_map(
                static fn (array $line): int => (int) $line['account_id'],
                $lines,
            ));
            $this->periods->assertPostingAllowed($date);

            $lockedJe->update([
                'date'           => $date,
                'description'    => $description,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
                'total_debit'    => $totalDebit,
                'total_credit'   => $totalCredit,
            ]);

            foreach ($oldLines as $oldLine) {
                $oldLine->delete();
            }
            foreach ($lines as $line) {
                $line['journal_entry_id'] = $lockedJe->id;
                JournalEntryLine::create($line);
            }

            return $lockedJe->fresh(['lines.account']);
        };

        if ($user !== null) {
            return JournalEntryAuditContext::run(
                (int) $user->id,
                'user',
                null,
                fn (): JournalEntry => DB::transaction($transaction),
            );
        }

        return DB::transaction($transaction);
    }

    public function delete(JournalEntry $je): void
    {
        DB::transaction(function () use ($je) {
            // Re-check Draft under the header lock so a stale delete cannot
            // remove the lines of an entry that another request just posted.
            $lockedJe = JournalEntry::withTrashed()
                ->lockForUpdate()
                ->findOrFail($je->getKey());
            if (! $lockedJe->isDraft()) {
                throw new BusinessRuleException('Only draft entries can be deleted.');
            }

            // Keep the same header → lines → accounts order for draft archive
            // operations. Account activity is irrelevant to deletion, so this
            // locks existing account rows without applying the posting guard.
            //
            // The lines are LOCKED BUT NOT DELETED. JournalEntryLine has no
            // SoftDeletes trait, so deleting them here was a hard DELETE while
            // restore() only ever restores the header — an archived draft came
            // back as a header still claiming its total with nothing behind it,
            // un-postable and advertised at full value in the journal list.
            // Keeping them is safe because every aggregate that joins
            // journal_entry_lines filters its parent to status='posted'
            // (TrialBalanceService, BalanceSheetService, IncomeStatementService,
            // AccountService, BudgetConsumptionService and the dashboard
            // queries), so a draft's lines are already counted by nothing; the
            // header's deleted_at is what hides the entry.
            $oldLines = $this->lockLines($lockedJe->id);
            $this->lockAccountRows($oldLines);
            $lockedJe->delete();
        });
    }

    public function restore(JournalEntry $je): JournalEntry
    {
        return DB::transaction(function () use ($je): JournalEntry {
            $lockedJe = JournalEntry::withTrashed()
                ->lockForUpdate()
                ->findOrFail($je->getKey());

            if (! $lockedJe->trashed()) {
                throw new BusinessRuleException('Only archived journal entries can be restored.');
            }
            if (! $lockedJe->isDraft()) {
                throw new BusinessRuleException('Only archived draft entries can be restored.');
            }

            $lockedJe->restore();

            return $lockedJe->fresh(['lines.account']);
        });
    }

    public function post(JournalEntry $je, User $by): JournalEntry
    {
        $transaction = function () use ($je, $by): JournalEntry {
            // P20 — re-check the authoritative row while holding its lock. The
            // passed model may be stale: a concurrent reversal (or any external
            // terminal flip) after the draft was loaded must not let this post
            // resurrect a reversed/voided entry.
            $lockedJe = JournalEntry::query()
                ->lockForUpdate()
                ->findOrFail($je->getKey());
            if ($lockedJe->status !== JournalEntryStatus::Draft) {
                throw new BusinessRuleException('Only draft entries can be posted.');
            }

            // Header → lines → accounts: lock and validate the complete
            // aggregate before checking the period or changing its status.
            [, $td, $tc] = $this->lockAndValidateLines($lockedJe->id);
            // OGAMI-001 — block posting into a closed period (date may have
            // been back-dated since the draft was created).
            $this->periods->assertPostingAllowed($lockedJe->date);

            // OGAMI-002 — maker-checker / segregation of duties.
            // The user who created a draft JE may not also post it. A different
            // user must act as checker. Two escape hatches:
            //   1. `accounting.journal.self_post_override` permission (system_admin
            //      always has it, since hasPermission() short-circuits for admin).
            //   2. A configurable self-post limit: entries whose total is strictly
            //      below `accounting.je_self_post_limit` may be self-posted. A limit
            //      of 0 (the default) means maker !== checker is ALWAYS required.
            // Mirrors the abort(403, ...) self-action pattern in ApprovalService.
            $this->assertNotSelfPosting($lockedJe, $by, $td);

            $lockedJe->forceFill([
                'posted_by'   => $by->id,
                'posted_at'   => now(),
                'total_debit' => $td,
                'total_credit'=> $tc,
            ]);
            $this->stateMachine->transition($lockedJe, JournalEntryStatus::Posted);

            return $lockedJe->fresh(['lines.account']);
        };

        return JournalEntryAuditContext::run(
            (int) $by->id,
            'user',
            null,
            fn (): JournalEntry => DB::transaction($transaction),
        );
    }

    /**
     * Post a system-generated draft without maker-checker attribution.
     * Automated GL writers still use the canonical balance, period, lock, and
     * status transition path; the optional actor is retained for posting audit.
     */
    public function postSystem(JournalEntry $je, ?int $actorId = null): JournalEntry
    {
        $transaction = function () use ($je, $actorId): JournalEntry {
            $lockedJe = JournalEntry::query()
                ->lockForUpdate()
                ->findOrFail($je->getKey());
            if ($lockedJe->status !== JournalEntryStatus::Draft) {
                throw new BusinessRuleException('Only draft entries can be posted.');
            }

            [, $td, $tc] = $this->lockAndValidateLines($lockedJe->id);
            $this->periods->assertPostingAllowed($lockedJe->date);

            $lockedJe->forceFill([
                'posted_by' => $actorId,
                'posted_at' => now(),
                'total_debit' => $td,
                'total_credit' => $tc,
            ]);
            $this->stateMachine->transition($lockedJe, JournalEntryStatus::Posted);

            return $lockedJe->fresh(['lines.account']);
        };

        return JournalEntryAuditContext::run(
            $actorId,
            $actorId === null ? 'system' : 'user',
            null,
            fn (): JournalEntry => DB::transaction($transaction),
        );
    }

    /**
     * OGAMI-002 — enforce maker-checker on posting.
     *
     * Blocks the JE creator from posting their own draft unless:
     *   - they hold `accounting.journal.self_post_override`, OR
     *   - the entry total is strictly below the configured self-post limit.
     *
     * A null `created_by` (legacy / system-generated drafts) cannot trigger the
     * guard, so those post freely.
     */
    private function assertNotSelfPosting(JournalEntry $je, User $by, string $total): void
    {
        $creatorId = $je->created_by !== null ? (int) $je->created_by : null;
        if ($creatorId === null || $creatorId !== (int) $by->id) {
            return; // different checker, or unknown maker — allowed.
        }

        if ($by->hasPermission(self::SELF_POST_OVERRIDE_PERMISSION)) {
            return; // explicit override.
        }

        // Threshold escape hatch. Default '0' => always require maker !== checker.
        $limit = (string) $this->settings->requiredFloat('accounting.je_self_post_limit', 0);
        if (Money::gt($limit, '0') && Money::lt($total, $limit)) {
            return; // below self-post limit — permitted.
        }

        abort(403, 'You cannot post a journal entry you created. A different user must post it (segregation of duties).');
    }

    /**
     * Create a mirror entry that posts immediately, marking the original
     * as `reversed`. Returns the new (reversal) entry.
     */
    public function reverse(
        JournalEntry $je,
        User $by,
        ?Carbon $reverseDate = null,
        ?string $reason = null,
    ): JournalEntry
    {
        return DB::transaction(function () use ($je, $by, $reverseDate, $reason) {
            // Re-check the authoritative entry while holding its row lock. A
            // posted model can be stale by the time a reversal is requested.
            $lockedJe = JournalEntry::query()
                ->lockForUpdate()
                ->findOrFail($je->getKey());
            if ($lockedJe->status !== JournalEntryStatus::Posted) {
                throw new BusinessRuleException('Only posted entries can be reversed.');
            }
            if ($lockedJe->reversed_by_entry_id !== null) {
                throw new BusinessRuleException('This entry has already been reversed.');
            }

            // Header → lines → accounts: the source aggregate is locked and
            // revalidated before a replacement entry can be constructed.
            [$sourceLines, $sourceDebit, $sourceCredit] = $this->lockAndValidateLines($lockedJe->id);
            $effectiveDate = ($reverseDate ?? now())->toDateString();
            $this->periods->assertPostingAllowed($effectiveDate);

            $reason = trim((string) $reason);
            if ($reason === '') {
                // Existing automated writers do not have an operator-entered
                // reason. Keep their reversals auditable without breaking their
                // established service contracts.
                $reason = "Automated reversal of {$lockedJe->entry_number}.";
            }
            $entryNumber = $this->sequences->generate('journal_entry');

            $reversalDebit = $sourceCredit;
            $reversalCredit = $sourceDebit;

            return JournalEntryAuditContext::run(
                (int) $by->id,
                'user',
                $reason,
                function () use (
                    $lockedJe,
                    $sourceLines,
                    $entryNumber,
                    $effectiveDate,
                    $reversalDebit,
                    $reversalCredit,
                    $reason,
                    $by,
                ): JournalEntry {
                    $reversal = JournalEntry::create([
                        'entry_number'    => $entryNumber,
                        'date'            => $effectiveDate,
                        'description'     => "REVERSAL of {$lockedJe->entry_number}: {$lockedJe->description}",
                        'reference_type'  => 'journal_entry_reversal',
                        'reference_id'    => $lockedJe->id,
                        'total_debit'     => $reversalDebit,
                        'total_credit'    => $reversalCredit,
                        'reversal_reason' => $reason,
                        'status'          => JournalEntryStatus::Draft,
                        'created_by'      => $by->id,
                    ]);

                    $lineNo = 1;
                    foreach ($sourceLines as $orig) {
                        JournalEntryLine::create([
                            'journal_entry_id' => $reversal->id,
                            'account_id'       => $this->accounts->id($orig->account_id),
                            'line_no'          => $lineNo++,
                            'debit'            => $orig->credit,
                            'credit'           => $orig->debit,
                            'description'      => 'Reversal: ' . ($orig->description ?? ''),
                        ]);
                    }

                    // Lock and validate the replacement aggregate before its
                    // Draft → Posted transition. The state machine then makes
                    // that status change the single authoritative write.
                    $lockedReversal = JournalEntry::query()
                        ->lockForUpdate()
                        ->findOrFail($reversal->id);
                    [, $replacementDebit, $replacementCredit] = $this->lockAndValidateLines($lockedReversal->id);
                    $lockedReversal->forceFill([
                        'posted_at'    => now(),
                        'posted_by'    => $by->id,
                        'total_debit'  => $replacementDebit,
                        'total_credit' => $replacementCredit,
                    ]);
                    $this->stateMachine->transition($lockedReversal, JournalEntryStatus::Posted);

                    $this->stateMachine->transition(
                        $lockedJe,
                        JournalEntryStatus::Reversed,
                        $lockedReversal,
                    );

                    return $lockedReversal->fresh(['lines.account']);
                },
            );
        });
    }

    /**
     * Lock every line for a journal header in a deterministic order.
     *
     * PostgreSQL's parent-row lock also blocks new FK children while the
     * transaction is open; SQLite has no concurrent writer, so the same query
     * remains the portable aggregate boundary for tests and local work.
     *
     * @return EloquentCollection<int, JournalEntryLine>
     */
    private function lockLines(int $journalEntryId): EloquentCollection
    {
        return JournalEntryLine::query()
            ->where('journal_entry_id', $journalEntryId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Lock existing accounts without applying the active-account posting rule.
     * Draft deletion must remain possible after an account is deactivated.
     *
     * @param EloquentCollection<int, JournalEntryLine> $lines
     */
    private function lockAccountRows(EloquentCollection $lines): void
    {
        $ids = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            $lines->pluck('account_id')->all(),
        )));
        sort($ids, SORT_NUMERIC);

        if ($ids === []) {
            return;
        }

        Account::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Lock and validate the complete line aggregate before a terminal write.
     * The return value is the locked collection followed by canonical totals.
     *
     * @return array{0:EloquentCollection<int, JournalEntryLine>,1:string,2:string}
     */
    private function lockAndValidateLines(int $journalEntryId): array
    {
        $lines = $this->lockLines($journalEntryId);
        $this->accounts->lockIds($lines->pluck('account_id')->all());
        [$totalDebit, $totalCredit] = $this->validateLockedLines($lines);

        return [$lines, $totalDebit, $totalCredit];
    }

    /**
     * Re-check cardinality and the debit/credit XOR invariant while the line
     * and account locks are held. This closes the empty-balanced aggregate
     * path that a totals-only posting check cannot detect.
     *
     * @param EloquentCollection<int, JournalEntryLine> $lines
     * @return array{0:string,1:string}
     */
    private function validateLockedLines(EloquentCollection $lines): array
    {
        if ($lines->count() < 2) {
            throw new BusinessRuleException('A journal entry must have at least two lines.');
        }

        $totalDebit = Money::zero();
        $totalCredit = Money::zero();
        foreach ($lines as $line) {
            $debit = (string) $line->debit;
            $credit = (string) $line->credit;
            if (Money::lt($debit, '0') || Money::lt($credit, '0')) {
                throw new BusinessRuleException('Journal line amounts cannot be negative.');
            }

            $hasDebit = Money::gt($debit, '0');
            $hasCredit = Money::gt($credit, '0');
            if ($hasDebit === $hasCredit) {
                throw new BusinessRuleException('Each line must have exactly one of debit or credit greater than zero.');
            }

            $totalDebit = Money::add($totalDebit, $debit);
            $totalCredit = Money::add($totalCredit, $credit);
        }

        if (Money::cmp($totalDebit, $totalCredit) !== 0) {
            throw new UnbalancedJournalEntryException($totalDebit, $totalCredit);
        }

        return [$totalDebit, $totalCredit];
    }

    /**
     * Build canonical line rows + running totals from a request payload.
     *
     * BusinessRuleException, not ValidationException keyed to `lines`: this
     * builder serves both the JE form and every internal GL poster
     * (BillService::postBillToGl, invoice/GRN/payroll posting), which construct
     * lines themselves and never submit a `lines` field. A zero-value bill line
     * reaches the debit/credit rule from that direction, so keying the error to
     * a form field the caller never sent would point the user at nothing.
     *
     * @return array{0: array<int, array>, 1: string, 2: string}
     */
    private function buildLines(array $rawLines): array
    {
        $totalDebit = Money::zero(); $totalCredit = Money::zero();
        $rows = []; $lineNo = 1;

        foreach ($rawLines as $raw) {
            $accountId = $this->accounts->id($raw['account_id'] ?? null);

            $debit  = Money::round2((string) ($raw['debit']  ?? '0'));
            $credit = Money::round2((string) ($raw['credit'] ?? '0'));

            $hasDebit  = Money::gt($debit,  '0');
            $hasCredit = Money::gt($credit, '0');
            if ($hasDebit === $hasCredit) {
                throw new BusinessRuleException('Each line must have exactly one of debit or credit greater than zero.');
            }

            $rows[] = [
                'account_id'  => $accountId,
                'line_no'     => $lineNo++,
                'debit'       => $debit,
                'credit'      => $credit,
                'description' => $raw['description'] ?? null,
            ];

            $totalDebit  = Money::add($totalDebit,  $debit);
            $totalCredit = Money::add($totalCredit, $credit);
        }

        return [$rows, $totalDebit, $totalCredit];
    }
}
