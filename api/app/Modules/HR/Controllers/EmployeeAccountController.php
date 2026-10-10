<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\Admin\Services\UserAdminService;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Requests\BulkProvisionAccountsRequest;
use App\Modules\HR\Requests\ProvisionAccountRequest;
use App\Modules\HR\Resources\EmployeeAccountStatusResource;
use App\Modules\HR\Services\UserProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * U1 — system account lifecycle for an employee.
 * Account mutations reuse the administrator safeguards and audit trail.
 */
class EmployeeAccountController
{
    public function __construct(
        private readonly UserProvisioningService $provisioning,
        private readonly UserAdminService $userAdministration,
    ) {}

    public function status(Employee $employee): EmployeeAccountStatusResource
    {
        return new EmployeeAccountStatusResource(
            $this->provisioning->accountStatusForEmployee($employee),
        );
    }

    public function provision(ProvisionAccountRequest $request, Employee $employee): JsonResponse
    {
        try {
            $user = $this->provisioning->provisionForEmployee($employee, $request->payload());
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'message' => ($request->boolean('send_welcome', true) ? 'Account created. Welcome notification queued.' : 'Account created.'),
            'data' => [
                'id'    => $user->hash_id,
                'email' => $user->email,
                'name'  => $user->name,
                'role'  => $user->role ? [
                    'id'   => $user->role->hash_id,
                    'name' => $user->role->name,
                    'slug' => $user->role->slug,
                ] : null,
            ],
        ], 201);
    }

    public function deactivate(Request $request, Employee $employee): JsonResponse
    {
        $user = $employee->user()->first();
        if ($user) {
            $this->userAdministration->deactivate($user, $request->user());
        }
        return response()->json(null, 204);
    }

    public function resetPassword(Request $request, Employee $employee): JsonResponse
    {
        $user = $employee->user()->first();
        abort_if(! $user, 404, 'Employee has no system account.');
        $this->userAdministration->resetPassword($user, $request->user());
        return response()->json([
            'message' => 'Password reset. A notification was queued for the user.',
            'delivery_status' => 'queued',
            'sent_to' => $user->email,
        ]);
    }

    public function bulkProvision(BulkProvisionAccountsRequest $request): JsonResponse
    {
        $results = $this->provisioning->bulkProvision(
            $request->decodedEmployeeIds(),
            ['send_welcome' => (bool) ($request->validated('send_welcome') ?? true)],
        );

        $summary = [
            'total'   => count($results),
            'success' => count(array_filter($results, fn ($r) => $r['status'] === 'success')),
            'skipped' => count(array_filter($results, fn ($r) => $r['status'] === 'skipped')),
            'failed'  => count(array_filter($results, fn ($r) => $r['status'] === 'failed')),
        ];

        return response()->json([
            'summary' => $summary,
            'results' => $results,
        ]);
    }
}
