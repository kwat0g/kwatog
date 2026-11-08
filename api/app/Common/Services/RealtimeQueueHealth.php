<?php

declare(strict_types=1);

namespace App\Common\Services;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Exposes only whether durable real-time delivery is keeping up. */
final class RealtimeQueueHealth
{
    private const CACHE_KEY = 'realtime:delivery-health:v3';

    private const CACHE_TTL_SECONDS = 1;

    public function __construct(private readonly QueueFactory $queues) {}

    /**
     * `size()` includes pending, delayed, and reserved jobs. A non-empty
     * dedicated lane means clients cannot rely on the socket for catch-up.
     * Fail closed when Redis cannot be inspected.
     */
    public function deliveryHealthy(): bool
    {
        return $this->snapshot()['delivery_status'] === 'healthy';
    }

    /** @return array{delivery_status: 'healthy'|'degraded', broadcast_queue_pending: ?int, outbox_pending: ?int, outbox_failed: ?int, oldest_pending_seconds: ?int} */
    public function snapshot(): array
    {
        try {
            // Every visible SPA tab asks for the same content-free delivery state
            // every two seconds. A one-second shared entry collapses most of
            // that fan-out into one queue/DB inspection without caching any
            // user or record data. Expiry bounds the maximum stale health view.
            return Cache::remember(
                self::CACHE_KEY,
                self::CACHE_TTL_SECONDS,
                fn (): array => $this->inspectDeliveryHealth(),
            );
        } catch (Throwable) {
            // If the disposable cache itself is unavailable, inspect the
            // durable queue/outbox directly; cache failure alone must not mark
            // realtime degraded when delivery is otherwise healthy.
            return $this->inspectDeliveryHealth();
        }
    }

    private function inspectDeliveryHealth(): array
    {
        try {
            $queue = $this->queues->connection('redis_broadcasts');
            $queuePending = $queue->size('high') + $queue->size('broadcasts');

            // A queue push can briefly lag the committed outbox row. Only
            // treat pending rows as degraded after a short grace period; a
            // dead-lettered row has no automatic delivery path and stays
            // degraded until an operator retries or resolves it.
            $overdueAt = now()->subSeconds(2);
            $pending = DB::table('event_outbox')->where('status', 'pending')->count();
            $failed = DB::table('event_outbox')->where('status', 'failed')->count();
            $overduePending = DB::table('event_outbox')
                ->where('status', 'pending')
                ->where('available_at', '<=', $overdueAt)
                ->exists();
            // A worker can die after claiming its durable row. When its queue
            // retry is consumed before the lease is reclaimable, that job can
            // finish without publishing while the row remains processing; once
            // no queue job remains, the lease otherwise looks healthy until
            // stale-lease recovery runs (10 minutes).
            // Degrade after the same short freshness window used for pending
            // rows so active clients use their visibility-aware catch-up path.
            $staleProcessing = DB::table('event_outbox')
                ->where('status', 'processing')
                ->where(function ($query) use ($overdueAt): void {
                    $query->whereNull('locked_at')->orWhere('locked_at', '<=', $overdueAt);
                })
                ->exists();
            $oldestPending = DB::table('event_outbox')
                ->where('status', 'pending')
                ->min('created_at');

            return [
                'delivery_status' => $queuePending === 0 && $failed === 0 && ! $overduePending && ! $staleProcessing
                    ? 'healthy'
                    : 'degraded',
                'broadcast_queue_pending' => $queuePending,
                'outbox_pending' => $pending,
                'outbox_failed' => $failed,
                'oldest_pending_seconds' => $oldestPending === null
                    ? null
                    : max(0, (int) now()->diffInSeconds($oldestPending)),
            ];
        } catch (Throwable) {
            return [
                'delivery_status' => 'degraded',
                'broadcast_queue_pending' => null,
                'outbox_pending' => null,
                'outbox_failed' => null,
                'oldest_pending_seconds' => null,
            ];
        }
    }

}
