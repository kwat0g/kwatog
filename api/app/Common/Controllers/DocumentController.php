<?php

declare(strict_types=1);

namespace App\Common\Controllers;

use App\Common\Models\Document;
use App\Common\Resources\DocumentResource;
use App\Common\Services\DocumentVaultService;
use App\Common\Support\DepartmentScope;
use App\Common\Support\HashIdFilter;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Services\PayrollPublicationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Series E (Task E3) — vault HTTP surface. Routes registered in
 * app/Modules/Admin/routes.php.
 *
 * Authorization model: vault rows are polymorphic, so we delegate to the
 * owning entity's existing permission gates rather than inventing a new
 * vault-level ACL. The mapping lives in `permissionFor()` below.
 */
class DocumentController
{
    public function __construct(
        private readonly DocumentVaultService $vault,
        private readonly PayrollPublicationPolicy $payrollPublication,
    ) {}

    /** GET /api/v1/documents — list (admin/audit). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless($user?->can('admin.audit_logs.view'), 403);

        $query = Document::query()->with('generatedBy:id,name');

        if ($type = $request->query('document_type')) {
            $query->where('document_type', $type);
        }
        if ($entity = $request->query('entity_type')) {
            $query->where('entity_type', 'like', '%'.$entity.'%');
        }
        if ($from = $request->query('from')) {
            $query->where('generated_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('generated_at', '<=', $to);
        }

        $perPage = min((int) $request->query('per_page', 25), 100);

        return DocumentResource::collection(
            $query->orderByDesc('generated_at')->paginate($perPage),
        );
    }

    /** GET /api/v1/documents/entity/{entityType}/{entityId}. */
    public function entityList(string $entityType, string $entityId, Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless($user, 401);

        $entityClass = match ($entityType) {
            'employee', 'employees' => Employee::class,
            default => null,
        };
        abort_unless($entityClass !== null, 404, 'Unsupported document entity.');

        $id = HashIdFilter::decode($entityId, $entityClass);
        abort_unless($id !== null, 404, 'Entity not found.');

        $entity = $entityClass::query()->findOrFail($id);
        $this->authorizeEntityList($entityType, $entity, $user);

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        return DocumentResource::collection(
            Document::query()
                ->forEntity($entity)
                ->with('generatedBy:id,name')
                ->orderByDesc('generated_at')
                ->paginate($perPage),
        );
    }

    /** GET /api/v1/documents/{document} — show metadata only. */
    public function show(Document $document, Request $request): DocumentResource
    {
        $this->authorizeAccess($document, $request);
        return new DocumentResource($document->load('generatedBy:id,name'));
    }

    /** GET /api/v1/documents/{document}/view — inline preview. */
    public function view(Document $document, Request $request): StreamedResponse
    {
        $this->authorizeAccess($document, $request);
        return $this->vault->streamInline($document);
    }

    /** GET /api/v1/documents/{document}/download — force download. */
    public function download(Document $document, Request $request): StreamedResponse
    {
        $this->authorizeAccess($document, $request);
        return $this->vault->streamDownload($document);
    }

    /** DELETE /api/v1/documents/{document} — admin only. */
    public function destroy(Document $document, Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('admin.audit_logs.view'), 403);
        $this->vault->delete($document);
        return response()->json(null, 204);
    }

    /**
     * Delegate to the entity's existing permission gate. Defaults to
     * `admin.audit_logs.view` if no mapping is found, which is a safe
     * fallback (admins can always view, regular users get 403 unless
     * the document type maps to one of their permissions).
     */
    private function authorizeAccess(Document $document, Request $request): void
    {
        $user = $request->user();
        if (! $user) abort(401);

        // System admin sees everything.
        if (method_exists($user, 'hasPermission') && $user->hasPermission('*')) {
            return;
        }

        $typeValue = $document->document_type instanceof \App\Common\Enums\DocumentType
            ? $document->document_type->value
            : (string) $document->document_type;

        $perm = $this->permissionFor($typeValue);

        $hasSensitivePayrollPermission = in_array($typeValue, ['payslip', 'bir_2316'], true)
            && ($user->can('payroll.payslip.view_all') || $user->can('hr.employees.view_sensitive'));
        abort_unless($user->can($perm) || $hasSensitivePayrollPermission, 403);

        // Payslip entity_id is a Payroll id, not an Employee id. Resolve the
        // owner before applying self-service/department scope; a hash id or a
        // forged entity pair is never an authorization boundary.
        if ($typeValue === 'payslip') {
            $payroll = $this->payrollForDocument($document);
            abort_unless($payroll !== null, 403);
            abort_unless($this->canViewPayroll($user, $payroll), 403);
        }

        // BIR 2316 vault rows, when produced by a future persisted path, must
        // name an Employee entity. Unknown polymorphic pairs fail closed.
        if ($typeValue === 'bir_2316') {
            $employeeExists = $document->entity_type === Employee::class
                && Employee::query()->whereKey($document->entity_id)->exists();
            abort_unless($employeeExists, 403);

            if (! $hasSensitivePayrollPermission) {
                abort_unless((int) $document->entity_id === (int) ($user->employee_id ?? 0), 403);
            }
        }
    }

    private function authorizeEntityList(string $entityType, Model $entity, \App\Modules\Auth\Models\User $user): void
    {
        if ($user->hasPermission('*')) {
            return;
        }

        if (in_array($entityType, ['employee', 'employees'], true)) {
            abort_unless($user->can('hr.employees.documents.view'), 403);

            $visible = Employee::query()->whereKey($entity->getKey());
            DepartmentScope::apply(
                $visible,
                $user,
                viewAllPermission: 'hr.employees.view_sensitive',
                departmentPermission: 'hr.employees.view',
                deptColumn: 'department_id',
                selfColumn: 'id',
                selfId: $user->employee_id,
            );
            abort_unless($visible->exists(), 403);
        }
    }

    private function payrollForDocument(Document $document): ?Payroll
    {
        if ($document->entity_type !== Payroll::class) {
            return null;
        }

        return Payroll::query()
            ->with(['employee.department', 'period'])
            ->find($document->entity_id);
    }

    private function canViewPayroll(\App\Modules\Auth\Models\User $user, Payroll $payroll): bool
    {
        if (! $this->payrollPublication->isPayrollPublishable($payroll)) {
            return false;
        }

        if ($user->can('payroll.payslip.view_all') || $user->can('hr.employees.view_sensitive')) {
            return true;
        }

        if ((int) $payroll->employee_id === (int) ($user->employee_id ?? 0)) {
            return true;
        }

        if ($user->role?->slug !== 'department_head' || ! $user->employee_id) {
            return false;
        }

        $viewerDepartmentId = Employee::query()->whereKey($user->employee_id)->value('department_id');
        $ownerDepartmentId = $payroll->employee?->department_id;

        return $viewerDepartmentId !== null
            && $ownerDepartmentId !== null
            && (int) $viewerDepartmentId === (int) $ownerDepartmentId;
    }

    private function permissionFor(string $type): string
    {
        return match ($type) {
            'payslip', 'payroll_register', 'bir_1601c', 'bir_2316',
            'sss_r3', 'philhealth_rf1', 'pagibig_remittance' => 'payroll.view',
            'invoice' => 'accounting.invoices.view',
            'bill', 'journal_entry' => 'accounting.bills.view',
            'purchase_order', 'purchase_request' => 'purchasing.view',
            'coc', 'complaint_8d', 'ncr' => 'quality.view',
            'work_order_traveler' => 'production.work_orders.view',
            'balance_sheet', 'income_statement', 'trial_balance' => 'accounting.statements.view',
            'bulk_pdf' => 'admin.print.bulk',
            default => 'admin.audit_logs.view',
        };
    }
}
