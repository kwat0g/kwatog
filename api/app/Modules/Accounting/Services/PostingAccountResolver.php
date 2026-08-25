<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use RuntimeException;

/**
 * Resolves accounts that are about to receive a new journal line.
 *
 * Historical journal lines remain readable after deactivation, but every new
 * manual or automated posting must pass through this resolver so an inactive
 * account cannot be used through a less-visible GL writer.
 */
final class PostingAccountResolver
{
    public function id(mixed $value): int
    {
        $accountId = is_numeric($value)
            ? (int) $value
            : HashIdFilter::decode((string) ($value ?? ''), Account::class);

        if (! $accountId) {
            throw new BusinessRuleException('Invalid account selected for a journal line.');
        }

        return $this->active($accountId)->id;
    }

    /**
     * Resolve an account that is allowed to receive a line for a known
     * accounting purpose. The active check and classification check happen at
     * the same service boundary for both hash IDs and internal IDs.
     */
    public function idForTypes(mixed $value, AccountType ...$types): int
    {
        $accountId = is_numeric($value)
            ? (int) $value
            : HashIdFilter::decode((string) ($value ?? ''), Account::class);

        if (! $accountId) {
            throw new BusinessRuleException('Invalid account selected for a journal line.');
        }

        return $this->assertTypes($accountId, ...$types);
    }

    /**
     * Re-check a persisted account before a draft is posted. Drafts can outlive
     * an account deactivation, so validation at creation time is not enough.
     */
    public function assertTypes(int $accountId, AccountType ...$types): int
    {
        $account = $this->active($accountId);
        if ($types !== [] && ! in_array($account->type, $types, true)) {
            $allowed = implode(', ', array_map(static fn (AccountType $type): string => $type->value, $types));
            throw new BusinessRuleException("Account {$account->code} must be of type {$allowed}.");
        }

        return $account->id;
    }

    public function idByCode(string $code): int
    {
        $account = Account::query()->where('code', $code)->first();
        if (! $account) {
            throw new BusinessRuleException("Required account {$code} not found in COA.");
        }

        return $this->active($account->id)->id;
    }

    /**
     * Lock all accounts used by a posting in a deterministic order. Account
     * deactivation takes the same ordered lock, so a post cannot pass its
     * active check and then commit after the account is deactivated.
     *
     * @param array<int, int|string> $accountIds
     */
    public function lockIds(array $accountIds): void
    {
        $ids = array_values(array_unique(array_map(static fn (int|string $id): int => (int) $id, $accountIds)));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            $this->active($id, true);
        }
    }

    /**
     * Resolve an account code coming from deployment settings. A missing code
     * is a server configuration fault; an inactive code is still an explicit
     * business-rule failure because it cannot receive a new posting.
     */
    public function configuredIdByCode(string $code, AccountType ...$types): int
    {
        $account = Account::query()->where('code', $code)->first();
        if (! $account) {
            throw new RuntimeException("Required account {$code} not found in COA.");
        }

        return $this->assertTypes($account->id, ...$types);
    }

    private function active(int $accountId, bool $lock = false): Account
    {
        $query = Account::query();
        if ($lock) {
            $query->lockForUpdate();
        }
        $account = $query->find($accountId);
        if (! $account) {
            throw new BusinessRuleException('The selected account no longer exists.');
        }
        if (! $account->is_active) {
            throw new BusinessRuleException("Account {$account->code} is inactive and cannot receive new postings.");
        }

        return $account;
    }
}
