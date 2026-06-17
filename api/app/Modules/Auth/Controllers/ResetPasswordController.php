<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Requests\ResetPasswordRequest;
use App\Modules\Auth\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;

class ResetPasswordController
{
    public function __construct(private readonly PasswordResetService $service) {}

    public function __invoke(ResetPasswordRequest $request): JsonResponse
    {
        $this->service->reset(
            $request->validated('token'),
            $request->validated('password'),
            $request,
        );

        return response()->json([
            'message' => 'Your password has been reset. You can now sign in.',
        ]);
    }
}
