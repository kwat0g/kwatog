<?php

declare(strict_types=1);

namespace App\Modules\HR\Events;

use App\Modules\HR\Models\Clearance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Series C — Task C3. Fired by SeparationService::initiate(). Carries the
 * Clearance because the lifecycle pivots around the clearance row, not
 * the Employee row directly.
 */
class SeparationInitiated
{
    use Dispatchable, SerializesModels;

    public function __construct(public Clearance $clearance) {}
}
