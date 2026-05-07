<?php

declare(strict_types=1);

namespace App\Common\Services\Export;

use App\Common\Exports\BaseModuleExport;
use App\Modules\HR\Exports\EmployeeMasterExport;
use InvalidArgumentException;

/**
 * Series E (Task E2) — resolves a module key to its export class.
 *
 * Adding a new export module:
 *   1. Subclass BaseModuleExport.
 *   2. Add the mapping below.
 *   3. Register columns from your ServiceProvider boot().
 */
class ExportRunner
{
    /** @var array<string, class-string<BaseModuleExport>> */
    private const MAP = [
        EmployeeMasterExport::MODULE => EmployeeMasterExport::class,
    ];

    /**
     * @param  array<int, string>  $columns
     * @param  array<string, mixed>  $filters
     */
    public function build(string $module, array $columns, array $filters = []): BaseModuleExport
    {
        $class = self::MAP[$module] ?? null;
        if (! $class) {
            throw new InvalidArgumentException("No export class registered for module [{$module}].");
        }
        return new $class($columns, $filters);
    }

    /**
     * Render a small preview as plain rows for the SPA's column-selector
     * modal. Stays an array so we don't have to spin up a real xlsx.
     *
     * @param  array<int, string>  $columns
     * @param  array<string, mixed>  $filters
     * @return array<int, array<int, mixed>>
     */
    public function preview(string $module, array $columns, array $filters, int $limit = 20): array
    {
        $exporter = $this->build($module, $columns, $filters);
        $records = $exporter->collection();
        $rows = [];
        foreach ($records->take($limit) as $rec) {
            $rows[] = $exporter->map($rec);
        }
        return $rows;
    }
}
