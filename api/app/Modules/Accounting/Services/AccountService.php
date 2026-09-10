<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\SearchOperator;

use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

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
                $qq->where('code', SearchOperator::like(), "%{$term}%")
                   ->orWhere('name', SearchOperator::like(), "%{$term}%");
            });
        }

        return $q->with(['parent:id,code,name'])
            ->orderBy('code')
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
        $byId = $accounts->keyBy('id');

        // A malformed parent pointer must fail deterministically instead of
        // recursing forever in the tree serializer. The write paths lock and
        // reject these relationships, but this guard also makes the read path
        // safe if legacy data was written outside the service.
        $state = [];
        $visitParent = function (int $accountId) use (&$visitParent, &$state, $byId): void {
            if (($state[$accountId] ?? 0) === 1) {
                throw new BusinessRuleException('The chart of accounts contains a cyclic parent relationship.');
            }
            if (($state[$accountId] ?? 0) === 2) {
                return;
            }

            $state[$accountId] = 1;
            $account = $byId->get($accountId);
            $parentId = (int) ($account?->parent_id ?? 0);
            if ($parentId !== 0 && $byId->has($parentId)) {
                $visitParent($parentId);
            }
            $state[$accountId] = 2;
        };
        foreach ($accounts as $account) {
            $visitParent((int) $account->id);
        }

        // Aggregate posted balances in one query. Reversed originals keep
        // their historical effect; the mirror entry (a separate posted JE)
        // nets them out from the reversal date.
        $balances = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
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
            $parentId = (int) ($a->parent_id ?? 0);
            // Foreign-key-protected data should not reach this branch, but a
            // deleted/legacy parent must not make an account disappear from
            // the chart entirely.
            if ($parentId !== 0 && ! $byId->has($parentId)) {
                $parentId = 0;
            }
            $byParent[$parentId][] = $a;
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
        $this->assertValidCode($data['code'] ?? null);
        $this->assertValidType($data['type'] ?? null);
        $this->assertValidNormalBalance($data['normal_balance'] ?? null);

        // Default normal balance from type if not supplied.
        if (empty($data['normal_balance']) && ! empty($data['type'])) {
            $data['normal_balance'] = AccountType::from($data['type'])->defaultNormalBalance()->value;
        }

        $data = $this->normalizeParentId($data);

        return DB::transaction(function () use ($data): Account {
            $accounts = $this->lockedAccounts();
            $this->assertCodeAvailable((string) $data['code'], $accounts);
            $this->assertValidParent(null, $data, $accounts);

            return Account::create($data);
        });
    }

    public function update(Account $account, array $data): Account
    {
        if (array_key_exists('is_active', $data)) {
            throw new BusinessRuleException('Account status changes must use the dedicated activation endpoint.');
        }

        if (array_key_exists('code', $data)) {
            $this->assertValidCode($data['code']);
        }
        if (array_key_exists('type', $data)) {
            $this->assertValidType($data['type']);
        }
        if (array_key_exists('normal_balance', $data)) {
            $this->assertValidNormalBalance($data['normal_balance']);
        }
        $data = $this->normalizeParentId($data);

        return DB::transaction(function () use ($account, $data) {
            $accounts = $this->lockedAccounts();
            /** @var Account|null $current */
            $current = $accounts->get((int) $account->id);
            if (! $current) {
                throw new BusinessRuleException('The account no longer exists.');
            }

            // type / normal_balance are immutable once posted lines exist.
            if (($current->hasPostedLines())
                && (
                    (isset($data['type']) && $data['type'] !== $current->type->value)
                    || (isset($data['normal_balance']) && $data['normal_balance'] !== $current->normal_balance->value)
                )) {
                throw new BusinessRuleException('Cannot change type or normal_balance after posted lines exist.');
            }

            $this->assertCodeAvailable((string) ($data['code'] ?? $current->code), $accounts, $current->id);
            $this->assertValidParent($current, $data, $accounts);

            $newType = (string) ($data['type'] ?? $current->type->value);
            if ($newType !== $current->type->value
                && $accounts->contains(fn (Account $candidate): bool => (int) $candidate->parent_id === (int) $current->id)) {
                throw new BusinessRuleException('Cannot change an account type while it has child accounts.');
            }

            $current->update($data);

            return $current->fresh();
        });
    }

    public function deactivate(Account $account): Account
    {
        return DB::transaction(function () use ($account) {
            $accounts = $this->lockedAccounts();
            /** @var Account|null $current */
            $current = $accounts->get((int) $account->id);
            if (! $current) {
                throw new BusinessRuleException('The account no longer exists.');
            }
            if ($accounts->contains(fn (Account $candidate): bool => (int) $candidate->parent_id === (int) $current->id)) {
                throw new BusinessRuleException('Cannot deactivate an account that has child accounts.');
            }

            $current->update(['is_active' => false]);

            return $current->fresh();
        });
    }

    public function activate(Account $account): Account
    {
        return DB::transaction(function () use ($account) {
            $accounts = $this->lockedAccounts();
            /** @var Account|null $current */
            $current = $accounts->get((int) $account->id);
            if (! $current) {
                throw new BusinessRuleException('The account no longer exists.');
            }

            $parent = $current->parent_id !== null ? $accounts->get((int) $current->parent_id) : null;
            if ($parent && ! $parent->is_active) {
                throw new BusinessRuleException('Activate the parent account before activating this account.');
            }

            $current->update(['is_active' => true]);

            return $current->fresh();
        });
    }

    private function lockedAccounts()
    {
        return Account::query()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    private function normalizeParentId(array $data): array
    {
        if (! array_key_exists('parent_id', $data)) {
            return $data;
        }

        $value = $data['parent_id'];
        if ($value === null || $value === '') {
            $data['parent_id'] = null;
            return $data;
        }

        $parentId = is_numeric($value)
            ? (int) $value
            : HashIdFilter::decode((string) $value, Account::class);
        if (! $parentId) {
            throw new BusinessRuleException('Invalid parent account selected.');
        }

        $data['parent_id'] = $parentId;
        return $data;
    }

    private function assertValidParent(?Account $account, array $data, $accounts): void
    {
        $parentId = array_key_exists('parent_id', $data)
            ? $data['parent_id']
            : $account?->parent_id;
        if ($parentId === null) {
            return;
        }

        $parent = $accounts->get((int) $parentId);
        if (! $parent) {
            throw new BusinessRuleException('The selected parent account does not exist.');
        }
        if (! $parent->is_active) {
            throw new BusinessRuleException('An inactive parent account cannot host new or reassigned children.');
        }

        $childType = (string) ($data['type'] ?? $account?->type?->value);
        if ($parent->type->value !== $childType) {
            throw new BusinessRuleException(sprintf(
                "Parent account %s is type '%s', cannot host child of type '%s'.",
                $parent->code,
                $parent->type->value,
                $childType,
            ));
        }

        if ($account && (int) $parent->id === (int) $account->id) {
            throw new BusinessRuleException('An account cannot be its own parent.');
        }

        $seen = [];
        $cursor = $parent;
        while ($cursor) {
            $cursorId = (int) $cursor->id;
            if (isset($seen[$cursorId])) {
                throw new BusinessRuleException('The chart of accounts already contains a cyclic parent relationship.');
            }
            $seen[$cursorId] = true;
            if ($account && $cursorId === (int) $account->id) {
                throw new BusinessRuleException('An account cannot be assigned beneath one of its descendants.');
            }
            $cursor = $cursor->parent_id !== null
                ? $accounts->get((int) $cursor->parent_id)
                : null;
        }
    }

    private function assertCodeAvailable(string $code, $accounts, ?int $ignoreId = null): void
    {
        if ($accounts->contains(fn (Account $candidate): bool => $candidate->code === $code
            && ($ignoreId === null || (int) $candidate->id !== $ignoreId))) {
            throw new BusinessRuleException("Account code '{$code}' already exists.");
        }
    }

    private function assertValidCode(mixed $code): void
    {
        if (! is_string($code) || ! preg_match('/^[0-9]{3,6}$/', $code)) {
            throw new BusinessRuleException('Account code must contain 3 to 6 digits.');
        }
    }

    private function assertValidType(mixed $type): void
    {
        if (! is_string($type) || AccountType::tryFrom($type) === null) {
            throw new BusinessRuleException('Invalid account type.');
        }
    }

    private function assertValidNormalBalance(mixed $normalBalance): void
    {
        if ($normalBalance !== null && $normalBalance !== '' && ! in_array($normalBalance, ['debit', 'credit'], true)) {
            throw new BusinessRuleException('Normal balance must be debit or credit.');
        }
    }
}
