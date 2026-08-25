<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\AlertEngineService;
use Illuminate\Console\Command;

class RetryCriticalAlertEmails extends Command
{
    protected $signature = 'alerts:retry-critical-emails {--limit=100 : Maximum alerts to attempt}';

    protected $description = 'Retry eligible critical-alert email deliveries';

    public function handle(AlertEngineService $engine): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1) {
            $this->error('The --limit option must be at least 1.');

            return self::FAILURE;
        }

        $stats = $engine->retryCriticalEmails($limit);
        $this->info(sprintf(
            'Critical alert email sweep attempted %d, sent %d, failed %d.',
            $stats['attempted'],
            $stats['sent'],
            $stats['failed'],
        ));

        return self::SUCCESS;
    }
}
