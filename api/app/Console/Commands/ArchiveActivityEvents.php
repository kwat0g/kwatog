<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Archive-only retention for the append-only activity feed projection.
 *
 * Activity events are useful for operations but the canonical evidence remains
 * in audit_logs. The projection is retained and archived by calendar month;
 * this command never deletes or mutates source rows.
 */
class ArchiveActivityEvents extends Command
{
    protected $signature = 'activity:archive {--months=12 : Retain events for this many months; older rows are archived (never deleted)}';

    protected $description = 'Archive activity events older than the retention period to gzipped JSON (append-only — never deletes)';

    private const ARCHIVE_DIR = 'activity-archives';

    public function handle(): int
    {
        $months = (int) $this->option('months');
        if ($months < 1) {
            $this->error('--months must be at least 1.');

            return self::FAILURE;
        }

        if (! Schema::hasTable('activity_events')) {
            $this->warn('activity_events does not exist; nothing to archive.');

            return self::SUCCESS;
        }

        $cutoff = CarbonImmutable::now()->subMonths($months)->startOfDay();
        $disk = Storage::disk('local');
        if (! $disk->exists(self::ARCHIVE_DIR)) {
            $disk->makeDirectory(self::ARCHIVE_DIR);
        }

        $bucketExpression = DB::connection()->getDriverName() === 'pgsql'
            ? "to_char(created_at, 'YYYY-MM')"
            : "strftime('%Y-%m', created_at)";
        $buckets = DB::table('activity_events')
            ->where('created_at', '<', $cutoff)
            ->selectRaw("{$bucketExpression} as bucket")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->pluck('bucket');

        $rows = 0;
        $files = 0;
        $skipped = 0;

        foreach ($buckets as $bucket) {
            $path = self::ARCHIVE_DIR."/activity-{$bucket}.json.gz";
            $absolutePath = $disk->path($path);
            $lockHandle = fopen($absolutePath.'.lock', 'c');
            if ($lockHandle === false || ! flock($lockHandle, LOCK_EX)) {
                if (is_resource($lockHandle)) {
                    fclose($lockHandle);
                }
                $this->error("Could not lock archive target for {$bucket}.");

                return self::FAILURE;
            }

            $temporaryPath = $absolutePath.'.'.Str::uuid().'.tmp';
            $gzipHandle = null;

            try {
                if ($disk->exists($path)) {
                    if ($this->isValidGzip($absolutePath)) {
                        $skipped++;
                        continue;
                    }
                    if (! @unlink($absolutePath)) {
                        throw new RuntimeException("Could not remove corrupt archive {$path}");
                    }
                }

                $query = static fn () => DB::table('activity_events')
                    ->where('created_at', '<', $cutoff)
                    ->whereRaw("{$bucketExpression} = ?", [$bucket]);
                $expectedRows = (int) $query()->count();
                $gzipHandle = @gzopen($temporaryPath, 'wb9');
                if ($gzipHandle === false) {
                    throw new RuntimeException("Could not create temporary archive {$temporaryPath}");
                }

                $metadata = json_encode([
                    'source_table' => 'activity_events',
                    'bucket' => $bucket,
                    'cutoff' => $cutoff->toIso8601String(),
                    'archived_at' => CarbonImmutable::now()->toIso8601String(),
                    'row_count' => $expectedRows,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $this->writeGzip($gzipHandle, substr($metadata, 0, -1).',"rows":[', $path);

                $first = true;
                $writtenRows = 0;
                foreach ($query()->orderBy('id')->cursor() as $row) {
                    $encoded = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    $this->writeGzip($gzipHandle, ($first ? '' : ',').$encoded, $path);
                    $first = false;
                    $writtenRows++;
                }

                if ($writtenRows !== $expectedRows) {
                    throw new RuntimeException("Activity rows changed while archiving {$bucket} ({$writtenRows}/{$expectedRows})");
                }

                $this->writeGzip($gzipHandle, ']}', $path);
                if (! gzclose($gzipHandle)) {
                    throw new RuntimeException("Could not close gzip archive for {$bucket}");
                }
                $gzipHandle = null;

                if (! $this->isValidGzip($temporaryPath) || ! @rename($temporaryPath, $absolutePath)) {
                    throw new RuntimeException("Could not publish archive for {$bucket}");
                }

                $rows += $writtenRows;
                $files++;
                $this->info("Archived {$writtenRows} activity row(s) for {$bucket} → {$path}");
            } catch (\Throwable $e) {
                if (is_resource($gzipHandle)) {
                    @gzclose($gzipHandle);
                }
                @unlink($temporaryPath);
                $this->error("Failed to archive activity events for {$bucket}: {$e->getMessage()}");

                return self::FAILURE;
            } finally {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
        }

        $this->info("activity:archive complete — {$files} new archive file(s), {$rows} row(s) archived, {$skipped} month(s) already archived. Source rows retained.");

        return self::SUCCESS;
    }

    private function writeGzip(mixed $handle, string $contents, string $path): void
    {
        $written = gzwrite($handle, $contents);
        if ($written === false || $written !== strlen($contents)) {
            throw new RuntimeException("Could not write complete gzip archive {$path}");
        }
    }

    private function isValidGzip(string $path): bool
    {
        if (! is_file($path) || (int) @filesize($path) === 0 || @file_get_contents($path, false, null, 0, 2) !== "\x1f\x8b") {
            return false;
        }

        $handle = @gzopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            while (! gzeof($handle)) {
                if (gzread($handle, 1024 * 1024) === false) {
                    return false;
                }
            }

            return true;
        } finally {
            gzclose($handle);
        }
    }
}
