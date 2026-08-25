<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** Remove private queued-export artifacts after their safety window. */
class PruneScheduledExportArtifacts extends Command
{
    protected $signature = 'exports:prune-artifacts {--days=7 : Keep artifacts for at least this many days}';

    protected $description = 'Prune durable scheduled-export attachments after retention.';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subDays(max(1, (int) $this->option('days')))->timestamp;
        $deleted = 0;

        foreach ($disk->allFiles('exports/scheduled') as $path) {
            if ((int) ($disk->lastModified($path) ?: 0) >= $cutoff) {
                continue;
            }
            if ($disk->delete($path)) {
                $deleted++;
            }
        }

        $this->info("Pruned {$deleted} scheduled-export artifact(s).");
        return self::SUCCESS;
    }
}
