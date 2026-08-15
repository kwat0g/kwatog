<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Accounting\Services\SourceReferenceRegistry;
use Illuminate\Console\Command;

final class ReconcileSourceReferences extends Command
{
    protected $signature = 'audit:source-references {--json : Emit machine-readable output}';

    protected $description = 'Report unresolved journal-entry and stock-movement source references';

    public function handle(): int
    {
        $orphans = SourceReferenceRegistry::reconcile();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => $orphans === [] ? 'pass' : 'fail',
                'orphan_count' => count($orphans),
                'orphans' => $orphans,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } elseif ($orphans === []) {
            $this->info('All journal and stock-movement source references resolve.');
        } else {
            $this->table(
                ['ledger', 'id', 'reference_type', 'reference_id', 'reason'],
                array_map(static fn (array $row): array => [
                    $row['ledger'],
                    $row['id'],
                    $row['reference_type'] ?? '',
                    $row['reference_id'] ?? '',
                    $row['reason'],
                ], $orphans),
            );
        }

        return $orphans === [] ? self::SUCCESS : self::FAILURE;
    }
}
