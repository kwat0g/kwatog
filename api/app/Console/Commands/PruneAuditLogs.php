<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
            $path = self::ARCHIVE_DIR . "/audit-{$bucket}.json.gz";

            // Idempotency: a closed (fully-past-cutoff) month is immutable, so
            // an existing archive is authoritative — skip without re-reading.
            if ($disk->exists($path)) {
                $skipped++;
                continue;
            }

            $rows = DB::table('audit_logs')
                ->where('created_at', '<', $cutoff)
                ->whereRaw("to_char(created_at, 'YYYY-MM') = ?", [$bucket])
                ->orderBy('id')
                ->get();

            $payload = [
                'bucket' => $bucket,
                'cutoff' => $cutoff->toIso8601String(),
                'archived_at' => CarbonImmutable::now()->toIso8601String(),
                'row_count' => $rows->count(),
                'rows' => $rows,
            ];

            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                $this->error("Failed to encode archive for {$bucket}: " . json_last_error_msg());
                return self::FAILURE;
            }

            $gz = gzencode($json, 9);
            if ($gz === false) {
                $this->error("Failed to gzip archive for {$bucket}.");
                return self::FAILURE;
            }

            $disk->put($path, $gz);

            $archivedRows += $rows->count();
            $writtenFiles++;
            $this->info("Archived {$rows->count()} rows for {$bucket} → {$path}");
        }

        $this->info(
            "audit:prune complete — {$writtenFiles} new archive file(s), {$archivedRows} row(s) archived, "
            . "{$skipped} month(s) already archived. Source rows retained (audit_logs is immutable)."
        );

        return self::SUCCESS;
    }
}
