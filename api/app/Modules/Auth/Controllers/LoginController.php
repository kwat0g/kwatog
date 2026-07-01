<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Resources\UserResource;
use App\Modules\Auth\Services\AuthService;

class LoginController
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * @OA\Post(
     *     path="/auth/login",
     *     tags={"Authentication"},
     *     summary="Authenticate user",
     *     description="Validates credentials, creates a session, and returns the authenticated user. Uses Sanctum SPA cookie-based auth.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email", "password"},
     *             @OA\Property(property="email", type="string", format="email", example="admin@ogami.ph"),
     *             @OA\Property(property="password", type="string", format="password", example="password")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Login successful — returns authenticated user resource"),
     *     @OA\Response(response=422, description="Invalid credentials or validation error"),
     *     @OA\Response(response=429, description="Too many login attempts — account temporarily locked")
     * )
     */
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
