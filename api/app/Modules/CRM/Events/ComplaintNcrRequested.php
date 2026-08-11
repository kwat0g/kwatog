<?php

declare(strict_types=1);

namespace App\Modules\CRM\Events;

use App\Modules\CRM\Models\CustomerComplaint;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Narrow recovery request for one complaint → NCR handoff. */
class ComplaintNcrRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CustomerComplaint $complaint,
        public readonly string $reasonCode = 'complaint_ncr_manual_required',
    ) {}
}
