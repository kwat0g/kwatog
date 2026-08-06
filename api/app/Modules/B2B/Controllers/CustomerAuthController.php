<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Services\B2bAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerAuthController
{
    public function __construct(private readonly B2bAuthService $auth) {}

    /**
     * POST /api/v1/b2b/customer/login
     * Authenticate customer portal user and return a Sanctum API token.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $result = $this->auth->login(
            CustomerPortalUser::class,
            $data['email'],
            $data['password'],
            $request,
            'customer-portal',
            'customer',
        );

        /** @var CustomerPortalUser $user */
        $user = $result['user'];

        return response()->json([
            'data' => [
                'token' => $result['token'],
                'user'  => [
                    'id'          => $user->hash_id,
                    'name'        => $user->name,
                    'email'       => $user->email,
                    'customer_id' => app('hashids')->encode((int) $user->customer_id),
                ],
            ],
        ]);
    }

    /**
     * POST /api/v1/b2b/customer/logout
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var \App\Modules\B2B\Models\CustomerPortalUser $user */
        $user = $request->user('customer_portal');
        $user?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * GET /api/v1/b2b/customer/me
     */
    public function me(Request $request): JsonResponse
    {
        /** @var \App\Modules\B2B\Models\CustomerPortalUser $user */
        $user = $request->user('customer_portal')->load('customer:id,name');

        return response()->json([
            'data' => [
                'id'            => $user->hash_id,
                'name'          => $user->name,
                'email'         => $user->email,
                'customer_id'   => app('hashids')->encode((int) $user->customer_id),
                'customer_name' => $user->customer?->name,
            ],
        ]);
    }
}
