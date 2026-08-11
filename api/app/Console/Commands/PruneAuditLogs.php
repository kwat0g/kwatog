<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * audit:prune — ARCHIVE-ONLY retention for audit_logs.
 *
 * IMPORTANT — WHY THIS COMMAND DOES NOT DELETE:
 *   audit_logs carries an append-only immutability guarantee enforced at two
 *   layers: the App\Common\Traits\HasAuditLog trait (write path) AND a
 *   PostgreSQL BEFORE UPDATE/DELETE trigger installed by migration
 *   2026_06_09_100001_add_audit_log_immutability_trigger.php, whose function
 *   prevent_audit_log_modification() RAISES 'Audit logs are immutable.' on any
 *   row delete. A DELETE against this table therefore ERRORS on Postgres (and
 *   would silently violate immutability on SQLite). The previous implementation
 *   issued DB::table('audit_logs')->delete() and was broken on every run.
 *
 *   We honour immutability: this command EXPORTS rows older than the retention
 *   cutoff to gzipped JSON archive files under storage/app/audit-archives/ and
 *   leaves the source rows in place. Long-term physical pruning, if ever
 *   required, must be done by an operator who first DROPs the trigger via a
 *   dedicated migration (see docs/RESTORE-DRILL.md) — it is intentionally NOT
 *   automated here.
 *
 * IDEMPOTENT: rows are archived into one file per calendar month
 * (audit-YYYY-MM.json.gz). A month that has fully passed the retention window
 * is immutable, so its archive content is stable; months whose file already
 * exists are skipped. Re-runs are safe no-ops.
 */
class PruneAuditLogs extends Command
{
    protected $signature = 'audit:prune {--months=12 : Retain logs for this many months; older rows are archived (never deleted)}';

    protected $description = 'Archive audit logs older than the retention period to gzipped JSON (append-only — never deletes)';

    private const ARCHIVE_DIR = 'audit-archives';

    public function handle(): int
    {
        $months = (int) $this->option('months');

        if ($months < 1) {
            $this->error('--months must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = CarbonImmutable::now()->subMonths($months)->startOfDay();

        $disk = Storage::disk('local');
        if (! $disk->exists(self::ARCHIVE_DIR)) {
            $disk->makeDirectory(self::ARCHIVE_DIR);
        }

        // Enumerate the distinct year-month buckets that are fully older than
        // the cutoff. Bucketing keeps each archive file deterministic so a
        // re-run can cheaply skip months it has already written.
        $buckets = DB::table('audit_logs')
            ->where('created_at', '<', $cutoff)
            ->selectRaw("to_char(created_at, 'YYYY-MM') as bucket")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->pluck('bucket');

        $archivedRows = 0;
        $writtenFiles = 0;
        $skipped = 0;

        foreach ($buckets as $bucket) {
            $path = self::ARCHIVE_DIR."/audit-{$bucket}.json.gz";
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
                // A previous interrupted run may have published a corrupt
                // final file. Only a fully readable gzip counts as complete.
                if ($disk->exists($path)) {
                    if ($this->isValidGzip($absolutePath)) {
                        $skipped++;

                        continue;
                    }

                    $this->warn("Replacing corrupt audit archive: {$path}");
                    if (! @unlink($absolutePath)) {
                        throw new RuntimeException("Could not remove corrupt archive {$path}");
                    }
                }

                $query = static fn () => DB::table('audit_logs')
                    ->where('created_at', '<', $cutoff)
                    ->whereRaw("to_char(created_at, 'YYYY-MM') = ?", [$bucket]);
                $expectedRows = (int) $query()->count();

                $gzipHandle = @gzopen($temporaryPath, 'wb9');
                if ($gzipHandle === false) {
                    throw new RuntimeException("Could not create temporary archive {$temporaryPath}");
                }

                $metadata = json_encode([
                    'bucket' => $bucket,
                    'cutoff' => $cutoff->toIso8601String(),
                    'archived_at' => CarbonImmutable::now()->toIso8601String(),
                    'row_count' => $expectedRows,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $metadata = substr($metadata, 0, -1).',"rows":[';
                $this->writeGzip($gzipHandle, $metadata, $path);

                $first = true;
                $writtenRows = 0;
                foreach ($query()->orderBy('id')->cursor() as $row) {
                    $encodedRow = json_encode(
                        $row,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                    );
                    $this->writeGzip($gzipHandle, ($first ? '' : ',').$encodedRow, $path);
                    $first = false;
                    $writtenRows++;
                }

                if ($writtenRows !== $expectedRows) {
                    throw new RuntimeException("Audit rows changed while archiving {$bucket} ({$writtenRows}/{$expectedRows})");
                }

                $this->writeGzip($gzipHandle, ']}', $path);
                if (! gzclose($gzipHandle)) {
                    throw new RuntimeException("Could not close gzip archive for {$bucket}");
                }
                $gzipHandle = null;

                if (! $this->isValidGzip($temporaryPath)) {
                    throw new RuntimeException("Generated archive for {$bucket} failed gzip validation");
                }
                if (! @rename($temporaryPath, $absolutePath)) {
                    throw new RuntimeException("Could not publish archive for {$bucket}");
                }

                $archivedRows += $writtenRows;
                $writtenFiles++;
                $this->info("Archived {$writtenRows} rows for {$bucket} → {$path}");
            } catch (\Throwable $e) {
                if (is_resource($gzipHandle)) {
                    @gzclose($gzipHandle);
                }
                @unlink($temporaryPath);
                $this->error("Failed to archive {$bucket}: {$e->getMessage()}");

                return self::FAILURE;
            } finally {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
        }

        $this->info(
            "audit:prune complete — {$writtenFiles} new archive file(s), {$archivedRows} row(s) archived, "
            ."{$skipped} month(s) already archived. Source rows retained (audit_logs is immutable)."
        );

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
        if (! is_file($path) || (int) @filesize($path) === 0) {
            return false;
        }
        if (@file_get_contents($path, false, null, 0, 2) !== "\x1f\x8b") {
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
