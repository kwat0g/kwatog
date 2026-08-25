<?php

declare(strict_types=1);

namespace App\Common\Services\Export;

use Closure;
use InvalidArgumentException;
use App\Modules\Auth\Models\User;
use Illuminate\Validation\ValidationException;

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

    /** @var array<string, array{implementation: class-string, permission: string, filters: array<int, string>}> */
    private static array $modules = [];

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

    /**
     * Register the complete runtime contract for an export module.
     *
     * A column list without an implementation or permission is useful for
     * registry unit tests and discovery, but it must never be enough to make
     * a production export runnable. Keeping the metadata beside the columns
     * prevents the HTTP controller, scheduler, and runner from drifting.
     *
     * @param class-string $implementation
     * @param array<int, string> $filters
     */
    public static function registerModule(
        string $module,
        string $implementation,
        string $permission,
        array $columns,
        array $filters = [],
    ): void {
        self::register($module, $columns);
        self::$modules[$module] = [
            'implementation' => $implementation,
            'permission' => $permission,
            'filters' => array_values(array_unique($filters)),
        ];
    }

    public static function has(string $module): bool
    {
        return isset(self::$registry[$module]);
    }

    public static function hasImplementation(string $module): bool
    {
        return isset(self::$modules[$module]);
    }

    /**
     * Module keys that carry a complete runtime contract (implementation +
     * permission + filters). This is what "advertised as runnable" means, and
     * the drift guard iterates it so a new module cannot be shipped without
     * its class, permission, resolvers, and tests.
     *
     * @return array<int, string>
     */
    public static function modules(): array
    {
        return array_keys(self::$modules);
    }

    /** @return array<int, string> */
    public static function filtersFor(string $module): array
    {
        return self::$modules[$module]['filters'] ?? [];
    }

    public static function implementationFor(string $module): ?string
    {
        return self::$modules[$module]['implementation'] ?? null;
    }

    public static function permissionFor(string $module): ?string
    {
        return self::$modules[$module]['permission'] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function for(string $module): array
    {
        return self::$registry[$module] ?? [];
    }

    /** @return array<string, array<string, mixed>> */
    public static function forUser(string $module, User $user): array
    {
        return array_filter(
            self::for($module),
            static function (array $definition) use ($user): bool {
                $permission = $definition['permission'] ?? null;
                return ! is_string($permission) || $user->can($permission);
            },
        );
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

    /**
     * Validate caller-selected columns at the common module boundary.
     *
     * Unknown keys are rejected instead of being humanized and resolved as a
     * model property. A permission-bearing column is also rejected unless the
     * acting user has that explicit capability.
     *
     * @param array<int, mixed> $columns
     * @return array<int, string>
     */
    public static function validateColumns(string $module, array $columns, ?User $user = null): array
    {
        if (! self::has($module)) {
            throw ValidationException::withMessages([
                'module' => "Unknown export module [{$module}].",
            ]);
        }

        if ($columns === []) {
            throw ValidationException::withMessages([
                'columns' => 'Select at least one export column.',
            ]);
        }

        $errors = [];
        $seen = [];
        $available = self::for($module);

        foreach ($columns as $index => $column) {
            if (! is_string($column) || trim($column) === '') {
                $errors["columns.{$index}"][] = 'Each export column must be a non-empty string.';
                continue;
            }

            if (isset($seen[$column])) {
                $errors["columns.{$index}"][] = "Column [{$column}] was selected more than once.";
                continue;
            }
            $seen[$column] = true;

            if (! array_key_exists($column, $available)) {
                $errors["columns.{$index}"][] = "Column [{$column}] is not available for module [{$module}].";
                continue;
            }

            $requiredPermission = $available[$column]['permission'] ?? null;
            if (is_string($requiredPermission) && ($user === null || ! $user->can($requiredPermission))) {
                $errors["columns.{$index}"][] = "Column [{$column}] requires permission [{$requiredPermission}].";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return array_values($columns);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function validateFilters(string $module, array $filters): array
    {
        $allowed = self::filtersFor($module);
        $unknown = array_values(array_diff(array_keys($filters), $allowed));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'filters' => 'Unsupported export filter(s): '.implode(', ', $unknown).'.',
            ]);
        }

        return $filters;
    }

    /** Reset for tests. */
    public static function reset(): void
    {
        self::$registry = [];
        self::$modules = [];
    }
}
