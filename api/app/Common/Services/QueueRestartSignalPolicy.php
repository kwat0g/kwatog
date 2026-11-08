<?php

declare(strict_types=1);

namespace App\Common\Services;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Cache\Repository;

/** Keep Laravel's queue restart control marker persistent and outside LRU eviction. */
final class QueueRestartSignalPolicy
{
    public const KEY = 'illuminate:queue:restart';

    /** @var list<string> */
    private const WRITERS = ['queue:restart', 'horizon:terminate'];

    public function __construct(private readonly Repository $cache) {}

    public function handle(CommandFinished $event): void
    {
        if (! in_array($event->command, self::WRITERS, true) || $event->exitCode !== 0) {
            return;
        }

        $signal = $this->cache->get(self::KEY);
        if ($signal === null) {
            return;
        }

        // Both Laravel commands write this framework control value forever.
        // Preserve that lifetime: volatile-lru protects this one allowlisted
        // no-TTL key while still evicting TTL-bound application cache entries.
        $this->cache->forever(self::KEY, $signal);
    }
}
