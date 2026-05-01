<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogoutController
{
    public function __construct(private readonly AuthService $auth) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->auth->logout($request);
        return response()->json(null, 204);
    }
}
