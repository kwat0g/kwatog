<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Requests\ForgotPasswordRequest;
use App\Modules\Auth\Services\UnifiedPasswordResetRequestService;
use Illuminate\Http\JsonResponse;

class ForgotPasswordController
{
    public function __construct(private readonly UnifiedPasswordResetRequestService $service) {}

    public function __invoke(ForgotPasswordRequest $request): JsonResponse
    {
        $this->service->send($request->validated('email'), $request);

        return response()->json([
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ]);
    }
}
