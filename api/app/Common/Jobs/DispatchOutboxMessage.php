<?php

declare(strict_types=1);

namespace App\Common\Jobs;

use App\Common\Services\OutboxDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DispatchOutboxMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    /** The lease this job most recently acquired; used to fence stale retries. */
    public ?string $leaseToken = null;

    public function __construct(public readonly string $outboxId) {}

    public function handle(OutboxDispatcher $dispatcher): void
    {
        $leaseToken = $dispatcher->dispatch(
            $this->outboxId,
            function (string $token): void {
                $this->leaseToken = $token;
            },
        );
        if ($leaseToken !== null) {
            $this->leaseToken = $leaseToken;
        }
    }

    public function failed(Throwable $exception): void
    {
        app(OutboxDispatcher::class)->markFailed($this->outboxId, $exception, $this->leaseToken);
    }
}
