<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\DSS\Models\DssRunLog;
use App\Modules\DSS\Services\DssParameterService;
use App\Modules\DSS\Services\DssRunnerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

final class RunDssDetectorJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 280;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 360;

    public function __construct(public readonly string $detectorId, public readonly ?int $limit = null)
    {
        $this->onConnection('redis_dss')->onQueue('dss');
    }

    public function uniqueId(): string
    {
        return $this->detectorId;
    }

    public function handle(DssRunnerService $runner, DssParameterService $params): void
    {
        $budgetMs = max(1, min(260000, (int) $params->get(
            DssParameterService::GLOBAL,
            'dss.runner.time_budget_ms',
            30000,
        )));
        $context = ['time_budget_ms' => $budgetMs];
        if ($this->limit !== null) {
            $context['limit'] = max(1, min(10000, $this->limit));
        }

        $runner->run($this->detectorId, $context);
    }

    public function failed(array $data, ?Throwable $exception, string $uuid, ?QueueJob $job = null): void
    {
        if ($exception instanceof TimeoutExceededException) {
            app(DssRunnerService::class)->failTimedOutRun($this->detectorId, DssRunLog::INTERRUPTION_REASON);
        }
    }
}
