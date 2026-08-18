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
use App\Modules\Auth\Models\User;
use Carbon\Carbon;
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
    ) {}

    /**
     * Filtered, paginated list ordered by date desc, id desc.
     */
    public function list(array $filters): LengthAwarePaginator
    {
        // role_id required so User's $with=['role'] eager-load can resolve.
        $q = JournalEntry::query()->with(['creator:id,name,email,role_id', 'poster:id,name,email,role_id']);

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
        return DB::transaction(function () use ($data, $user) {
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
                'created_by'     => $user?->id,
            ]);

            foreach ($lines as $line) {
                $line['journal_entry_id'] = $je->id;
                JournalEntryLine::insert($line);
            }

            return $je->load('lines.account');
        });
    }

    public function update(JournalEntry $je, array $data, ?User $user = null): JournalEntry
    {
        if (! $je->isDraft()) {
            throw new BusinessRuleException('Only draft entries can be edited.');
        }

        $referenceType = $data['reference_type'] ?? $je->reference_type;
        $referenceId = array_key_exists('reference_id', $data) ? $data['reference_id'] : $je->reference_id;
        SourceReferenceRegistry::assertValid($referenceType, $referenceId === null ? null : (int) $referenceId);

        return DB::transaction(function () use ($je, $data) {
            [$lines, $totalDebit, $totalCredit] = $this->buildLines($data['lines'] ?? []);
            if (Money::cmp($totalDebit, $totalCredit) !== 0) {
                throw new UnbalancedJournalEntryException($totalDebit, $totalCredit);
            }
            if (count($lines) < 2) {
                throw new BusinessRuleException('A journal entry must have at least two lines.');
            }

            $je->update([
                'date'           => $data['date']        ?? $je->date,
                'description'    => $data['description'] ?? $je->description,
                'reference_type' => $data['reference_type'] ?? $je->reference_type,
                'reference_id'   => $data['reference_id']   ?? $je->reference_id,
                'total_debit'    => $totalDebit,
                'total_credit'   => $totalCredit,
            ]);

            JournalEntryLine::where('journal_entry_id', $je->id)->forceDelete();
            foreach ($lines as $line) {
                $line['journal_entry_id'] = $je->id;
                JournalEntryLine::insert($line);
            }

            return $je->fresh(['lines.account']);
        });
    }

    public function delete(JournalEntry $je): void
    {
        if (! $je->isDraft()) {
            throw new BusinessRuleException('Only draft entries can be deleted.');
        }
        DB::transaction(function () use ($je) {
            JournalEntryLine::where('journal_entry_id', $je->id)->forceDelete();
            $je->delete();
        });
    }

    public function post(JournalEntry $je, User $by): JournalEntry
    {
        return DB::transaction(function () use ($je, $by) {
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

            // OGAMI-001 — block posting into a closed period (date may have
            // been back-dated since the draft was created).
            $this->periods->assertPostingAllowed($lockedJe->date);

            // Re-validate balance — the lines may have been edited.
            $lockedJe->loadMissing('lines');
            $td = Money::zero(); $tc = Money::zero();
            foreach ($lockedJe->lines as $line) {
                $td = Money::add($td, (string) $line->debit);
                $tc = Money::add($tc, (string) $line->credit);
            }
            if (Money::cmp($td, $tc) !== 0) {
                throw new UnbalancedJournalEntryException($td, $tc);
            }

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

            $lockedJe->update([
                'status'      => JournalEntryStatus::Posted,
                'posted_by'   => $by->id,
                'posted_at'   => now(),
                'total_debit' => $td,
                'total_credit'=> $tc,
            ]);

            return $lockedJe->fresh(['lines.account']);
        });
    }

    /**
     * Post a system-generated draft without maker-checker attribution.
     * Automated GL writers still use the canonical balance, period, lock, and
     * status transition path; the optional actor is retained for posting audit.
     */
    public function postSystem(JournalEntry $je, ?int $actorId = null): JournalEntry
    {
        return DB::transaction(function () use ($je, $actorId): JournalEntry {
            $lockedJe = JournalEntry::query()
                ->lockForUpdate()
                ->findOrFail($je->getKey());
            if ($lockedJe->status !== JournalEntryStatus::Draft) {
                throw new BusinessRuleException('Only draft entries can be posted.');
            }

            $this->periods->assertPostingAllowed($lockedJe->date);
            $lockedJe->loadMissing('lines');
            $td = Money::zero();
            $tc = Money::zero();
            foreach ($lockedJe->lines as $line) {
                $td = Money::add($td, (string) $line->debit);
                $tc = Money::add($tc, (string) $line->credit);
            }
            if (Money::cmp($td, $tc) !== 0) {
                throw new UnbalancedJournalEntryException($td, $tc);
            }

            $lockedJe->update([
                'status' => JournalEntryStatus::Posted,
                'posted_by' => $actorId,
                'posted_at' => now(),
                'total_debit' => $td,
                'total_credit' => $tc,
            ]);

            return $lockedJe->fresh(['lines.account']);
        });
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

        // System-generated postings (final pay, payroll, invoice, bill, etc.)
        // carry a reference_type and are created+posted in one automated service
        // flow — they are not manual maker/checker entries, so maker-checker does
        // not apply. SoD on those source documents is enforced upstream (e.g. PO
        // approval, payroll finalize). Only manual, free-form JEs are gated here.
        if (! empty($je->reference_type)) {
            return;
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
    public function reverse(JournalEntry $je, User $by, ?Carbon $reverseDate = null): JournalEntry
    {
        return DB::transaction(function () use ($je, $by, $reverseDate) {
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

            $lockedJe->loadMissing('lines');
            $entryNumber = $this->sequences->generate('journal_entry');

            $reversal = JournalEntry::create([
                'entry_number'   => $entryNumber,
                'date'           => $reverseDate ?? now()->toDateString(),
                'description'    => "REVERSAL of {$lockedJe->entry_number}: {$lockedJe->description}",
                'reference_type' => 'journal_entry_reversal',
                'reference_id'   => $lockedJe->id,
                'total_debit'    => $lockedJe->total_credit,
                'total_credit'   => $lockedJe->total_debit,
                'status'         => JournalEntryStatus::Posted,
                'posted_at'      => now(),
                'posted_by'      => $by->id,
                'created_by'     => $by->id,
            ]);

            $lineNo = 1;
            foreach ($lockedJe->lines as $orig) {
                JournalEntryLine::insert([
                    'journal_entry_id' => $reversal->id,
                    'account_id'       => $orig->account_id,
                    'line_no'          => $lineNo++,
                    'debit'            => $orig->credit,
                    'credit'           => $orig->debit,
                    'description'      => 'Reversal: ' . ($orig->description ?? ''),
                ]);
            }

            $lockedJe->update([
                'status'               => JournalEntryStatus::Reversed,
                'reversed_by_entry_id' => $reversal->id,
            ]);

            return $reversal->load('lines.account');
        });
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
            $accountId = $raw['account_id'] ?? null;
            if (! is_numeric($accountId)) {
                $accountId = HashIdFilter::decode((string) $accountId, Account::class);
            }
            if (! $accountId) {
                throw new BusinessRuleException('Invalid account selected in journal entry line.');
            }

            $debit  = Money::round2((string) ($raw['debit']  ?? '0'));
            $credit = Money::round2((string) ($raw['credit'] ?? '0'));

            $hasDebit  = Money::gt($debit,  '0');
            $hasCredit = Money::gt($credit, '0');
            if ($hasDebit === $hasCredit) {
                throw new BusinessRuleException('Each line must have exactly one of debit or credit greater than zero.');
            }

            $rows[] = [
                'account_id'  => (int) $accountId,
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
