<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Exceptions\BusinessRuleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Private storage boundary for employee training and skill evidence. */
final class TrainingEvidenceService
{
    private const DISK = 'local';

    /**
     * @return array{path:string, original_name:string, mime_type:string, size:int}
     */
    public function store(UploadedFile $file, string $directory): array
    {
        $path = $file->store($directory, self::DISK);
        if (! is_string($path) || $path === '') {
            throw new BusinessRuleException('Unable to store certification evidence.');
        }

        return [
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => (int) ($file->getSize() ?: 0),
        ];
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public function download(string $path, string $name, ?string $mimeType = null): StreamedResponse
    {
        if (! Storage::disk(self::DISK)->exists($path)) {
            abort(404, 'This certification document is no longer available.');
        }

        return Storage::disk(self::DISK)->download($path, $name, array_filter([
            'Content-Type' => $mimeType,
        ]));
    }
}
