<?php

declare(strict_types=1);

namespace App\Common\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Container\Container;
use Throwable;

/**
 * Explicitly re-runs one queued listener against the original event.
 *
 * Re-dispatching the whole domain event would also repeat sibling listeners
 * such as notifications, email, or bank-file generation. This job keeps the
 * operator action narrow while retaining ordinary queue lifecycle telemetry:
 * its display name is the original listener class and the outbox context is
 * attached by OutboxDispatchContext at enqueue time.
 */
class ReplayChainListenerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly string $listenerClass,
        public readonly string $listenerMethod,
        public readonly object $event,
    ) {}

    public function handle(): void
    {
        $listener = Container::getInstance()->make($this->listenerClass);

        if (in_array(InteractsWithQueue::class, class_uses_recursive($listener), true)) {
            $listener->setJob($this->job);
        }

        $listener->{$this->listenerMethod}($this->event);
    }

    public function failed(Throwable $exception): void
    {
        $listener = Container::getInstance()->make($this->listenerClass);

        if (method_exists($listener, 'failed')) {
            $listener->failed($this->event, $exception);
        }
    }

    public function displayName(): string
    {
        return $this->listenerClass;
    }
}
