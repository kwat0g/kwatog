<?php

declare(strict_types=1);

namespace App\Common\Services\Import;

/**
 * REC-03 — contract for a per-entity master-data importer.
 *
 * An importer is pure per-row logic: it declares its required columns, and
 * turns one CSV row (assoc array, lowercased keys) into a persisted model. The
 * generic ImportService owns the CSV parsing, dry-run/commit orchestration,
 * per-row error capture, batch tracking, and rollback — importers never touch
 * those.
 */
interface EntityImporter
{
    /** Stable slug used in the route + import_batches.entity_type. */
    public function key(): string;

    /** Column headers that MUST be present in the CSV (lowercased). */
    public function requiredColumns(): array;

    /**
     * Validate + persist one row. Throw on any problem — the ImportService
     * catches it and records a row error. On success return the created Model
     * (so the batch can track it for rollback).
     *
     * @param array<string, string> $row  lowercased-header => value
     */
    public function importRow(array $row): \Illuminate\Database\Eloquent\Model;
}
