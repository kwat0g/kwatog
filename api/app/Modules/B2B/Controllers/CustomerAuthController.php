<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\B2B\Models\CustomerPortalUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CustomerAuthController
{
    /**
     * POST /api/v1/b2b/customer/login
     * Authenticate customer portal user and return a Sanctum API token.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = CustomerPortalUser::query()
            ->where('email', $request->input('email'))
            ->where('is_active', true)
            ->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Revoke old tokens
        $user->tokens()->delete();

        $token = $user->createToken('customer-portal')->plainTextToken;

        $user->update(['last_login_at' => now()]);

        return response()->json([
            'data' => [
                'token'      => $token,
                'user'       => [
                    'id'          => $user->id,
                    'name'        => $user->name,
                    'email'       => $user->email,
                    'customer_id' => $user->customer_id,
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
                'id'            => $user->id,
                'name'          => $user->name,
                'email'         => $user->email,
                'customer_id'   => $user->customer_id,
                'customer_name' => $user->customer?->name,
            ],
        ]);
    }
}
