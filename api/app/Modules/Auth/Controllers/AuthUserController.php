<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Resources\UserResource;
use Illuminate\Http\Request;

class AuthUserController
{
    public function __invoke(Request $request): UserResource
    {
        return new UserResource($request->user()->load(['role.permissions']));
    }
}
