<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Models\DriverCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DriverCredentialController
{
    public function index(): JsonResponse
    {
        $drivers = User::query()
            ->whereHas('role', static fn ($query) => $query->where('slug', 'driver'))
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);
        $credentials = DriverCredential::query()
            ->whereIn('user_id', $drivers->modelKeys())
            ->get()
            ->keyBy('user_id');

        return response()->json(['data' => $drivers->map(function (User $driver) use ($credentials): array {
            $credential = $credentials->get($driver->id);

            return [
                'id' => $driver->hash_id,
                'name' => $driver->name,
                'is_active' => (bool) $driver->is_active,
                'credential' => $credential ? [
                    'id' => $credential->hash_id,
                    'license_number' => $credential->license_number,
                    'expires_at' => $credential->expires_at?->toDateString(),
                    'status' => $credential->status,
                    'document_url' => $credential->documentUrl(),
                ] : null,
            ];
        })->values()]);
    }

    public function store(Request $request, User $driver): JsonResponse
    {
        $this->assertDriver($driver);
        $data = $request->validate([
            'license_number' => ['required', 'string', 'max:100'],
            // Past dates are accepted so existing expired credentials can be
            // recorded and visibly corrected; assignment checks the expiry.
            'expires_at' => ['required', 'date_format:Y-m-d'],
            'credential_document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $file = $request->file('credential_document');
        $newPath = $file?->store("driver-credentials/{$driver->hash_id}", 'local');
        if ($file && ! $newPath) {
            throw ValidationException::withMessages(['credential_document' => ['The license document could not be stored. Try again.']]);
        }

        $oldPath = null;
        try {
            $credential = DB::transaction(function () use ($driver, $data, $file, $newPath, $request, &$oldPath): DriverCredential {
                $lockedDriver = User::query()->lockForUpdate()->findOrFail($driver->id);
                $this->assertDriver($lockedDriver);
                $credential = DriverCredential::query()->where('user_id', $lockedDriver->id)->lockForUpdate()->first();
                $oldPath = $credential?->document_path;
                $credential ??= new DriverCredential(['user_id' => $lockedDriver->id]);
                $attributes = [
                    'license_number' => trim($data['license_number']),
                    'expires_at' => $data['expires_at'],
                    'uploaded_by' => $request->user()->id,
                ];
                if ($file && $newPath) {
                    $attributes += [
                        'document_path' => $newPath,
                        'document_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                        'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                        'file_size' => $file->getSize(),
                    ];
                }
                $credential->fill($attributes)->save();

                return $credential->fresh();
            });
        } catch (\Throwable $exception) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            throw $exception;
        }

        if ($oldPath && $newPath && $oldPath !== $newPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return response()->json(['data' => [
            'id' => $credential->hash_id,
            'license_number' => $credential->license_number,
            'expires_at' => $credential->expires_at?->toDateString(),
            'status' => $credential->status,
            'document_url' => $credential->documentUrl(),
        ]]);
    }

    public function document(DriverCredential $credential): StreamedResponse
    {
        abort_unless($credential->document_path && Storage::disk('local')->exists($credential->document_path), 404);

        return Storage::disk('local')->download(
            $credential->document_path,
            $credential->document_name ?: 'driver-license-document',
            [
                'Content-Type' => $credential->mime_type ?: 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }

    private function assertDriver(User $driver): void
    {
        abort_unless($driver->role?->slug === 'driver', 404);
    }
}
