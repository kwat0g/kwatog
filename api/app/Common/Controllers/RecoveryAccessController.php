<?php

declare(strict_types=1);

namespace App\Common\Controllers;

use App\Common\Models\BackupOperation;
use App\Common\Services\BackupManifestStore;
use App\Common\Services\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Throwable;

class RecoveryAccessController
{
    private const TOKEN_SECONDS = 1800;

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate(['secret' => ['required', 'string', 'max:512']]);
        $expected = (string) config('backup.recovery_secret', '');
        if (strlen($expected) < 32) {
            return response()->json(['message' => 'Recovery access is not configured.'], 503)
                ->header('Cache-Control', 'no-store, private');
        }
        if (! hash_equals($expected, (string) $validated['secret'])) {
            return response()->json(['message' => 'Recovery secret is incorrect.'], 401)
                ->header('Cache-Control', 'no-store, private');
        }

        $token = Crypt::encryptString(json_encode([
            'scope' => 'backup-recovery',
            'expires_at' => time() + self::TOKEN_SECONDS,
            'secret_hash' => hash('sha256', $expected),
        ], JSON_THROW_ON_ERROR));

        return response()->json(['access_token' => $token, 'expires_in' => self::TOKEN_SECONDS])
            ->header('Cache-Control', 'no-store, private');
    }

    public function index(BackupService $backups): JsonResponse
    {
        return response()->json(['data' => $backups->recoveryIndex()]);
    }

    public function verify(Request $request, BackupService $backups): JsonResponse
    {
        $validated = $request->validate([
            'database_filename' => ['required', 'string', 'max:180'],
        ]);
        $manifest = $backups->adoptLegacy($validated['database_filename']);

        return response()->json([
            'message' => 'Archive checked; checksum recorded for future restore checks.',
            'data' => $manifest,
        ]);
    }

    public function restore(Request $request, BackupService $backups): JsonResponse
    {
        $validated = $request->validate([
            'manifest_id' => ['required', 'string', 'max:64'],
            'database_filename' => ['required', 'string', 'max:180'],
            'files_filename' => ['nullable', 'string', 'max:180'],
            'confirmation' => ['required', 'string', 'max:220'],
        ]);

        $operation = $backups->queueRestore(
            null,
            $validated['manifest_id'],
            $validated['database_filename'],
            $validated['files_filename'] ?? null,
            $validated['confirmation'],
        );

        return response()->json([
            'message' => 'Restore queued. A rollback copy is made if the target database exists and a backup succeeds.',
            'data' => [
                'id' => $operation->id,
                'type' => $operation->type,
                'status' => $operation->status,
                'rollback_available' => false,
            ],
        ], 202);
    }

    public function operation(string $id): JsonResponse
    {
        $recoveryOperation = app(BackupManifestStore::class)->recoveryOperation($id);
        if ($recoveryOperation !== null) {
            $metadata = is_array($recoveryOperation['metadata'] ?? null) ? $recoveryOperation['metadata'] : [];

            return response()->json(['data' => [
                'id' => $recoveryOperation['id'],
                'status' => $recoveryOperation['status'],
                'phase' => $metadata['restore_phase'] ?? $metadata['heartbeat_phase'] ?? null,
                'error_message' => $recoveryOperation['error_message'] ?? null,
                'rollback_available' => (bool) ($metadata['rollback_available'] ?? false),
            ]]);
        }

        try {
            $operation = BackupOperation::query()->find($id);
        } catch (Throwable) {
            return response()->json([
                'message' => 'Restore operation was not found.',
            ], 404);
        }

        if ($operation === null || $operation->type !== BackupService::TYPE_RESTORE) {
            throw ValidationException::withMessages(['operation' => ['Restore operation was not found.']]);
        }

        $metadata = is_array($operation->metadata) ? $operation->metadata : [];

        return response()->json(['data' => [
            'id' => $operation->id,
            'status' => $operation->status,
            'phase' => $metadata['restore_phase'] ?? $metadata['heartbeat_phase'] ?? null,
            'error_message' => $operation->error_message,
            'rollback_available' => is_array($metadata['pre_restore_artifacts']['database'] ?? null),
        ]]);
    }

    public function rollback(string $id, BackupService $backups): JsonResponse
    {
        $backups->rollback($id);

        return response()->json(['message' => 'Pre-restore snapshot restored.']);
    }
}
