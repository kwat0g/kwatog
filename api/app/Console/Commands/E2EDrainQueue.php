<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Support\E2EClock;
use App\Common\Support\E2EEnvironment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

final class E2EDrainQueue extends Command
{
    protected $signature = 'e2e:drain-queue
        {connection=redis : Queue connection (redis, redis_broadcasts, or redis_dss)}
        {--queue=default : Comma-separated queue names to drain}';

    protected $description = 'Run queued E2E work until the selected queues are empty (isolated E2E only).';

    public function handle(E2EClock $clock): int
    {
        E2EEnvironment::assertEnabled();
        $connection = (string) $this->argument('connection');
        if (! in_array($connection, ['redis', 'redis_broadcasts', 'redis_dss'], true)) {
            throw new RuntimeException('Unsupported E2E queue connection.');
        }

        $clock->apply();
        return Artisan::call('queue:work', [
            'connection' => $connection,
            '--queue' => (string) $this->option('queue'),
            '--sleep' => 0,
            '--tries' => 1,
            '--timeout' => 3600,
            '--stop-when-empty' => true,
        ], $this->output);
    }
}
