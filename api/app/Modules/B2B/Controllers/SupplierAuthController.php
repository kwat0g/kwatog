<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Services\B2bAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// TODO: replace inline abort_if(...) tenancy guards in SupplierPortalController
// with a Vendor/Customer model scope (Phase 2 follow-up). Existing 50+ inline
// checks have been visually audited; a model-scope refactor narrows the blast
// radius if a future controller forgets the guard.
class SupplierAuthController
{
    public function __construct(private readonly B2bAuthService $auth) {}

    /**
     * POST /api/v1/b2b/supplier/login
     * Authenticate supplier portal user and return a Sanctum API token.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $result = $this->auth->login(
            SupplierPortalUser::class,
            $data['email'],
            $data['password'],
            $request,
            'supplier-portal',
            'supplier',
        );

        /** @var SupplierPortalUser $user */
        $user = $result['user'];

        return response()->json([
            'data' => [
                'token' => $result['token'],
                'user'  => [
                    'id'        => $user->hash_id,
                    'name'      => $user->name,
                    'email'     => $user->email,
                    'vendor_id' => app('hashids')->encode((int) $user->vendor_id),
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
                'id'          => $user->hash_id,
                'name'        => $user->name,
                'email'       => $user->email,
                'vendor_id'   => app('hashids')->encode((int) $user->vendor_id),
                'vendor_name' => $user->vendor?->name,
            ],
        ]);
    }
}
