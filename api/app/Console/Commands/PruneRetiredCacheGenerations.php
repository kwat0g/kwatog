<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\RetiredGenerationCacheKeyReader;
use App\Common\Support\PersistentCacheKeyPolicy;
use App\Common\Support\RetiredCacheGenerationKeys;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class PruneRetiredCacheGenerations extends Command
{
    protected $signature = 'cache:prune-retired-generations
        {--apply : Delete TTL-bearing application payloads whose UUID generation is no longer active}
        {--limit=100 : Maximum number of candidate keys to delete in one run}';

    protected $description = 'Remove unreachable payloads from retired UUID cache generations';

    public function handle(RetiredGenerationCacheKeyReader $reader): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('--limit must be between 1 and 500.');

            return self::FAILURE;
        }

        try {
            $activeTokens = array_fill_keys($reader->currentGenerationTokens(), true);
            $candidateCount = 0;
            $deletedCount = 0;
            $limitSkipped = 0;

            foreach ($reader->scan() as $entry) {
                if ($entry['store'] !== 'redis'
                    || $entry['ttl'] <= 0
                    || PersistentCacheKeyPolicy::isExpected($entry['store'], $entry['key'])) {
                    continue;
                }

                $retiredTokens = array_values(array_filter(
                    RetiredCacheGenerationKeys::fromCacheKey($entry['key']),
                    static fn (string $token): bool => ! isset($activeTokens[$token]),
                ));
                if ($retiredTokens === []) {
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
                if ($reader->deleteIfRetiredGeneration($entry['key'], $retiredTokens)) {
                    $deletedCount++;
                }
            }

            $context = [
                'retired_generation_candidates' => $candidateCount,
                'deleted' => $deletedCount,
                'limit_skipped' => $limitSkipped,
                'apply' => (bool) $this->option('apply'),
            ];
            Log::info('Retired cache-generation prune completed.', $context);

            $this->line("Retired-generation entries found: {$candidateCount}");
            $this->line($this->option('apply')
                ? "Entries deleted: {$deletedCount}"
                : 'Entries deleted: 0 (dry run)');
            if ($limitSkipped > 0) {
                $this->warn("Entries deferred by the deletion limit: {$limitSkipped}");
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Cache generation prune failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
