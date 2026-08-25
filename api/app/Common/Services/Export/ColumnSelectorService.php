<?php

declare(strict_types=1);

namespace App\Common\Services\Export;

use App\Common\Models\ExportColumnPreference;
use App\Modules\Auth\Models\User;

/**
 * Series E (Task E2) — get/set per-user column preferences.
 */
class ColumnSelectorService
{
    /**
     * Resolve the columns to use for a given (user, module) pair, in order:
     *   1. User's saved preference (if any)
     *   2. Registry defaults
     *
     * @return array<int, string>
     */
    public function resolve(User $user, string $module): array
    {
        $pref = ExportColumnPreference::query()
            ->where('user_id', $user->id)
            ->where('module', $module)
            ->first();

        if ($pref && is_array($pref->columns) && $pref->columns !== []) {
            return ExportColumnRegistry::validateColumns($module, $pref->columns, $user);
        }
        return ExportColumnRegistry::validateColumns(
            $module,
            ExportColumnRegistry::defaultsFor($module),
            $user,
        );
    }

    /**
     * @param  array<int, string>  $columns
     */
    public function save(User $user, string $module, array $columns): ExportColumnPreference
    {
        $clean = ExportColumnRegistry::validateColumns($module, $columns, $user);

        return ExportColumnPreference::updateOrCreate(
            ['user_id' => $user->id, 'module' => $module],
            ['columns' => $clean],
        );
    }
}
