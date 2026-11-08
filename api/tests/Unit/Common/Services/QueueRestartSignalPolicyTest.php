<?php

declare(strict_types=1);

namespace Tests\Unit\Common\Services;

use App\Common\Services\QueueRestartSignalPolicy;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Cache\Repository;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class QueueRestartSignalPolicyTest extends TestCase
{
    public function test_successful_queue_restart_and_horizon_terminate_signals_stay_persistent(): void
    {
        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('get')->twice()->with('illuminate:queue:restart')->andReturn(1234567890);
        $cache->shouldReceive('forever')->twice()
            ->with('illuminate:queue:restart', 1234567890)
            ->andReturnTrue();

        $listener = new QueueRestartSignalPolicy($cache);
        $listener->handle($this->commandFinished('queue:restart'));
        $listener->handle($this->commandFinished('horizon:terminate'));
    }

    public function test_unrelated_or_failed_commands_do_not_change_the_restart_marker(): void
    {
        $cache = Mockery::mock(Repository::class);
        $cache->shouldNotReceive('get');
        $cache->shouldNotReceive('put');
        $listener = new QueueRestartSignalPolicy($cache);

        $listener->handle($this->commandFinished('cache:audit'));
        $listener->handle($this->commandFinished('queue:restart', 1));
    }

    private function commandFinished(string $command, int $exitCode = 0): CommandFinished
    {
        return new CommandFinished($command, new ArrayInput([]), new BufferedOutput, $exitCode);
    }
}
