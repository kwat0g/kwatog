<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\B2B\Models\SupplierPortalUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SupplierAuthController
{
    /**
     * POST /api/v1/b2b/supplier/login
     * Authenticate supplier portal user and return a Sanctum API token.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = SupplierPortalUser::query()
            ->where('email', $request->input('email'))
            ->where('is_active', true)
            ->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Revoke old tokens so each login generates a fresh one
        $user->tokens()->delete();

        $token = $user->createToken('supplier-portal')->plainTextToken;

        $user->update(['last_login_at' => now()]);

        return response()->json([
            'data' => [
                'token'      => $token,
                'user'       => [
                    'id'        => $user->id,
                    'name'      => $user->name,
                    'email'     => $user->email,
                    'vendor_id' => $user->vendor_id,
                ],
            ],
        ]);
    }

    /**
     * POST /api/v1/b2b/supplier/logout
     * Revoke the current API token.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var \App\Modules\B2B\Models\SupplierPortalUser $user */
        $user = $request->user('supplier_portal');
        $user?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * GET /api/v1/b2b/supplier/me
     * Return the authenticated supplier portal user's info.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var \App\Modules\B2B\Models\SupplierPortalUser $user */
        $user = $request->user('supplier_portal')->load('vendor:id,name');

        return response()->json([
            'data' => [
                'id'          => $user->id,
                'name'        => $user->name,
                'email'       => $user->email,
                'vendor_id'   => $user->vendor_id,
                'vendor_name' => $user->vendor?->name,
            ],
        ]);
    }
}
