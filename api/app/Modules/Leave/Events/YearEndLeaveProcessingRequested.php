<?php

declare(strict_types=1);

namespace App\Modules\Leave\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Durable request for one year-end leave disposition run.
 *
 * The request is recorded in the event outbox before the command/controller
 * reports success. That makes a Redis outage or worker restart recoverable by
 * the normal outbox dispatcher instead of silently losing the run.
 */
class YearEndLeaveProcessingRequested
{
    use Dispatchable;

    /**
     * @param array<int>|null $leaveTypeIds
     */
    public function __construct(
        public readonly int $year,
        public readonly int $runById,
        public readonly ?array $leaveTypeIds,
        public readonly string $requestId,
    ) {}
}
