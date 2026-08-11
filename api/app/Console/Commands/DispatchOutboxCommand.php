<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Jobs\DispatchOutboxMessage;
use App\Common\Models\ChainListenerRun;
use App\Common\Models\OutboxMessage;
use App\Common\Services\OutboxDispatcher;
use Illuminate\Console\Command;

class DispatchOutboxCommand extends Command
{
    protected $signature = 'outbox:dispatch
        {--limit=100 : Maximum number of pending messages to enqueue}
        {--retry-failed : Requeue failed/dead-letter messages before dispatching}';

    protected $description = 'Publish durable cross-module outbox messages';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        if ($this->option('retry-failed')) {
            $result = $dispatcher->requeueFailed($limit);
            $this->info("Requeued {$result['requeued']} failed outbox messages.");
        }

        $ids = OutboxMessage::query()
            ->where(function ($query): void {
                $query
                    ->where(function ($pending): void {
                        $pending
                            ->where('status', OutboxMessage::STATUS_PENDING)
                            ->where('available_at', '<=', now());
                    })
                    ->orWhere(function ($processing): void {
                        $processing
                            ->where('status', OutboxMessage::STATUS_PROCESSING)
                            ->where(function ($lease): void {
                                $lease
                                    ->where('locked_at', '<=', now()->subMinutes(10))
                                    ->orWhereNull('locked_at');
                            });
                    });
            })
            ->orderBy('available_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            DispatchOutboxMessage::dispatch((string) $id);
        }

        $this->info("Enqueued {$ids->count()} outbox messages.");
        $failedCount = OutboxMessage::query()
            ->where('status', OutboxMessage::STATUS_FAILED)
            ->count();
        if ($failedCount > 0) {
            $this->warn("{$failedCount} outbox messages are in failed/dead-letter status; use --retry-failed after review.");
        }

        $failedListeners = ChainListenerRun::query()
            ->where('status', ChainListenerRun::STATUS_FAILED)
            ->count();
        $retryingListeners = ChainListenerRun::query()
            ->where('status', ChainListenerRun::STATUS_RETRYING)
            ->count();
        if ($failedListeners > 0 || $retryingListeners > 0) {
            $this->warn(sprintf(
                '%d listener jobs failed and %d are retrying; inspect failed_jobs and use queue:retry after review.',
                $failedListeners,
                $retryingListeners,
            ));
        }

        return self::SUCCESS;
    }
}
