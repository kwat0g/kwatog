<?php

declare(strict_types=1);

namespace App\Common\Events\Concerns;

/** Shared queue contract for the application's private realtime events. */
trait BroadcastsOnDedicatedQueue
{
    /** Laravel's BroadcastManager reads this property to select the queue connection. */
    public ?string $connection = 'redis_broadcasts';

    /** @var array<int, int> Bounded retries with short delays for transient Reverb failures. */
    public array $backoff = [1, 5, 15];

    public function broadcastConnection(): ?string
    {
        return $this->connection;
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
