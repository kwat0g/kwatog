<?php

declare(strict_types=1);

namespace App\Modules\CRM\Support;

use App\Modules\Auth\Models\User;
use Closure;

/** Scoped user attribution for audited customer price-agreement changes. */
final class PriceAgreementAuditContext
{
    /** @var list<array{user_id:?int, actor_type:string, reason:?string}> */
    private static array $stack = [];

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public static function run(int $actorId, string $reason, Closure $callback): mixed
    {
        $validUserId = $actorId > 0 && User::query()->whereKey($actorId)->exists() ? $actorId : null;
        self::$stack[] = [
            'user_id' => $validUserId,
            'actor_type' => $validUserId === null ? 'system' : 'user',
            'reason' => trim($reason),
        ];

        try {
            return $callback();
        } finally {
            array_pop(self::$stack);
        }
    }

    /** @template T @param Closure(): T $callback @return T */
    public static function runAsCustomerPortal(string $reason, Closure $callback): mixed
    {
        self::$stack[] = [
            'user_id' => null,
            'actor_type' => 'customer_portal',
            'reason' => trim($reason),
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
}
