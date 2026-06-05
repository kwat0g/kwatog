<?php

declare(strict_types=1);

namespace App\Modules\Loans\Events;

use App\Modules\Loans\Models\EmployeeLoan;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LoanDecided
{
    use Dispatchable, SerializesModels;

    public function __construct(public EmployeeLoan $loan, public bool $approved) {}
}
