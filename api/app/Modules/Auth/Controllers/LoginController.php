<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Resources\UserResource;
use App\Modules\Auth\Services\AuthService;

class LoginController
{
    public function __construct(private readonly AuthService $auth) {}

    public function __invoke(LoginRequest $request): UserResource
    {
        $user = $this->auth->login(
            email: $request->validated('email'),
            password: $request->validated('password'),
            request: $request,
        );

        return new UserResource($user);
    }
}
