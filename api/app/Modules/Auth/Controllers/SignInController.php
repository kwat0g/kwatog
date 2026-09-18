<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Resources\UserResource;
use App\Modules\Auth\Services\UnifiedSignInService;
use Illuminate\Http\JsonResponse;

final class SignInController
{
    public function __construct(private readonly UnifiedSignInService $signIn) {}

    /**
     * Authenticate an Ogami account without asking the user to choose a realm.
     * The response keeps the internal and portal session guards separate.
     */
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $result = $this->signIn->login(
            email: $request->validated('email'),
            password: $request->validated('password'),
            request: $request,
        );

        $user = $result['user'];

        return response()->json([
            'data' => [
                'realm' => $result['realm'],
                'must_change_password' => (bool) $user->getAttribute('must_change_password'),
                'user' => $result['realm'] === 'internal'
                    ? (new UserResource($user))->resolve($request)
                    : null,
            ],
        ]);
    }
}
