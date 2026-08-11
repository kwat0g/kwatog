<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Modules\Accounting\Jobs\SyncBudgetActuals;
use App\Modules\Leave\Jobs\ProcessYearEndLeave;
use App\Modules\Payroll\Jobs\ProcessPayrollJob;
use Tests\TestCase;

class QueueConfigurationTest extends TestCase
{
    public function test_redis_retry_lease_exceeds_every_durable_job_timeout(): void
    {
        $longestJobTimeout = max(
            ProcessPayrollJob::TIMEOUT_SECONDS,
            ProcessYearEndLeave::TIMEOUT_SECONDS,
            SyncBudgetActuals::TIMEOUT_SECONDS,
        );

        $this->assertGreaterThan(
            $longestJobTimeout,
            (int) config('queue.connections.redis.retry_after'),
            'Redis retry_after must exceed every durable job timeout.',
        );
    }
}
