<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Modules\Auth\Models\User;
use Closure;

/**
 * Scoped audit attribution for journal model events.
 *
 * Journal writers often run from a queue or listener where Auth is empty, but
 * still know which user finalized or requested the financial action. The
 * context is deliberately stack-based so nested journal writes restore the
 * previous attribution even when an exception aborts the transaction.
 */
final class JournalEntryAuditContext
{
    /** @var list<array{user_id:?int, actor_type:string, reason:?string}> */
    private static array $stack = [];

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public static function run(
        ?int $actorId,
        string $actorType,
        ?string $reason,
        Closure $callback,
    ): mixed {
        $validUserId = self::validUserId($actorId);
        self::$stack[] = [
            'user_id' => $validUserId,
            'actor_type' => $actorType === 'user' && $validUserId === null ? 'system' : $actorType,
            'reason' => $reason !== null ? trim($reason) : null,
        ];

        try {
            return $callback();
        } finally {
            array_pop(self::$stack);
        }
    }

    /** @return array{user_id:?int, actor_type:string, reason:?string}|null */
    public static function current(): ?array
    {
        return self::$stack === [] ? null : self::$stack[array_key_last(self::$stack)];
    }

    private static function validUserId(?int $actorId): ?int
    {
        if ($actorId === null || $actorId < 1) {
            return null;
        }

        return User::query()->whereKey($actorId)->exists() ? $actorId : null;
    }
}
