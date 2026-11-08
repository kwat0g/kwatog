<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Requests\UpdatePreferencesRequest;
use App\Modules\Auth\Resources\UserResource;
use App\Modules\Auth\Services\UserPreferencesService;

class PreferencesController
{
    public function update(UpdatePreferencesRequest $request, UserPreferencesService $service): UserResource
    {
        return new UserResource($service->update($request->user(), $request->validated()));
    }
}
