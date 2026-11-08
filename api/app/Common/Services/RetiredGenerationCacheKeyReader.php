<?php

declare(strict_types=1);

namespace App\Common\Services;

interface RetiredGenerationCacheKeyReader extends CacheKeyAuditReader
{
    /** @return list<string> Current UUID generation values in the durable marker store. */
    public function currentGenerationTokens(): array;

    /** Atomically delete a TTL-bearing payload key when its name contains a retired UUID token. */
    public function deleteIfRetiredGeneration(string $key, array $retiredTokens): bool;
}
