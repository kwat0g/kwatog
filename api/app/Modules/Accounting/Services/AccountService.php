<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountService
{
    /**
     * Flat paginated list with optional filters.
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $q = Account::query();

        if (! empty($filters['type'])) {
            $q->where('type', $filters['type']);
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('code', 'ilike', "%{$term}%")
                   ->orWhere('name', 'ilike', "%{$term}%");
            });
        }

        return $q->orderBy('code')
            ->paginate(min((int) ($filters['per_page'] ?? 100), 200));
    }

    /**
     * Hierarchical tree, ordered by code. Returns top-level accounts with
     * `children` recursively populated. Includes a `current_balance` field
     * computed from posted JE lines (single SQL query, then merged in PHP).
     */
    public function tree(): array
    {
        $accounts = Account::query()->orderBy('code')->get();

        // Aggregate posted balances in one query.
        $balances = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->groupBy('journal_entry_lines.account_id')
            ->selectRaw('
                journal_entry_lines.account_id,
                COALESCE(SUM(debit), 0)  as total_debit,
                COALESCE(SUM(credit), 0) as total_credit
            ')
            ->get()
            ->keyBy('account_id');

        $byParent = [];
        foreach ($accounts as $a) {
            $bal = $balances[$a->id] ?? null;
            $td = (string) ($bal->total_debit ?? '0');
            $tc = (string) ($bal->total_credit ?? '0');
            $balance = $a->normal_balance->value === 'debit'
                ? \App\Common\Support\Money::sub($td, $tc)
                : \App\Common\Support\Money::sub($tc, $td);

            $a->setAttribute('current_balance', $balance);
            $a->setAttribute('total_debit',  $td);
            $a->setAttribute('total_credit', $tc);
            $byParent[(int) ($a->parent_id ?? 0)][] = $a;
        }

        $build = function (int $parentId) use (&$build, $byParent) {
            $list = $byParent[$parentId] ?? [];
            foreach ($list as $node) {
                $node->setRelation('children', collect($build($node->id)));
            }
            return $list;
        };

        return $build(0);
    }

    public function create(array $data): Account
    {
        // Default normal balance from type if not supplied.
        if (empty($data['normal_balance']) && ! empty($data['type'])) {
            $data['normal_balance'] = AccountType::from($data['type'])->defaultNormalBalance()->value;
        }

        // Decode parent hash if provided as string.
        if (! empty($data['parent_id']) && ! is_numeric($data['parent_id'])) {
            $data['parent_id'] = HashIdFilter::decode((string) $data['parent_id'], Account::class);
        }

        // Sanity: parent's type must match this account's type.
        if (! empty($data['parent_id'])) {
            $parent = Account::findOrFail($data['parent_id']);
            if ($parent->type->value !== $data['type']) {
                throw new RuntimeException(sprintf(
                    "Parent account %s is type '%s', cannot host child of type '%s'.",
                    $parent->code, $parent->type->value, $data['type'],
                ));
            }
        }

        return DB::transaction(fn () => Account::create($data));
    }

    public function update(Account $account, array $data): Account
    {
        // type / normal_balance are immutable once posted lines exist.
        if (($account->hasPostedLines())
            && (
                (isset($data['type']) && $data['type'] !== $account->type->value)
                || (isset($data['normal_balance']) && $data['normal_balance'] !== $account->normal_balance->value)
            )) {
            throw new RuntimeException('Cannot change type or normal_balance after posted lines exist.');
        }

        if (! empty($data['parent_id']) && ! is_numeric($data['parent_id'])) {
            $data['parent_id'] = HashIdFilter::decode((string) $data['parent_id'], Account::class);
        }

        return DB::transaction(function () use ($account, $data) {
            $account->update($data);
            return $account->fresh();
        });
    }

    public function deactivate(Account $account): Account
    {
        if ($account->children()->exists()) {
            throw new RuntimeException('Cannot deactivate an account that has child accounts.');
        }

        return DB::transaction(function () use ($account) {
            $account->update(['is_active' => false]);
            return $account->fresh();
        });
    }
}
