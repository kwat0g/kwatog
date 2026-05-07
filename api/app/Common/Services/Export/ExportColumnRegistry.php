<?php

declare(strict_types=1);

namespace App\Common\Services\Export;

use Closure;
use InvalidArgumentException;

/**
 * Series E (Task E2) — single source of truth for "what columns are
 * available in module X". Each module registers its columns in its
 * ServiceProvider's `boot()`. ColumnSelectorModal on the SPA reads the
 * same definitions to render checkboxes.
 *
 * Registration shape:
 *   ExportColumnRegistry::register('hr.employees', [
 *     'employee_no' => [
 *        'label'   => 'Employee No.',
 *        'default' => true,
 *        'format'  => 'text',
 *        'resolver' => fn($e) => $e->employee_no,
 *     ],
 *     ...
 *   ]);
 */
class ExportColumnRegistry
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private static array $registry = [];

    /**
     * @param  string  $module  e.g. "hr.employees"
     * @param  array<string, array{label: string, default?: bool, format?: string, resolver?: Closure}>  $columns
     */
    public static function register(string $module, array $columns): void
    {
        // Ensure every column has at minimum `label`.
        foreach ($columns as $key => $def) {
            if (! isset($def['label']) || ! is_string($def['label'])) {
                throw new InvalidArgumentException("Column [{$key}] in module [{$module}] is missing a label.");
            }
        }
        self::$registry[$module] = $columns;
    }

    public static function has(string $module): bool
    {
        return isset(self::$registry[$module]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function for(string $module): array
    {
        return self::$registry[$module] ?? [];
    }

    /**
     * @return array<int, string>  Column keys flagged default=true.
     */
    public static function defaultsFor(string $module): array
    {
        $cols = self::for($module);
        $defaults = [];
        foreach ($cols as $key => $def) {
            if (! empty($def['default'])) {
                $defaults[] = $key;
            }
        }
        return $defaults;
    }

    /** Reset for tests. */
    public static function reset(): void
    {
        self::$registry = [];
    }
}
