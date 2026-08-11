<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Purchasing\Services\SupplierDispatchService;
use Illuminate\Console\Command;

/**
 * Reconcile supplier dispatch rows left behind by a crashed worker or a
 * provider timeout. Scheduled runs only reclaim stale pending rows. Retrying
 * provider failures is an explicit operator choice because the provider may
 * have accepted an idempotency key before the worker was interrupted.
 */
class RecoverSupplierDispatches extends Command
{
    protected $signature = 'supplier:dispatch-recover
        {--limit=100 : Maximum number of dispatch rows to inspect}
        {--stale-minutes= : Optional override for the configured stale age}
        {--retry-failed : Also retry failed provider rows after reviewing their error}';

    protected $description = 'Recover stale supplier dispatch attempts and reconcile cancelled purchase orders';

    public function handle(SupplierDispatchService $dispatches): int
    {
        $result = $dispatches->recoverStale(
            (int) $this->option('limit'),
            $this->option('stale-minutes') !== null ? (int) $this->option('stale-minutes') : null,
            (bool) $this->option('retry-failed'),
        );

        $this->info(sprintf(
            'Supplier dispatch recovery scanned %d; recovered %d, confirmed %d, cancelled %d, skipped %d.',
            $result['scanned'],
            $result['recovered'],
            $result['confirmed'],
            $result['cancelled'],
            $result['skipped'],
        ));

        if ($result['failed'] > 0) {
            $this->error("{$result['failed']} supplier dispatch recovery attempt(s) failed; inspect the logs and failed queue jobs.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
