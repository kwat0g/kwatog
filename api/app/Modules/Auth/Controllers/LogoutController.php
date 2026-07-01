<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogoutController
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * @OA\Post(
     *     path="/auth/logout",
     *     tags={"Authentication"},
     *     summary="Logout current user",
     *     description="Invalidates the current session and clears auth cookies.",
     *     security={{"sanctum":{}}},
     *     @OA\Response(response=204, description="Logged out successfully"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function __invoke(Request $request): JsonResponse
    {
        $this->auth->logout($request);
        return response()->json(null, 204);
    }
}
