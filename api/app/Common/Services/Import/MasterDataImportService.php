<?php

declare(strict_types=1);

namespace App\Common\Services\Import;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\ImportBatch;
use App\Common\Models\ImportBatchRecord;
use App\Modules\Accounting\Imports\AccountImporter;
use App\Modules\Accounting\Imports\CustomerImporter;
use App\Modules\Accounting\Imports\VendorImporter;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Imports\EmployeeImporter;
use App\Modules\Inventory\Imports\ItemImporter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * REC-03 — generic master-data CSV import pipeline.
 *
 * Owns CSV parsing, dry-run validation, atomic commit, batch tracking, and
 * rollback. Per-entity logic lives in EntityImporter implementations, resolved
 * from the registry below. Adding a new entity = write an importer + register
 * it here (customers/vendors/employees/BOMs/molds/machines are the follow-on).
 *
 * Master-data cutover is all-or-nothing: if ANY row is invalid, commit()
 * imports nothing and returns the full error list, so a partial chart of
 * accounts or item master can never be created.
 */
class MasterDataImportService
{
    /** @var array<string, class-string<EntityImporter>> */
    private const REGISTRY = [
        'coa'       => AccountImporter::class,
        'items'     => ItemImporter::class,
        'customers' => CustomerImporter::class,
        'vendors'   => VendorImporter::class,
        'employees' => EmployeeImporter::class,
    ];

    /** @return array<int, string> */
    public function entityTypes(): array
    {
        return array_keys(self::REGISTRY);
    }

    private function importer(string $entityType): EntityImporter
    {
        $class = self::REGISTRY[$entityType] ?? null;
        if (! $class) {
            throw new RuntimeException("Unknown import entity '{$entityType}'. Supported: ".implode(', ', $this->entityTypes()));
        }
        return app($class);
    }

    /**
     * Validate a CSV without writing anything.
     *
     * @return array{total:int, valid:int, errors:array<int,array{row:int,message:string}>, columns:array<int,string>}
     */
    public function dryRun(string $entityType, UploadedFile $file): array
    {
        $importer = $this->importer($entityType);
        [$header, $rows] = $this->parse($file, $importer);

        $errors = [];
        $valid = 0;
        // Dry-run validates inside a transaction that is ALWAYS rolled back, so
        // uniqueness/relationship checks that hit the DB are realistic without
        // persisting anything.
        DB::beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                try {
                    $importer->importRow($row);
                    $valid++;
                } catch (Throwable $e) {
                    $errors[] = ['row' => $i + 2, 'message' => $e->getMessage()]; // +2: header + 1-index
                }
            }
        } finally {
            DB::rollBack();
        }

        return ['total' => count($rows), 'valid' => $valid, 'errors' => $errors, 'columns' => $header];
    }

    /**
     * Commit a CSV atomically. If any row is invalid, NOTHING is imported and
     * the batch is not created — the returned array carries the errors.
     *
     * @return array{batch: ?ImportBatch, total:int, imported:int, errors:array<int,array{row:int,message:string}>}
     */
    public function commit(string $entityType, UploadedFile $file, User $by): array
    {
        $importer = $this->importer($entityType);
        [, $rows] = $this->parse($file, $importer);

        return DB::transaction(function () use ($entityType, $file, $rows, $importer, $by) {
            $batch = ImportBatch::create([
                'entity_type'   => $entityType,
                'filename'      => $file->getClientOriginalName(),
                'status'        => 'committed',
                'total_rows'    => count($rows),
                'imported_rows' => 0,
                'created_by'    => $by->id,
            ]);

            $errors = [];
            $created = [];
            foreach ($rows as $i => $row) {
                try {
                    $model = $importer->importRow($row);
                    ImportBatchRecord::create([
                        'import_batch_id' => $batch->id,
                        'recordable_type' => $model::class,
                        'recordable_id'   => $model->getKey(),
                    ]);
                    $created[] = $model;
                } catch (Throwable $e) {
                    $errors[] = ['row' => $i + 2, 'message' => $e->getMessage()];
                }
            }

            if (! empty($errors)) {
                // All-or-nothing: abort the whole batch.
                DB::rollBack();
                return ['batch' => null, 'total' => count($rows), 'imported' => 0, 'errors' => $errors];
            }

            $batch->update(['imported_rows' => count($created)]);
            return ['batch' => $batch->fresh(), 'total' => count($rows), 'imported' => count($created), 'errors' => []];
        });
    }

    /**
     * Roll back a committed batch — delete exactly the models it created.
     * Fails if any created record is now referenced elsewhere (FK restrict):
     * once master data is in use it cannot be silently removed.
     */
    public function rollback(ImportBatch $batch, User $by): ImportBatch
    {
        if ($batch->status === 'rolled_back') {
            throw new BusinessRuleException('Batch already rolled back.');
        }

        DB::transaction(function () use ($batch, $by) {
            foreach ($batch->records()->get() as $rec) {
                $model = $rec->recordable_type::find($rec->recordable_id);
                $model?->delete();
                $rec->delete();
            }
            $batch->update([
                'status'         => 'rolled_back',
                'rolled_back_at' => now(),
                'rolled_back_by' => $by->id,
            ]);
        });

        return $batch->fresh();
    }

    /**
     * Parse CSV into a lowercased-header assoc array; validates required cols.
     *
     * @return array{0: array<int,string>, 1: array<int, array<string,string>>}
     */
    private function parse(UploadedFile $file, EntityImporter $importer): array
    {
        $stream = fopen($file->getRealPath(), 'r');
        if ($stream === false) {
            throw new RuntimeException('Could not open uploaded file.');
        }

        try {
            $header = fgetcsv($stream);
            if (! $header) {
                throw new RuntimeException('Empty CSV — no header row.');
            }
            $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

            $missing = array_diff($importer->requiredColumns(), $header);
            if ($missing) {
                throw new RuntimeException('Missing required column(s): '.implode(', ', $missing));
            }

            $rows = [];
            while (($line = fgetcsv($stream)) !== false) {
                // Skip fully-blank lines.
                if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }
                $assoc = [];
                foreach ($header as $col => $name) {
                    $assoc[$name] = isset($line[$col]) ? (string) $line[$col] : '';
                }
                $rows[] = $assoc;
            }
            return [$header, $rows];
        } finally {
            fclose($stream);
        }
    }
}
