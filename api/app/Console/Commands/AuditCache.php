<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\CacheKeyAuditReader;
use App\Common\Support\PersistentCacheKeyPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuditCache extends Command
{
    protected $signature = 'cache:audit
        {--max-bytes=1048576 : Report entries larger than this Redis memory usage}
        {--limit=20 : Maximum no-TTL key names to print}
        {--quiet-keys : Do not print key names (for scheduled audits)}';

    protected $description = 'Audit the dedicated application cache Redis keyspace without reading cached values';

    public function handle(CacheKeyAuditReader $reader): int
    {
        $maxBytes = filter_var($this->option('max-bytes'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($maxBytes === false || $maxBytes < 1 || $maxBytes > 67_108_864) {
            $this->error('--max-bytes must be between 1 and 67108864.');

            return self::FAILURE;
        }
        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('--limit must be between 1 and 500.');

            return self::FAILURE;
        }

        try {
            $count = 0;
            $withoutTtl = [];
            $withoutTtlCount = 0;
            $persistentInfrastructureWithoutTtl = 0;
            $oversized = 0;
            $unknownSize = 0;
            $families = [];

            foreach ($reader->scan() as $entry) {
                $count++;
                $store = $entry['store'];
                $family = preg_split('/[.:]/', $entry['key'], 2)[0] ?? 'unknown';
                $family = "{$store}:{$family}";
                $families[$family] = ($families[$family] ?? 0) + 1;
                if ($entry['ttl'] === -1) {
                    if (PersistentCacheKeyPolicy::isExpected($store, $entry['key'])) {
                        $persistentInfrastructureWithoutTtl++;

                        continue;
                    }
                    $withoutTtlCount++;
                    if (count($withoutTtl) < $limit) {
                        $withoutTtl[] = "{$store}:{$entry['key']}";
                    }
                }
                if ($entry['bytes'] === null) {
                    $unknownSize++;
                } elseif ($entry['bytes'] > $maxBytes) {
                    $oversized++;
                }
            }

            ksort($families);
            $familySummary = collect($families)
                ->map(fn (int $items, string $family): string => "{$family}={$items}")
                ->implode(', ');

            $auditContext = [
                'entries' => $count,
                'without_ttl' => $withoutTtlCount,
                'persistent_infrastructure_without_ttl' => $persistentInfrastructureWithoutTtl,
                'oversized' => $oversized,
                'unknown_size' => $unknownSize,
                'families' => $families,
            ];
            try {
                $summaryStored = Cache::store('redis')->put('ops:cache-audit:last:v1', [
                    'completed_at' => now()->toIso8601String(),
                    'entries' => $count,
                    'without_ttl' => $withoutTtlCount,
                    'persistent_infrastructure_without_ttl' => $persistentInfrastructureWithoutTtl,
                    'oversized' => $oversized,
                    'unknown_size' => $unknownSize,
                ], now()->addDays(8));
                if (! $summaryStored) {
                    Log::warning('Application cache audit summary write was rejected.');
                }
            } catch (Throwable $exception) {
                // The audit remains useful even if its short-lived operations
                // summary cannot be saved. The admin panel will show Unknown.
                Log::warning('Application cache audit summary could not be stored.', [
                    'error_class' => $exception::class,
                ]);
            }

            if ($withoutTtlCount > 0 || $oversized > 0 || $unknownSize > 0) {
                Log::warning('Application cache audit found entries requiring review.', $auditContext);
            } else {
                Log::info('Application cache audit completed.', $auditContext);
            }

            $this->line("Cache entries inspected: {$count}");
            $this->line("Entries without TTL: {$withoutTtlCount}");
            $this->line("Persistent infrastructure entries without TTL: {$persistentInfrastructureWithoutTtl}");
            $this->line("Entries above {$maxBytes} bytes: {$oversized}");
            $this->line("Entries with unavailable size: {$unknownSize}");
            $this->line('Key families: '.($familySummary !== '' ? $familySummary : '(none)'));

            if ($withoutTtl !== []) {
                if (! $this->option('quiet-keys')) {
                    $this->warn('No-TTL key names (values were not read):');
                    foreach ($withoutTtl as $key) {
                        $this->line("- {$key}");
                    }
                }
            } else {
                $this->info($persistentInfrastructureWithoutTtl > 0
                    ? 'No unbounded application cache entries found.'
                    : 'No non-expiring cache entries found.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Cache audit failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
