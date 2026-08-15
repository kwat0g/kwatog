<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\B2B\Services\PortalInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalAccessController
{
    public function __construct(private readonly PortalInvitationService $invitations) {}

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
                'temporary_password' => $result['temporary_password'],
            ],
        ], 201);
    }

    public function inviteSupplier(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:255'],
        ]);
        $result = $this->invitations->inviteSupplier($vendor, $data['name'], $data['email']);

        return response()->json([
            'message' => 'Supplier portal invitation queued.',
            'data' => [
                'id' => $result['user']->hash_id,
                'name' => $result['user']->name,
                'email' => $result['user']->email,
                'temporary_password' => $result['temporary_password'],
            ],
        ], 201);
    }
}
