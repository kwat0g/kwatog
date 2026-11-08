<?php

declare(strict_types=1);

namespace App\Common\Events\Concerns;

use Illuminate\Support\Str;

/** Supplies a queue-serializable identity for a broadcast event instance. */
trait HasBroadcastEventId
{
    public string $eventId;

    protected function initializeBroadcastEventId(?string $eventId = null): void
    {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }
}
