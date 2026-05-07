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
            // Filter against registry to drop stale keys.
            $available = array_keys(ExportColumnRegistry::for($module));
            $filtered = array_values(array_intersect($pref->columns, $available));
            if ($filtered !== []) {
                return $filtered;
            }
        }
        return ExportColumnRegistry::defaultsFor($module);
    }

    /**
     * @param  array<int, string>  $columns
     */
    public function save(User $user, string $module, array $columns): ExportColumnPreference
    {
        $available = array_keys(ExportColumnRegistry::for($module));
        $clean = array_values(array_unique(array_intersect($columns, $available)));

        return ExportColumnPreference::updateOrCreate(
            ['user_id' => $user->id, 'module' => $module],
            ['columns' => $clean],
        );
    }
}
