<?php

declare(strict_types=1);

namespace App\Common\Controllers;

use App\Common\Models\Document;
use App\Common\Resources\DocumentResource;
use App\Common\Services\DocumentVaultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
    public function __construct(private readonly DocumentVaultService $vault) {}

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

        abort_unless($user->can($perm), 403);

        // Extra guard: payslips and gov reports — only owner or HR/Finance.
        if (in_array($typeValue, ['payslip', 'bir_2316'], true)) {
            $isOwner = $document->entity_id === ($user->employee_id ?? null)
                && $document->entity_type !== null;
            $hasView = $user->can('payroll.payslip.view_all')
                || $user->can('payroll.view')
                || $user->can('hr.employees.view_sensitive');
            abort_unless($isOwner || $hasView, 403);
        }
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
