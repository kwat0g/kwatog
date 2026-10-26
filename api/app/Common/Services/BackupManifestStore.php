<?php

declare(strict_types=1);

namespace App\Common\Services;

use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Backup metadata stored beside the archives, outside the database ledger. */
class BackupManifestStore
{
    private const DATABASE_PATTERN = '/\Aogami-[A-Za-z0-9][A-Za-z0-9._-]*\.sql\.gz\z/D';

    private const FILES_PATTERN = '/\Aogami-files-[A-Za-z0-9][A-Za-z0-9._-]*\.tar\.gz\z/D';

    /** @param array<string, mixed> $operation */
    public function createRecoveryOperation(array $operation): void
    {
        $this->withRecoveryOperationLock(function () use ($operation): void {
            $current = $this->readRecoveryOperation();
            $metadata = is_array($current['metadata'] ?? null) ? $current['metadata'] : [];
            $hasRollback = is_array($metadata['pre_restore_artifacts']['database'] ?? null);
            if (in_array($current['status'] ?? null, ['queued', 'running'], true)
                || (($current['status'] ?? null) === 'rollback_required' && $hasRollback)) {
                throw new RuntimeException('A recovery restore is already active or requires rollback.');
            }

            $this->writeRecoveryOperation($operation);
        });
    }

    /** @return array<string, mixed>|null */
    public function recoveryOperation(string $id): ?array
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $id) !== 1) {
            return null;
        }

        $operation = $this->readRecoveryOperation();

        return ($operation['id'] ?? null) === $id ? $operation : null;
    }

    /** @return array<string, mixed>|null */
    public function activeRecoveryOperation(): ?array
    {
        $operation = $this->readRecoveryOperation();

        return in_array($operation['status'] ?? null, ['queued', 'running', 'rollback_required'], true)
            ? $operation
            : null;
    }

    /** @param array<string, mixed> $changes */
    public function updateRecoveryOperation(string $id, array $changes): void
    {
        $this->withRecoveryOperationLock(function () use ($id, $changes): void {
            $operation = $this->recoveryOperation($id);
            if ($operation === null) {
                throw new RuntimeException('Recovery operation was not found.');
            }

            $operation = array_merge($operation, $changes, ['updated_at' => now()->toIso8601String()]);
            $this->writeRecoveryOperation($operation);
        });
    }

    /** @return array<string, mixed> */
    public function publish(
        string $databaseFilename,
        ?string $filesFilename = null,
        ?string $id = null,
        string $source = 'scheduled',
    ): array {
        $database = $this->describe($databaseFilename, 'database');
        $files = $filesFilename !== null ? $this->describe($filesFilename, 'files') : null;
        $manifest = [
            'version' => 1,
            'id' => $id ?: (string) Str::uuid(),
            'source' => $source,
            'created_at' => $database['created_at'],
            'scope' => $files !== null ? 'database_and_private_files' : 'database_only',
            'artifacts' => ['database' => $database, 'files' => $files],
        ];

        $this->write($databaseFilename, $manifest);

        return $manifest;
    }

    /** @return array<string, mixed>|null */
    public function find(string $reference): ?array
    {
        foreach ($this->all() as $manifest) {
            if (($manifest['id'] ?? null) === $reference
                || ($manifest['artifacts']['database']['name'] ?? null) === $reference) {
                return $manifest;
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $manifests = [];
        foreach (glob($this->directory().'/*.sql.gz.manifest.json') ?: [] as $path) {
            $contents = @file_get_contents($path);
            $manifest = is_string($contents) ? json_decode($contents, true) : null;
            if (! is_array($manifest) || ! $this->isValid($manifest)) {
                continue;
            }

            $manifests[] = $manifest;
        }

        usort($manifests, static fn (array $a, array $b): int => strcmp(
            (string) ($b['created_at'] ?? ''),
            (string) ($a['created_at'] ?? ''),
        ));

        return $manifests;
    }

    /**
     * Capture integrity metadata for a pre-manifest archive after checking its
     * gzip stream. This records current bytes; it cannot authenticate the old
     * archive's origin.
     *
     * @return array<string, mixed>
     */
    public function adoptLegacy(string $databaseFilename): array
    {
        $this->validateName($databaseFilename, 'database');
        $existing = $this->find($databaseFilename);
        if ($existing !== null) {
            return $existing;
        }

        $path = $this->directory().DIRECTORY_SEPARATOR.$databaseFilename;
        if (! is_file($path)) {
            throw new RuntimeException('Selected database backup is unavailable.');
        }

        $gzip = new Process(['gzip', '-t', $path], null, null, null, 120);
        $gzip->run();
        if (! $gzip->isSuccessful()) {
            throw new RuntimeException('Selected database backup is not a valid gzip archive.');
        }

        $stream = @gzopen($path, 'rb');
        $header = is_resource($stream) ? @gzread($stream, 8192) : false;
        if (is_resource($stream)) {
            gzclose($stream);
        }
        $clientMajor = is_string($header)
            ? preg_match('/^-- Dumped by pg_dump version ([0-9]+)/m', $header, $clientMatch)
            : 0;
        $serverMajor = is_string($header)
            ? preg_match('/^-- Dumped from database version ([0-9]+)/m', $header, $serverMatch)
            : 0;
        if ($clientMajor !== 1 || $serverMajor !== 1 || $clientMatch[1] !== $serverMatch[1]) {
            throw new RuntimeException('Selected archive is not a compatible PostgreSQL dump.');
        }

        return $this->publish($databaseFilename, null, null, 'legacy_adopted');
    }

    public function pruneMissingArtifacts(): void
    {
        $bucket = config('backup.s3_bucket');
        if (is_string($bucket) && trim($bucket) !== '') {
            // Local retention can delete the archive while its S3 copy remains restorable.
            return;
        }

        foreach (glob($this->directory().'/*.sql.gz.manifest.json') ?: [] as $path) {
            $contents = @file_get_contents($path);
            $manifest = is_string($contents) ? json_decode($contents, true) : null;
            $databaseFilename = is_array($manifest)
                ? ($manifest['artifacts']['database']['name'] ?? null)
                : null;
            if (! is_string($databaseFilename)
                || preg_match(self::DATABASE_PATTERN, $databaseFilename) !== 1
                || ! is_file($this->directory().DIRECTORY_SEPARATOR.$databaseFilename)) {
                @unlink($path);
            }
        }
    }

    private function write(string $databaseFilename, array $manifest): void
    {
        $this->validateName($databaseFilename, 'database');
        $path = $this->manifestPath($databaseFilename);
        $temporary = $path.'.'.Str::uuid().'.tmp';
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        if (@file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)
            || ! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Could not publish the backup manifest.');
        }

        @chmod($path, 0640);
    }

    /** @return array<string, mixed> */
    private function describe(string $filename, string $kind): array
    {
        $path = $this->directory().DIRECTORY_SEPARATOR.$filename;
        $this->validateName($filename, $kind);
        if (! is_file($path) || filesize($path) === false) {
            throw new RuntimeException('Backup artifact is unavailable.');
        }

        $checksum = hash_file('sha256', $path);
        if (! is_string($checksum)) {
            throw new RuntimeException('Could not checksum backup artifact.');
        }

        return [
            'name' => $filename,
            'kind' => $kind,
            'size' => filesize($path),
            'sha256' => $checksum,
            'created_at' => now()->setTimestamp((int) filemtime($path))->toIso8601String(),
            'source' => 'local',
        ];
    }

    private function isValid(array $manifest): bool
    {
        $database = $manifest['artifacts']['database'] ?? null;
        if (($manifest['version'] ?? null) !== 1
            || ! is_string($manifest['id'] ?? null)
            || ! is_array($database)
            || ! is_string($database['name'] ?? null)
            || preg_match(self::DATABASE_PATTERN, $database['name']) !== 1
            || ! is_numeric($database['size'] ?? null)
            || ! is_string($database['sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/i', $database['sha256']) !== 1) {
            return false;
        }

        $files = $manifest['artifacts']['files'] ?? null;

        return $files === null || (
            is_array($files)
            && is_string($files['name'] ?? null)
            && preg_match(self::FILES_PATTERN, $files['name']) === 1
            && is_numeric($files['size'] ?? null)
            && is_string($files['sha256'] ?? null)
            && preg_match('/\A[a-f0-9]{64}\z/i', $files['sha256']) === 1
        );
    }

    private function validateName(string $filename, string $kind): void
    {
        $pattern = $kind === 'files' ? self::FILES_PATTERN : self::DATABASE_PATTERN;
        if (basename($filename) !== $filename || preg_match($pattern, $filename) !== 1) {
            throw new RuntimeException('Backup artifact name is invalid.');
        }
    }

    private function manifestPath(string $databaseFilename): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.$databaseFilename.'.manifest.json';
    }

    /** @return array<string, mixed> */
    private function readRecoveryOperation(): array
    {
        $contents = @file_get_contents($this->directory().DIRECTORY_SEPARATOR.'recovery-operation.json');
        $operation = is_string($contents) ? json_decode($contents, true) : null;

        return is_array($operation) ? $operation : [];
    }

    /** @param array<string, mixed> $operation */
    private function writeRecoveryOperation(array $operation): void
    {
        $directory = $this->directory();
        if (! is_dir($directory) && ! @mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not prepare the recovery operation store.');
        }

        $path = $directory.DIRECTORY_SEPARATOR.'recovery-operation.json';
        $temporary = $path.'.'.Str::uuid().'.tmp';
        $json = json_encode($operation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (@file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)
            || ! @chmod($temporary, 0600)
            || ! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Could not persist the recovery operation.');
        }
        @chmod($path, 0600);
    }

    private function withRecoveryOperationLock(callable $callback): void
    {
        $directory = $this->directory();
        if (! is_dir($directory) && ! @mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not prepare the recovery operation store.');
        }
        $lock = @fopen($directory.DIRECTORY_SEPARATOR.'.recovery-operation.lock', 'c');
        if (! is_resource($lock) || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock the recovery operation store.');
        }
        @chmod($directory.DIRECTORY_SEPARATOR.'.recovery-operation.lock', 0600);

        try {
            $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function directory(): string
    {
        $configured = config('backup.directory');
        $directory = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : storage_path('app/backups');
        if ($directory === '/' || trim($directory) === '') {
            throw new RuntimeException('Unsafe backup directory configuration.');
        }

        return rtrim($directory, DIRECTORY_SEPARATOR);
    }
}
