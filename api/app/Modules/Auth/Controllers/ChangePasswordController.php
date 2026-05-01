<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Requests\ChangePasswordRequest;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\JsonResponse;

class ChangePasswordController
{
    public function __construct(private readonly AuthService $auth) {}

    public function __invoke(ChangePasswordRequest $request): JsonResponse
    {
        $this->auth->changePassword(
            user: $request->user(),
            current: $request->string('current_password')->toString(),
            new: $request->string('new_password')->toString(),
            request: $request,
        );

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }
}
