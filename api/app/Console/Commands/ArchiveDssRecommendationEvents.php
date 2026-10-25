<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ArchiveDssRecommendationEvents extends Command
{
    protected $signature = 'dss:archive-events {--dry-run} {--months=12} {--delete-archived}';

    protected $description = 'Archive old DSS recommendation events to private local storage';

    private const ARCHIVE_DIR = 'dss-event-archives';

    public function handle(): int
    {
        $months = filter_var($this->option('months'), FILTER_VALIDATE_INT);
        $approval = trim((string) config('dss.event_retention_approval_reference', ''));
        if ($months === false || $months < 1) {
            $this->error('--months must be a positive integer.');

            return self::FAILURE;
        }
        if ($months !== 12 && $approval === '') {
            $this->error('Non-default retention requires a configured approval reference.');

            return self::FAILURE;
        }
        if ($this->option('delete-archived') && $approval === '') {
            $this->error('--delete-archived requires a configured approval reference.');

            return self::FAILURE;
        }

        $cutoff = CarbonImmutable::now()->subMonths($months)->startOfMonth();
        $disk = Storage::disk('local');
        $bucketExpression = DB::connection()->getDriverName() === 'pgsql'
            ? "to_char(created_at, 'YYYY-MM')"
            : "strftime('%Y-%m', created_at)";
        $buckets = DB::table('dss_recommendation_events')
            ->where('created_at', '<', $cutoff)
            ->selectRaw("{$bucketExpression} as bucket")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->pluck('bucket');

        if ($this->option('dry-run')) {
            $count = (int) DB::table('dss_recommendation_events')->where('created_at', '<', $cutoff)->count();
            $this->info("Dry run: {$count} eligible event(s) before {$cutoff->toDateString()} in {$buckets->count()} closed month(s).");

            return self::SUCCESS;
        }

        $disk->makeDirectory(self::ARCHIVE_DIR);
        $archived = 0;
        foreach ($buckets as $bucket) {
            $path = self::ARCHIVE_DIR."/dss-events-{$bucket}.json";
            $absolutePath = $disk->path($path);
            $lock = fopen($absolutePath.'.lock', 'c');
            if ($lock === false || ! flock($lock, LOCK_EX)) {
                if (is_resource($lock)) {
                    fclose($lock);
                }
                $this->error('Could not lock DSS archive target.');

                return self::FAILURE;
            }

            $temporaryPath = $absolutePath.'.'.Str::uuid().'.tmp';
            try {
                $query = fn () => DB::table('dss_recommendation_events')
                    ->where('created_at', '<', $cutoff)
                    ->whereRaw("{$bucketExpression} = ?", [$bucket]);
                $rows = $query()->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
                $archive = $this->makeArchive($bucket, $rows);

                if ($disk->exists($path)) {
                    $existing = $this->readVerifiedArchive($absolutePath, $bucket);
                    if ($existing !== null) {
                        if ($existing['checksum'] !== $archive['checksum']) {
                            throw new RuntimeException('Verified archive no longer matches source rows.');
                        }
                        if ($this->option('delete-archived') && ! $this->deleteVerifiedRows($bucket, $cutoff, $bucketExpression, $existing['rows'])) {
                            return self::FAILURE;
                        }
                        $archived += count($existing['rows']);
                        continue;
                    }
                }

                if (file_put_contents($temporaryPath, $archive['json'], LOCK_EX) !== strlen($archive['json'])) {
                    throw new RuntimeException('Could not write complete temporary archive.');
                }
                if ($this->readVerifiedArchive($temporaryPath, $bucket) === null) {
                    throw new RuntimeException('Generated archive failed integrity verification.');
                }
                if (! @rename($temporaryPath, $absolutePath)) {
                    throw new RuntimeException('Could not publish archive.');
                }

                $verified = $this->readVerifiedArchive($absolutePath, $bucket);
                if ($verified === null || count($verified['rows']) !== count($rows)) {
                    throw new RuntimeException('Published archive failed row-count verification.');
                }
                if ($this->option('delete-archived') && ! $this->deleteVerifiedRows($bucket, $cutoff, $bucketExpression, $verified['rows'])) {
                    return self::FAILURE;
                }

                $archived += count($rows);
            } catch (\Throwable) {
                @unlink($temporaryPath);
                $this->error('DSS event archive failed verification or publication.');

                return self::FAILURE;
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        $this->info("DSS event archive complete: {$archived} event(s) verified; source rows retained unless deletion was explicitly approved.");

        return self::SUCCESS;
    }

    /** @param list<array<string, mixed>> $rows */
    private function makeArchive(string $bucket, array $rows): array
    {
        $payload = [
            'source_table' => 'dss_recommendation_events',
            'bucket' => $bucket,
            'row_count' => count($rows),
            'rows' => $rows,
        ];
        $checksum = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $json = json_encode([...$payload, 'checksum_sha256' => $checksum], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return ['checksum' => $checksum, 'json' => $json];
    }

    /** @return array{rows: list<array<string, mixed>>, checksum: string}|null */
    private function readVerifiedArchive(string $path, string $bucket): ?array
    {
        try {
            $archive = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($archive) || ($archive['source_table'] ?? null) !== 'dss_recommendation_events'
                || ($archive['bucket'] ?? null) !== $bucket || ! is_array($archive['rows'] ?? null)
                || count($archive['rows']) !== ($archive['row_count'] ?? null)) {
                return null;
            }
            $payload = [
                'source_table' => $archive['source_table'],
                'bucket' => $archive['bucket'],
                'row_count' => $archive['row_count'],
                'rows' => $archive['rows'],
            ];
            $checksum = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (! hash_equals((string) ($archive['checksum_sha256'] ?? ''), $checksum)) {
                return null;
            }

            return ['rows' => $archive['rows'], 'checksum' => $checksum];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function deleteVerifiedRows(string $bucket, CarbonImmutable $cutoff, string $bucketExpression, array $rows): bool
    {
        $ids = array_column($rows, 'id');
        if ($ids === [] || count($ids) !== count(array_unique($ids))) {
            $this->error('DSS archive row IDs failed verification; no source rows deleted.');

            return false;
        }

        try {
            DB::transaction(function () use ($ids, $bucket, $cutoff, $bucketExpression): void {
                $query = DB::table('dss_recommendation_events')->whereIn('id', $ids)
                    ->where('created_at', '<', $cutoff)->whereRaw("{$bucketExpression} = ?", [$bucket]);
                $matched = $query->lockForUpdate()->pluck('id')->all();
                sort($matched);
                $expected = $ids;
                sort($expected);
                if ($matched !== $expected) {
                    throw new RuntimeException('Archived rows no longer match source rows.');
                }
                $deleted = DB::table('dss_recommendation_events')->whereIn('id', $expected)->delete();
                if ($deleted !== count($expected)) {
                    throw new RuntimeException('Source deletion count did not match verified archive.');
                }
            });

            return true;
        } catch (\Throwable) {
            $this->error('DSS source rows did not match verified archive; no source rows deleted.');

            return false;
        }
    }
}
