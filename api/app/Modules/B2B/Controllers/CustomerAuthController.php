<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;
use App\Common\Rules\StrongPassword;

use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Services\B2bAuthService;
use App\Modules\B2B\Services\PortalPasswordResetService;
use App\Modules\B2B\Services\PortalPasswordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerAuthController
{
    public function __construct(private readonly B2bAuthService $auth) {}

    public function forgotPassword(Request $request, PortalPasswordResetService $resets): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $resets->requestReset('customer', $data['email']);

        return response()->json(['message' => 'If an active portal account exists for that email, a reset link will be sent shortly.']);
    }

    public function resetPassword(Request $request, PortalPasswordResetService $resets): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', new StrongPassword()],
        ]);
        $resets->reset('customer', $data['token'], $data['password']);

        return response()->json(['message' => 'Portal password updated. You can now sign in.']);
    }

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
                    'must_change_password' => $user->must_change_password,
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

    public function changePassword(Request $request, PortalPasswordService $passwords): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'confirmed', new StrongPassword()],
            'new_password_confirmation' => ['required', 'string'],
        ]);

        $passwords->change($request->user('customer_portal'), $data['current_password'], $data['new_password'], $request);

        return response()->json(['message' => 'Password updated successfully. Please sign in again.']);
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
                'must_change_password' => $user->must_change_password,
            ],
        ]);
    }
}
