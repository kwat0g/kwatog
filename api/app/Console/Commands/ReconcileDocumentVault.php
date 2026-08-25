<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Report vault rows with missing blobs and files with no document row.
 *
 * The default is a read-only report. Orphan deletion requires an explicit
 * flag and a grace period because a worker may still be completing a write.
 * Soft-deleted rows are retained and counted until a document-type retention
 * policy is approved; this command does not guess a legal/audit requirement.
 */
class ReconcileDocumentVault extends Command
{
    protected $signature = 'documents:reconcile
        {--delete-orphans : Delete unreferenced blobs older than the grace period}
        {--grace-days=7 : Minimum age before an orphan blob can be deleted}
        {--dry-run : Explicitly report without deleting, even with --delete-orphans}';

    protected $description = 'Report missing vault blobs and unreferenced private files.';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $referenced = [];
        $missing = 0;
        $softDeleted = 0;

        Document::withTrashed()->orderBy('id')->chunkById(500, function ($documents) use ($disk, &$referenced, &$missing, &$softDeleted): void {
            foreach ($documents as $document) {
                $path = (string) $document->file_path;
                if ($path !== '') {
                    $referenced[$path] = true;
                    if (! $disk->exists($path) && $document->deleted_at === null) {
                        $missing++;
                        $this->warn("Missing blob: {$path} (document {$document->id})");
                    }
                }
                if ($document->deleted_at !== null) {
                    $softDeleted++;
                }
            }
        });

        $graceDays = max(1, (int) $this->option('grace-days'));
        $cutoff = now()->subDays($graceDays)->timestamp;
        $orphans = 0;
        $deleted = 0;
        $delete = (bool) $this->option('delete-orphans') && ! (bool) $this->option('dry-run');

        foreach ($disk->allFiles('documents') as $path) {
            if (isset($referenced[$path])) {
                continue;
            }
            $orphans++;
            $age = (int) ($disk->lastModified($path) ?: 0);
            if ($age > $cutoff) {
                $this->line("Recent orphan (kept): {$path}");
                continue;
            }
            if ($delete && $disk->delete($path)) {
                $deleted++;
                $this->info("Deleted orphan: {$path}");
            } else {
                $this->line("Orphan: {$path}");
            }
        }

        $this->info("Vault reconciliation: {$missing} missing, {$orphans} orphan(s), {$softDeleted} soft-deleted row(s), {$deleted} deleted.");

        return $missing > 0 ? self::FAILURE : self::SUCCESS;
    }
}
