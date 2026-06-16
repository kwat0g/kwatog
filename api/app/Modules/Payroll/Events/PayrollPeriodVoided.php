<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Events;

use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * OGAMI-011 — fired by PayrollPeriodService::void() after a finalized period
 * is voided. Listeners may reverse downstream artifacts (e.g. notify finance
 * that the GL reversal posted, void bank files, etc.).
 */
class PayrollPeriodVoided
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public PayrollPeriod $period,
        public ?int $reversalJournalEntryId = null,
    ) {}
}
