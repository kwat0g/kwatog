<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Quality\Services\PpapService;
use Illuminate\Console\Command;

/** Mark expired approved PPAPs so the operational status matches the date gate. */
class ExpirePpapSubmissions extends Command
{
    protected $signature = 'quality:expire-ppaps';
    protected $description = 'Mark approved PPAP submissions past their expiry date as expired';

    public function handle(PpapService $service): int
    {
        $count = $service->expireOverdue();
        $this->info("PPAP expiry check: {$count} submission(s) marked expired.");

        return self::SUCCESS;
    }
}
