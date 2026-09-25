<?php

declare(strict_types=1);

namespace App\Common\Controllers;

use App\Common\Models\ImportBatch;
use App\Common\Services\Import\MasterDataImportService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * REC-03 — master-data CSV import (dry-run preview, atomic commit, rollback).
 */
class ImportController
{
    public function __construct(private readonly MasterDataImportService $service) {}

    /** Available import entity types (coa, items, ...). */
    public function entities(): JsonResponse
    {
        return response()->json(['data' => [
            'entities' => $this->service->entityTypes(),
            'schemas' => $this->service->entitySchemas(),
        ]]);
    }

    /** Validate a CSV without writing anything — returns preview + row errors. */
    public function dryRun(Request $request, string $entity): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:8192']]);
        try {
            $result = $this->service->dryRun($entity, $request->file('file'));
        } catch (QueryException $e) {
            return $this->databaseFailure($e, 'preview');
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }

    /** Commit a CSV atomically. If any row is invalid, imports nothing (422). */
    public function commit(Request $request, string $entity): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:8192']]);
        try {
            $result = $this->service->commit($entity, $request->file('file'), $request->user());
        } catch (QueryException $e) {
            return $this->databaseFailure($e, 'commit');
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($result['batch'] === null) {
            return response()->json([
                'message' => 'Import rejected — fix the '.count($result['errors']).' error row(s) and re-upload. Nothing was imported.',
                'errors' => $result['errors'],
                'total' => $result['total'],
            ], 422);
        }

        return response()->json([
            'data' => [
                'batch_id' => $result['batch']->hash_id,
                'total' => $result['total'],
                'imported' => $result['imported'],
            ],
            'message' => "Imported {$result['imported']} row(s).",
        ], 201);
    }

    public function index(): JsonResponse
    {
        $batches = ImportBatch::query()
            ->with('creator:id,name')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (ImportBatch $b) => [
                'id' => $b->hash_id,
                'entity_type' => $b->entity_type,
                'filename' => $b->filename,
                'status' => $b->status,
                'status_label' => Str::headline((string) $b->status),
                'total_rows' => $b->total_rows,
                'imported_rows' => $b->imported_rows,
                'created_by' => $b->creator?->name,
                'created_at' => optional($b->created_at)->toIso8601String(),
                'rolled_back_at' => optional($b->rolled_back_at)->toIso8601String(),
            ]);

        return response()->json(['data' => $batches]);
    }

    public function rollback(Request $request, ImportBatch $batch): JsonResponse
    {
        try {
            $this->service->rollback($batch, $request->user());
        } catch (QueryException $e) {
            // Only a PostgreSQL foreign-key violation means imported data is
            // now referenced. Connection, timeout, and schema errors must
            // reach Laravel's exception reporter and sanitized 500 renderer.
            if ((string) $e->getCode() !== '23503') {
                return $this->databaseFailure($e, 'rollback');
            }

            return response()->json([
                'message' => 'Cannot roll back — some imported records are already referenced by other data.',
            ], 409);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Batch rolled back.']);
    }

    private function databaseFailure(QueryException $exception, string $operation): JsonResponse
    {
        report($exception);

        return response()->json([
            'message' => "The import {$operation} could not be completed. Try again later or contact support.",
        ], 500);
    }
}
