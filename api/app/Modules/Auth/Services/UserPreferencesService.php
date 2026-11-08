<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;

/** Persists self-service display preferences and stages their cross-tab ping. */
final class UserPreferencesService
{
    public function __construct(private readonly UserDisplayPreferencesRealtimeService $realtime) {}

    /** @param array<string, mixed> $preferences */
    public function update(User $user, array $preferences): User
    {
        return DB::transaction(function () use ($user, $preferences): User {
            // Display preferences are patched independently from several tabs
            // (table layout and saved views). Serialize the read/merge/write so
            // two quick changes cannot overwrite one another.
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            if (array_key_exists('table_preferences', $preferences)) {
                $tablePreferences = $lockedUser->table_preferences ?? [];
                foreach ($preferences['table_preferences'] as $tableKey => $tablePreference) {
                    if ($tablePreference === null) {
                        unset($tablePreferences[$tableKey]);
                        continue;
                    }
                    $existing = array_replace($tablePreferences[$tableKey] ?? [], $tablePreference);
                    // Saved views are an ordered list. Replace the list as a
                    // unit so removing a view cannot leave stale numeric keys.
                    if (array_key_exists('savedViews', $tablePreference)) {
                        $existing['savedViews'] = $tablePreference['savedViews'];
                    }
                    $tablePreferences[$tableKey] = $existing;
                }
                $lockedUser->table_preferences = $tablePreferences;
                unset($preferences['table_preferences']);
            }

            $lockedUser->fill($preferences)->save();
            if ($lockedUser->wasChanged(['theme_mode', 'sidebar_collapsed', 'sidebar_peek', 'sidebar_expand_all', 'table_preferences'])) {
                $this->realtime->stage($lockedUser);
            }

            return $lockedUser->load('role.permissions');
        });
    }
}
