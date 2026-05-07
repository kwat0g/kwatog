<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Requests\BulkProvisionAccountsRequest;
use App\Modules\HR\Requests\ProvisionAccountRequest;
use App\Modules\HR\Resources\EmployeeAccountStatusResource;
use App\Modules\HR\Services\UserProvisioningService;
use Illuminate\Http\JsonResponse;

/**
 * U1 — system account lifecycle for an employee.
 * Each method delegates to UserProvisioningService and returns a thin shape.
 */
class EmployeeAccountController
{
    public function __construct(
        private readonly UserProvisioningService $provisioning,
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
            'message' => 'Account created.',
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

    public function deactivate(Employee $employee): JsonResponse
    {
        $this->provisioning->deactivateForEmployee($employee);
        return response()->json(null, 204);
    }

    public function resetPassword(Employee $employee): JsonResponse
    {
        $this->provisioning->resetPasswordForEmployee($employee);
        return response()->json([
            'message' => 'Password reset. A new temporary password has been emailed to the user.',
            'sent_to' => $employee->user?->email,
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
