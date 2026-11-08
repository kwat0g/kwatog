<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Common\Services\RealtimeQueueHealth;
use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Content-free infrastructure metrics for the tightly permissioned settings area. */
final class AdminSystemHealthService
{
    private const CACHE_AUDIT_KEY = 'ops:cache-audit:last:v1';

    public function __construct(private readonly RealtimeQueueHealth $realtime) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'realtime' => $this->realtime->snapshot(),
            'reverb_tcp_status' => $this->reverbTcpStatus(),
            'redis_cache' => $this->redisStoreMemory('redis'),
            'redis_persistent' => $this->redisStoreMemory('generations'),
            'cache_audit' => $this->cacheAudit(),
        ];
    }

    /** Verify the configured Reverb TCP listener without exposing its address. */
    private function reverbTcpStatus(): string
    {
        $options = config('broadcasting.connections.reverb.options', []);
        $host = $options['host'] ?? null;
        $port = $options['port'] ?? null;
        if (! is_string($host) || $host === '' || ! is_numeric($port) || (int) $port < 1 || (int) $port > 65535) {
            return 'unconfigured';
        }

        $socket = @fsockopen($host, (int) $port, $errorCode, $errorMessage, 0.25);
        if (! is_resource($socket)) {
            return 'unreachable';
        }

        fclose($socket);

        return 'reachable';
    }

    /** @return array{status: 'available'|'unavailable', used_memory_bytes: ?int, max_memory_bytes: ?int} */
    private function redisStoreMemory(string $storeName): array
    {
        try {
            $store = Cache::store($storeName)->getStore();
            if (! $store instanceof RedisStore) {
                throw new \RuntimeException("The Redis {$storeName} cache store is unavailable.");
            }

            $connection = $store->connection();
            if (! $connection instanceof PhpRedisConnection) {
                throw new \RuntimeException('The Redis cache client does not expose memory telemetry.');
            }

            $info = $connection->client()->info('memory');
            $used = $info['used_memory'] ?? null;
            $maximum = $info['maxmemory'] ?? null;

            return [
                'status' => is_numeric($used) ? 'available' : 'unavailable',
                'used_memory_bytes' => is_numeric($used) ? (int) $used : null,
                'max_memory_bytes' => is_numeric($maximum) && (int) $maximum > 0
                    ? (int) $maximum
                    : null,
            ];
        } catch (Throwable) {
            return [
                'status' => 'unavailable',
                'used_memory_bytes' => null,
                'max_memory_bytes' => null,
            ];
        }
    }

    /** @return array{status: 'clean'|'attention'|'unknown', completed_at: ?string, entries: ?int, without_ttl: ?int, oversized: ?int, unknown_size: ?int} */
    private function cacheAudit(): array
    {
        try {
            $audit = Cache::store('redis')->get(self::CACHE_AUDIT_KEY);
            if (! is_array($audit)) {
                return $this->unknownCacheAudit();
            }

            $withoutTtl = $this->nonNegativeInt($audit['without_ttl'] ?? null);
            $oversized = $this->nonNegativeInt($audit['oversized'] ?? null);
            $unknownSize = $this->nonNegativeInt($audit['unknown_size'] ?? null);
            $entries = $this->nonNegativeInt($audit['entries'] ?? null);
            $completedAt = $audit['completed_at'] ?? null;
            if ($withoutTtl === null || $oversized === null || $unknownSize === null || $entries === null || ! is_string($completedAt)) {
                return $this->unknownCacheAudit();
            }

            return [
                'status' => $withoutTtl === 0 && $oversized === 0 && $unknownSize === 0
                    ? 'clean'
                    : 'attention',
                'completed_at' => $completedAt,
                'entries' => $entries,
                'without_ttl' => $withoutTtl,
                'oversized' => $oversized,
                'unknown_size' => $unknownSize,
            ];
        } catch (Throwable) {
            return $this->unknownCacheAudit();
        }
    }

    /** @return array{status: 'unknown', completed_at: null, entries: null, without_ttl: null, oversized: null, unknown_size: null} */
    private function unknownCacheAudit(): array
    {
        return [
            'status' => 'unknown',
            'completed_at' => null,
            'entries' => null,
            'without_ttl' => null,
            'oversized' => null,
            'unknown_size' => null,
        ];
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }
}
