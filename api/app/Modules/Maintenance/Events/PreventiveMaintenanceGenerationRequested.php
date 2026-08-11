<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Durable request for one scheduled preventive/predictive-maintenance sweep.
 *
 * The scheduler records this request before queue publication. A Redis outage
 * or scheduler restart therefore leaves a recoverable event_outbox row instead
 * of losing the day's maintenance evaluation.
 */
class PreventiveMaintenanceGenerationRequested
{
    use Dispatchable;

    public function __construct(public readonly string $requestId) {}
}
