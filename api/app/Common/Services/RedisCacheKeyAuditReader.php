<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Support\PersistentCacheKeyPolicy;
use App\Common\Support\RetiredCacheGenerationKeys;
use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Scans the bounded payload cache and the separate generation-marker Redis database. */
final class RedisCacheKeyAuditReader implements RetiredGenerationCacheKeyReader
{
    /** @return iterable<array{store:string,key:string,ttl:int,bytes:?int}> */
    public function scan(): iterable
    {
        foreach (['redis', 'generations'] as $storeName) {
            $store = Cache::store($storeName)->getStore();
            if (! $store instanceof RedisStore) {
                // Unit tests intentionally keep marker state in an isolated
                // array store; production must audit both Redis databases.
                if ($storeName === 'generations' && app()->environment('testing')) {
                    continue;
                }

                throw new RuntimeException("The configured Redis {$storeName} cache store is unavailable.");
            }

            yield from $this->scanStore($storeName, $store);
        }
    }

    /** @return iterable<array{store:string,key:string,ttl:int,bytes:?int}> */
    private function scanStore(string $storeName, RedisStore $store): iterable
    {
        $connection = $store->connection();
        if (! $connection instanceof PhpRedisConnection) {
            throw new RuntimeException('cache:audit currently requires the configured PhpRedis client.');
        }

        $client = $connection->client();
        if (! method_exists($client, 'rawCommand')) {
            throw new RuntimeException('The configured PhpRedis client does not support SCAN and MEMORY USAGE.');
        }

        // SCAN MATCH does not receive Redis' configured key prefix automatically.
        // Laravel's cache store adds its own prefix in addition to that connection prefix.
        $redisPrefix = $connection->_prefix('');
        $fullCachePrefix = $redisPrefix.$store->getPrefix();
        $match = $fullCachePrefix.'*';
        // Use SCAN as a raw command: phpredis applies its configured key prefix
        // to the MATCH option on some versions, which would double-prefix this
        // already fully-qualified cache pattern.
        $cursor = '0';

        do {
            $page = $client->rawCommand('SCAN', $cursor, 'MATCH', $match, 'COUNT', '500');
            if (! is_array($page) || count($page) < 2 || ! is_array($page[1])) {
                break;
            }
            [$cursor, $keys] = $page;

            foreach ($keys as $rawKey) {
                if (! is_string($rawKey) || ! str_starts_with($rawKey, $fullCachePrefix)) {
                    continue;
                }

                // Connection commands apply the configured Redis prefix, so use
                // the key after removing that layer for TTL. MEMORY USAGE is sent
                // as a raw command and therefore receives the full Redis key.
                $logicalKey = substr($rawKey, strlen($redisPrefix));
                $ttl = (int) $connection->ttl($logicalKey);
                if ($ttl === -2) {
                    continue; // Expired between SCAN and inspection.
                }

                $bytes = $client->rawCommand('MEMORY', 'USAGE', $rawKey);

                yield [
                    'store' => $storeName,
                    'key' => substr($rawKey, strlen($fullCachePrefix)),
                    'ttl' => $ttl,
                    'bytes' => is_numeric($bytes) ? (int) $bytes : null,
                ];
            }
        } while ((string) $cursor !== '0');
    }

    public function deleteIfUnbounded(string $key, string $storeName = 'redis'): bool
    {
        if (! in_array($storeName, ['redis', 'generations'], true)
            || $key === ''
            || strlen($key) > 512
            || str_contains($key, "\0")
            || PersistentCacheKeyPolicy::isExpected($storeName, $key)) {
            return false;
        }

        $store = Cache::store($storeName)->getStore();
        if (! $store instanceof RedisStore) {
            throw new RuntimeException("The configured Redis {$storeName} cache store is unavailable.");
        }

        $connection = $store->connection();
        if (! $connection instanceof PhpRedisConnection) {
            throw new RuntimeException('cache:prune-unbounded currently requires the configured PhpRedis client.');
        }

        $client = $connection->client();
        if (! method_exists($client, 'rawCommand')) {
            throw new RuntimeException('The configured PhpRedis client does not support atomic cache pruning.');
        }

        // SCAN yields only logical keys inside this store prefix. Recheck TTL
        // and delete atomically so a concurrent bounded write is never removed.
        $rawKey = $connection->_prefix('').$store->getPrefix().$key;
        $script = <<<'LUA'
local ttl = redis.call('TTL', KEYS[1])
if ttl == -1 then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;
        $deleted = $client->rawCommand('EVAL', $script, '1', $rawKey);

        return is_numeric($deleted) && (int) $deleted === 1;
    }

    public function currentGenerationTokens(): array
    {
        $repository = Cache::store('generations');
        $store = $repository->getStore();
        if (! $store instanceof RedisStore) {
            if (app()->environment('testing')) {
                return [];
            }

            throw new RuntimeException('The configured Redis generations cache store is unavailable.');
        }

        $tokens = [];
        foreach ($this->scanStore('generations', $store) as $entry) {
            $value = $repository->get($entry['key']);
            if (is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
                $tokens[] = strtolower($value);
            }
        }

        return array_values(array_unique($tokens));
    }

    public function deleteIfRetiredGeneration(string $key, array $retiredTokens): bool
    {
        if ($key === ''
            || strlen($key) > 512
            || str_contains($key, "\0")
            || PersistentCacheKeyPolicy::isExpected('redis', $key)) {
            return false;
        }

        $candidateTokens = array_fill_keys(RetiredCacheGenerationKeys::fromCacheKey($key), true);
        $matched = array_filter($retiredTokens, static function (mixed $token) use ($candidateTokens): bool {
            return is_string($token)
                && isset($candidateTokens[strtolower($token)])
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $token) === 1;
        });
        if ($matched === []) {
            return false;
        }

        $store = Cache::store('redis')->getStore();
        if (! $store instanceof RedisStore) {
            throw new RuntimeException('The configured Redis payload cache store is unavailable.');
        }

        $connection = $store->connection();
        if (! $connection instanceof PhpRedisConnection) {
            throw new RuntimeException('cache:prune-retired-generations currently requires the configured PhpRedis client.');
        }

        $client = $connection->client();
        if (! method_exists($client, 'rawCommand')) {
            throw new RuntimeException('The configured PhpRedis client does not support atomic cache pruning.');
        }

        // The key was yielded by SCAN within the dedicated payload cache prefix.
        // It can only contain an unreachable UUID generation, and must still have
        // a positive TTL when deleted. Queue/session stores are never scanned.
        $rawKey = $connection->_prefix('').$store->getPrefix().$key;
        $script = <<<'LUA'
local ttl = redis.call('TTL', KEYS[1])
if ttl > 0 then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;
        $deleted = $client->rawCommand('EVAL', $script, '1', $rawKey);

        return is_numeric($deleted) && (int) $deleted === 1;
    }
}
