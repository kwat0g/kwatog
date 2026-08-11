<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

/**
 * Disposable probe used by scripts/queue-worker-recovery-smoke.sh.
 *
 * This deliberately lives under tests/: it proves the real Redis worker
 * contract without adding a business operation to the production surface.
 * Attempt one leaves the job reserved while it sleeps; the smoke harness kills
 * that worker, waits for retry_after to reclaim the reservation, and expects
 * attempt two to finish exactly once.
 */
final class WorkerRecoveryProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 20;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $probeKey,
        public readonly int $firstAttemptSleepSeconds,
    ) {}

    public function handle(): void
    {
        $attempt = $this->attempts();
        $redis = Redis::connection(config('queue.connections.redis.connection', 'default'));

        $redis->set("{$this->probeKey}:started:{$attempt}", (string) now());

        if ($attempt === 1) {
            sleep($this->firstAttemptSleepSeconds);
        }

        $redis->set(
            "{$this->probeKey}:completed",
            json_encode([
                'attempt' => $attempt,
                'completed_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR),
        );
    }
}
