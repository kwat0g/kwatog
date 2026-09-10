<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;
use App\Common\Rules\StrongPassword;

use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Resources\CustomerPortalUserResource;
use App\Modules\B2B\Services\B2bAuthService;
use App\Modules\B2B\Services\PortalPasswordResetService;
use App\Modules\B2B\Services\PortalPasswordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerAuthController
{
    public function __construct(private readonly B2bAuthService $auth) {}

    public function forgotPassword(Request $request, PortalPasswordResetService $resets): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $resets->requestReset('customer', $data['email'], $request);

        return response()->json(['message' => 'If an active portal account exists for that email, a reset link will be sent shortly.']);
    }

    public function resetPassword(Request $request, PortalPasswordResetService $resets): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', new StrongPassword()],
        ]);
        $resets->reset('customer', $data['token'], $data['password'], $request);

        return response()->json(['message' => 'Portal password updated. You can now sign in.']);
    }

    /**
     * POST /api/v1/b2b/customer/login
     * Authenticate customer portal user and establish an HTTP-only session.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = $this->auth->login(
            CustomerPortalUser::class,
            $data['email'],
            $data['password'],
            $request,
            'customer',
            'customer_portal',
        );

        /** @var CustomerPortalUser $user */
        $user = $user->load('customer:id,name');

        return response()->json([
            'data' => ['user' => new CustomerPortalUserResource($user)],
        ]);
    }

    /**
     * POST /api/v1/b2b/customer/logout
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('customer_portal')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

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

        Auth::guard('customer_portal')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Password updated successfully. Please sign in again.']);
    }

    /**
     * GET /api/v1/b2b/customer/me
     */
    public function me(Request $request): JsonResponse
    {
        /** @var \App\Modules\B2B\Models\CustomerPortalUser $user */
        $user = $request->user('customer_portal')->load('customer:id,name');

        return response()->json(['data' => new CustomerPortalUserResource($user)]);
    }
}
