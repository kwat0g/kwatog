<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Requests\UpdatePreferencesRequest;
use App\Modules\Auth\Resources\UserResource;

class PreferencesController
{
    public function update(UpdatePreferencesRequest $request): UserResource
    {
        $user = $request->user();
        $preferences = $request->validated();
        if (array_key_exists('table_preferences', $preferences)) {
            $tablePreferences = $user->table_preferences ?? [];
            foreach ($preferences['table_preferences'] as $tableKey => $tablePreference) {
                if ($tablePreference === null) {
                    unset($tablePreferences[$tableKey]);
                    continue;
                }
                $tablePreferences[$tableKey] = array_replace($tablePreferences[$tableKey] ?? [], $tablePreference);
            }
            $user->table_preferences = $tablePreferences;
            unset($preferences['table_preferences']);
        }
        $user->fill($preferences)->save();
        return new UserResource($user->load('role.permissions'));
    }
}
