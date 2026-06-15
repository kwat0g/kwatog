<?php

declare(strict_types=1);

namespace App\Modules\Quality\Events;

use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * T3.2.C — Fired after NcrRecurrenceDetector links an NCR to a prior
 * recurrence. Carries the freshly-linked NCR; listeners use this hook to
 * spawn downstream artefacts (e.g. an 8D report when the NCR's source is
 * customer_complaint).
 */
class NcrRecurrenceLinked
{
    use Dispatchable;

    public function __construct(public readonly NonConformanceReport $ncr) {}
}
