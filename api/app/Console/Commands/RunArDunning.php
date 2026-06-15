<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Accounting\Services\ArDunningService;
use Illuminate\Console\Command;

class RunArDunning extends Command
{
    protected $signature = 'ar:run-dunning';

    protected $description = 'T1.5 — Send tiered AR dunning emails for overdue invoices.';

    public function handle(ArDunningService $service): int
    {
        $r = $service->run();
        $this->info(sprintf(
            'AR dunning: evaluated=%d sent=%d skipped=%d',
            $r['evaluated'], $r['sent'], $r['skipped'],
        ));
        return self::SUCCESS;
    }
}
