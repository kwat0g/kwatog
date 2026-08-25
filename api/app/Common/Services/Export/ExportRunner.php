<?php

declare(strict_types=1);

namespace App\Common\Services\Export;

use App\Common\Exports\BaseModuleExport;
use App\Modules\Auth\Models\User;
use Illuminate\Validation\ValidationException;
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
    public function supports(string $module): bool
    {
        $class = ExportColumnRegistry::implementationFor($module);

        return is_string($class) && is_a($class, BaseModuleExport::class, true);
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<string, mixed>  $filters
     */
    public function build(string $module, array $columns, array $filters = [], ?User $actor = null): BaseModuleExport
    {
        $class = ExportColumnRegistry::implementationFor($module);
        if (! $this->supports($module) || ! is_string($class)) {
            throw new InvalidArgumentException("No export class registered for module [{$module}].");
        }

        // The module capability is re-checked here, not just at the HTTP edge,
        // because a saved schedule can outlive the grant that created it. A
        // background run replaying stored JSON must not be a path around the
        // permission the direct download enforces. A null actor is a system
        // path (console/seeder) and is scoped by the exporter itself; every
        // caller that acts for a person passes that person.
        $permission = ExportColumnRegistry::permissionFor($module);
        if ($actor !== null && is_string($permission) && ! $actor->can($permission)) {
            throw ValidationException::withMessages([
                'module' => "Account cannot export module [{$module}]; permission [{$permission}] is required.",
            ]);
        }

        $columns = ExportColumnRegistry::validateColumns($module, $columns, $actor);
        $filters = ExportColumnRegistry::validateFilters($module, $filters);

        return new $class($columns, $filters, $actor);
    }

    /**
     * Render a small preview as plain rows for the SPA's column-selector
     * modal. Stays an array so we don't have to spin up a real xlsx.
     *
     * @param  array<int, string>  $columns
     * @param  array<string, mixed>  $filters
     * @return array<int, array<int, mixed>>
     */
    public function preview(string $module, array $columns, array $filters, int $limit = 20, ?User $actor = null): array
    {
        $exporter = $this->build($module, $columns, $filters, $actor);
        $records = $exporter->collection();
        $rows = [];
        foreach ($records->take($limit) as $rec) {
            $rows[] = $exporter->map($rec);
        }
        return $rows;
    }
}
