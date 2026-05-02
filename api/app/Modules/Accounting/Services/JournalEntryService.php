<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Support\SearchOperator;

use App\Common\Services\DocumentSequenceService;
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
use RuntimeException;

class JournalEntryService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
    ) {}

    /**
     * Filtered, paginated list ordered by date desc, id desc.
     */
    public function list(array $filters): LengthAwarePaginator
    {
        // role_id required so User's $with=['role'] eager-load can resolve.
        $q = JournalEntry::query()->with(['creator:id,name,email,role_id', 'poster:id,name,email,role_id']);

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
        return DB::transaction(function () use ($data, $user) {
            [$lines, $totalDebit, $totalCredit] = $this->buildLines($data['lines'] ?? []);

            if (Money::cmp($totalDebit, $totalCredit) !== 0) {
                throw new UnbalancedJournalEntryException($totalDebit, $totalCredit);
            }
            if (count($lines) < 2) {
                throw new RuntimeException('A journal entry must have at least two lines.');
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
            throw new RuntimeException('Only draft entries can be edited.');
        }

        return DB::transaction(function () use ($je, $data) {
            [$lines, $totalDebit, $totalCredit] = $this->buildLines($data['lines'] ?? []);
            if (Money::cmp($totalDebit, $totalCredit) !== 0) {
                throw new UnbalancedJournalEntryException($totalDebit, $totalCredit);
            }
            if (count($lines) < 2) {
                throw new RuntimeException('A journal entry must have at least two lines.');
            }

            $je->update([
                'date'           => $data['date']        ?? $je->date,
                'description'    => $data['description'] ?? $je->description,
                'reference_type' => $data['reference_type'] ?? $je->reference_type,
                'reference_id'   => $data['reference_id']   ?? $je->reference_id,
                'total_debit'    => $totalDebit,
                'total_credit'   => $totalCredit,
            ]);

            JournalEntryLine::where('journal_entry_id', $je->id)->delete();
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
            throw new RuntimeException('Only draft entries can be deleted.');
        }
        DB::transaction(function () use ($je) {
            JournalEntryLine::where('journal_entry_id', $je->id)->delete();
            $je->delete();
        });
    }

    public function post(JournalEntry $je, User $by): JournalEntry
    {
        if ($je->status !== JournalEntryStatus::Draft) {
            throw new RuntimeException('Only draft entries can be posted.');
        }

        return DB::transaction(function () use ($je, $by) {
            // Re-validate balance — the lines may have been edited.
            $je->loadMissing('lines');
            $td = Money::zero(); $tc = Money::zero();
            foreach ($je->lines as $line) {
                $td = Money::add($td, (string) $line->debit);
                $tc = Money::add($tc, (string) $line->credit);
            }
            if (Money::cmp($td, $tc) !== 0) {
                throw new UnbalancedJournalEntryException($td, $tc);
            }

            $je->update([
                'status'      => JournalEntryStatus::Posted,
                'posted_by'   => $by->id,
                'posted_at'   => now(),
                'total_debit' => $td,
                'total_credit'=> $tc,
            ]);

            return $je->fresh(['lines.account']);
        });
    }

    /**
     * Create a mirror entry that posts immediately, marking the original
     * as `reversed`. Returns the new (reversal) entry.
     */
    public function reverse(JournalEntry $je, User $by, ?Carbon $reverseDate = null): JournalEntry
    {
        if ($je->status !== JournalEntryStatus::Posted) {
            throw new RuntimeException('Only posted entries can be reversed.');
        }
        if ($je->reversed_by_entry_id !== null) {
            throw new RuntimeException('This entry has already been reversed.');
        }

        return DB::transaction(function () use ($je, $by, $reverseDate) {
            $je->loadMissing('lines');
            $entryNumber = $this->sequences->generate('journal_entry');

            $reversal = JournalEntry::create([
                'entry_number'   => $entryNumber,
                'date'           => $reverseDate ?? now()->toDateString(),
                'description'    => "REVERSAL of {$je->entry_number}: {$je->description}",
                'reference_type' => 'journal_entry_reversal',
                'reference_id'   => $je->id,
                'total_debit'    => $je->total_credit,
                'total_credit'   => $je->total_debit,
                'status'         => JournalEntryStatus::Posted,
                'posted_at'      => now(),
                'posted_by'      => $by->id,
                'created_by'     => $by->id,
            ]);

            $lineNo = 1;
            foreach ($je->lines as $orig) {
                JournalEntryLine::insert([
                    'journal_entry_id' => $reversal->id,
                    'account_id'       => $orig->account_id,
                    'line_no'          => $lineNo++,
                    'debit'            => $orig->credit,
                    'credit'           => $orig->debit,
                    'description'      => 'Reversal: ' . ($orig->description ?? ''),
                ]);
            }

            $je->update([
                'status'               => JournalEntryStatus::Reversed,
                'reversed_by_entry_id' => $reversal->id,
            ]);

            return $reversal->load('lines.account');
        });
    }

    /**
     * Build canonical line rows + running totals from a request payload.
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
                throw new RuntimeException('Invalid account_id in journal entry line.');
            }

            $debit  = Money::round2((string) ($raw['debit']  ?? '0'));
            $credit = Money::round2((string) ($raw['credit'] ?? '0'));

            $hasDebit  = Money::gt($debit,  '0');
            $hasCredit = Money::gt($credit, '0');
            if ($hasDebit === $hasCredit) {
                throw new RuntimeException('Each line must have exactly one of debit or credit greater than zero.');
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
