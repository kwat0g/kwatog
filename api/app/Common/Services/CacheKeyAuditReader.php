<?php

declare(strict_types=1);

namespace App\Common\Services;

interface CacheKeyAuditReader
{
    /** @return iterable<array{store:string,key:string,ttl:int,bytes:?int}> Key names and metadata only. */
    public function scan(): iterable;

    /** Delete a scanned application-cache key only if Redis still reports no TTL. */
    public function deleteIfUnbounded(string $key, string $store = 'redis'): bool;
}
