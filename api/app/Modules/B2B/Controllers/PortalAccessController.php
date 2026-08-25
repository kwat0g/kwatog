<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\B2B\Services\PortalInvitationService;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Resources\SupplierPortalUserResource;
use App\Modules\B2B\Services\PortalAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PortalAccessController
{
    public function __construct(
        private readonly PortalInvitationService $invitations,
        private readonly PortalAccessService $access,
    ) {}

    public function inviteCustomer(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:255'],
        ]);
        $result = $this->invitations->inviteCustomer($customer, $data['name'], $data['email']);

        return response()->json([
            'message' => 'Customer portal invitation queued.',
            'data' => [
                'id' => $result['user']->hash_id,
                'name' => $result['user']->name,
                'email' => $result['user']->email,
            ],
        ], 201);
    }

    public function inviteSupplier(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:255'],
        ]);
        /** @var \App\Modules\Auth\Models\User $actor */
        $actor = $request->user('sanctum');
        $user = $this->access->inviteSupplier($vendor, $data['name'], $data['email'], $actor, $request);

        return response()->json([
            'message' => 'Supplier portal invitation queued.',
            'data' => (new SupplierPortalUserResource($user))->toArray($request),
        ], 201);
    }

    public function suppliers(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive,locked,pending'],
            'vendor_id' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return SupplierPortalUserResource::collection($this->access->suppliers($filters));
    }

    public function resendSupplier(Request $request, SupplierPortalUser $supplierPortalUser): JsonResponse
    {
        /** @var \App\Modules\Auth\Models\User $actor */
        $actor = $request->user('sanctum');
        $user = $this->access->resendSupplier($supplierPortalUser, $actor, $request);

        return response()->json(['message' => 'Supplier portal invitation re-sent.', 'data' => (new SupplierPortalUserResource($user))->toArray($request)]);
    }

    public function deactivateSupplier(Request $request, SupplierPortalUser $supplierPortalUser): JsonResponse
    {
        /** @var \App\Modules\Auth\Models\User $actor */
        $actor = $request->user('sanctum');
        $user = $this->access->deactivate($supplierPortalUser, $actor, $request);

        return response()->json(['message' => 'Supplier portal access deactivated.', 'data' => (new SupplierPortalUserResource($user))->toArray($request)]);
    }

    public function reactivateSupplier(Request $request, SupplierPortalUser $supplierPortalUser): JsonResponse
    {
        /** @var \App\Modules\Auth\Models\User $actor */
        $actor = $request->user('sanctum');
        $user = $this->access->reactivate($supplierPortalUser, $actor, $request);

        return response()->json(['message' => 'Supplier portal access reactivated. A new password must be set.', 'data' => (new SupplierPortalUserResource($user))->toArray($request)]);
    }

    public function revokeSupplierTokens(Request $request, SupplierPortalUser $supplierPortalUser): JsonResponse
    {
        /** @var \App\Modules\Auth\Models\User $actor */
        $actor = $request->user('sanctum');
        $user = $this->access->revokeTokens($supplierPortalUser, $actor, $request);

        return response()->json(['message' => 'Supplier portal sessions revoked.', 'data' => (new SupplierPortalUserResource($user))->toArray($request)]);
    }
}
