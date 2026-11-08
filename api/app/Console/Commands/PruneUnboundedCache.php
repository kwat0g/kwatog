<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\CacheKeyAuditReader;
use App\Common\Support\PersistentCacheKeyPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class PruneUnboundedCache extends Command
{
    protected $signature = 'cache:prune-unbounded
        {--apply : Atomically delete application-cache keys that still have no TTL}
        {--limit=100 : Maximum number of keys to delete in one run}';

    protected $description = 'Remove unbounded keys from the dedicated application cache namespace';

    public function handle(CacheKeyAuditReader $reader): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('--limit must be between 1 and 500.');

            return self::FAILURE;
        }

        try {
            $candidateCount = 0;
            $deletedCount = 0;
            $limitSkipped = 0;

            foreach ($reader->scan() as $entry) {
                if ($entry['ttl'] !== -1
                    || PersistentCacheKeyPolicy::isExpected($entry['store'], $entry['key'])) {
                    continue;
                }

                $candidateCount++;
                if (! $this->option('apply')) {
                    continue;
                }
                if ($deletedCount >= $limit) {
                    $limitSkipped++;

                    continue;
                }
                if ($reader->deleteIfUnbounded($entry['key'], $entry['store'])) {
                    $deletedCount++;
                }
            }

            $context = [
                'unbounded_candidates' => $candidateCount,
                'deleted' => $deletedCount,
                'limit_skipped' => $limitSkipped,
                'apply' => (bool) $this->option('apply'),
            ];
            Log::info('Application cache unbounded-key prune completed.', $context);

            $this->line("Unbounded entries found: {$candidateCount}");
            $this->line($this->option('apply')
                ? "Entries deleted: {$deletedCount}"
                : 'Entries deleted: 0 (dry run)');
            if ($limitSkipped > 0) {
                $this->warn("Entries deferred by the deletion limit: {$limitSkipped}");
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Cache prune failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
