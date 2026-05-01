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
        $user->fill($request->validated())->save();
        return new UserResource($user->load('role.permissions'));
    }
}
