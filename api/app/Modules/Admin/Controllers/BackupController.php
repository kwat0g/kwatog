<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Common\Services\BackupService;
use App\Modules\Admin\Requests\RestoreBackupRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BackupController
{
    public function index(Request $request, BackupService $backups): JsonResponse
    {
        $validated = $request->validate([
            'cursor' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        return response()->json([
            'data' => $backups->index(
                $validated['cursor'] ?? null,
                (int) ($validated['per_page'] ?? 25),
            ),
        ]);
    }

    public function store(Request $request, BackupService $backups): JsonResponse
    {
        $operation = $backups->queueBackup($request->user());

        return response()->json([
            'message' => 'Full backup queued. The database and private files will be validated before publishing.',
            'data' => [
                'id' => $operation->id,
                'type' => $operation->type,
                'status' => $operation->status,
            ],
        ], 202);
    }

    public function restore(RestoreBackupRequest $request, BackupService $backups): JsonResponse
    {
        $operation = $backups->queueRestore(
            $request->user(),
            $request->filled('backup_operation_id') ? (string) $request->string('backup_operation_id') : null,
            (string) $request->string('database_filename'),
            $request->filled('files_filename') ? (string) $request->string('files_filename') : null,
            (string) $request->string('confirmation'),
        );

        return response()->json([
            'message' => 'Restore queued. The system will enter maintenance mode and create a rollback backup first.',
            'data' => [
                'id' => $operation->id,
                'type' => $operation->type,
                'status' => $operation->status,
            ],
        ], 202);
    }
}
