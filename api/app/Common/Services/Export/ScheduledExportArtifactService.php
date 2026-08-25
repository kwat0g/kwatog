<?php

declare(strict_types=1);

namespace App\Common\Services\Export;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores queued export payloads as private, durable artifacts.
 *
 * A queued mailable must not carry an unbounded base64 copy of a workbook in
 * Redis. Artifacts remain on the private local disk until the retention/reaper
 * command removes them after the configured safety window.
 */
class ScheduledExportArtifactService
{
    public const DISK = 'local';
    public const MAX_BYTES = 25 * 1024 * 1024;

    public function store(string $bytes, string $filename): string
    {
        $size = strlen($bytes);
        if ($size === 0) {
            throw new \RuntimeException('Refusing to queue an empty export artifact.');
        }
        if ($size > self::MAX_BYTES) {
            throw new \RuntimeException(sprintf(
                'Export artifact exceeds the %d MiB limit.',
                (int) (self::MAX_BYTES / 1024 / 1024),
            ));
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'export.bin';
        $path = 'exports/scheduled/'.now()->format('Y/m/d').'/'.Str::uuid().'-'.$safeName;
        if (! Storage::disk(self::DISK)->put($path, $bytes)) {
            throw new \RuntimeException('Unable to store the scheduled export artifact.');
        }

        return $path;
    }
}
