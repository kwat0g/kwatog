<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Controllers;

use App\Common\Support\HashIdFilter;
use App\Common\Models\ApprovalRecord;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestPriority;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Requests\ConvertPrToPoRequest;
use App\Modules\Purchasing\Requests\RejectPurchaseRequestRequest;
use App\Modules\Purchasing\Requests\StorePurchaseRequestRequest;
use App\Modules\Purchasing\Requests\UpdatePurchaseRequestRequest;
use App\Modules\Purchasing\Resources\PurchaseOrderResource;
use App\Modules\Purchasing\Resources\PurchaseRequestResource;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseRequestPdfService;
use App\Modules\Purchasing\Services\PurchaseRequestService;
use App\Modules\Purchasing\Policies\PurchaseRequestAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use App\Common\Services\SettingsService;
use App\Common\Exceptions\BusinessRuleException;

class PurchaseRequestController
{
    public function __construct(
        private readonly PurchaseRequestService $service,
        private readonly PurchaseOrderService $poService,
        private readonly PurchaseRequestPdfService $pdf,
        private readonly SettingsService $settings,
        private readonly PurchaseRequestAccessPolicy $access,
    ) {}

    /** Sprint P9 — printable PR with 4-tier approval signature block. */
    public function printPdf(Request $request, PurchaseRequest $purchaseRequest): Response
    {
        abort_unless($this->access->canView($request->user(), $purchaseRequest), 403, 'You do not have permission to view this purchase request.');
        return $this->pdf->render($purchaseRequest);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return PurchaseRequestResource::collection($this->service->list($request->query(), $request->user()));
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'statuses' => array_map(
                static fn (PurchaseRequestStatus $status): array => ['value' => $status->value, 'label' => ucfirst($status->value)],
                PurchaseRequestStatus::cases(),
            ),
            'priorities' => array_map(
                static fn (PurchaseRequestPriority $priority): array => [
                    'value' => $priority->value,
                    'label' => $priority->label(),
                ],
                PurchaseRequestPriority::cases(),
            ),
            'approval_sla_hours' => $this->settings->requiredInt('approvals.reminder_hours', 1),
            'default_priority' => (string) $this->settings->get('purchasing.purchase_request.default_priority', ''),
        ]]);
    }

    public function show(Request $request, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        abort_unless($this->access->canView($request->user(), $purchaseRequest), 403, 'You do not have permission to view this purchase request.');
        return new PurchaseRequestResource($this->service->show($purchaseRequest));
    }

    public function store(StorePurchaseRequestRequest $request): JsonResponse
    {
        $pr = $this->service->create($request->validated(), $request->user());
        return (new PurchaseRequestResource($pr))->response()->setStatusCode(201);
    }

    public function update(UpdatePurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        try {
            $pr = $this->service->update($purchaseRequest, $request->validated(), $request->user());
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }
        return new PurchaseRequestResource($pr);
    }

    public function destroy(PurchaseRequest $purchaseRequest): JsonResponse
    {
        try { $this->service->delete($purchaseRequest, request()->user()); }
        catch (BusinessRuleException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return response()->json(null, 204);
    }

    public function restore(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        abort_unless($this->access->canManageDraft($request->user(), $purchaseRequest), 403, 'You do not have permission to restore this purchase request.');
        $purchaseRequest->restore();
        return response()->json(['message' => 'Purchase request restored.']);
    }

    public function submit(Request $request, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        try { $pr = $this->service->submit($purchaseRequest, $request->user()); }
        catch (BusinessRuleException $e) { abort(422, $e->getMessage()); }
        return new PurchaseRequestResource($this->service->show($pr));
    }

    public function acknowledgeBudget(Request $request, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        try { $pr = $this->service->acknowledgeBudget($purchaseRequest, $request->user()); }
        catch (BusinessRuleException $e) { abort(422, $e->getMessage()); }
        return new PurchaseRequestResource($this->service->show($pr));
    }

    public function approve(Request $request, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        try { $pr = $this->service->approve($purchaseRequest, $request->user(), $request->input('remarks')); }
        catch (BusinessRuleException $e) { abort(422, $e->getMessage()); }
        return new PurchaseRequestResource($this->service->show($pr));
    }

    public function reject(RejectPurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        try { $pr = $this->service->reject($purchaseRequest, $request->user(), $request->validated()['reason']); }
        catch (BusinessRuleException $e) { abort(422, $e->getMessage()); }
        return new PurchaseRequestResource($this->service->show($pr));
    }

    public function cancel(Request $request, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        try { $pr = $this->service->cancel($purchaseRequest, $request->user()); }
        catch (BusinessRuleException $e) { abort(422, $e->getMessage()); }
        return new PurchaseRequestResource($this->service->show($pr));
    }

    /**
     * ADV6 — Bulk approve multiple PRs at once.
     * Accepts hash IDs from the frontend and decodes them server-side.
     * Expects JSON body: { ids: [string, string, ...], remarks?: string }
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'ids'     => 'required|array|min:1',
            'ids.*'   => 'string',
            'remarks' => 'nullable|string|max:500',
        ])->validate();

        // Decode hash IDs to numeric DB IDs.
        $ids = array_map(fn ($hash) => HashIdFilter::decode($hash, PurchaseRequest::class), $validated['ids']);
        $ids = array_filter($ids);

        if (empty($ids)) {
            return response()->json(['message' => 'No valid PR IDs provided.'], 422);
        }

        $results = $this->service->bulkApprove(
            $ids,
            $request->user(),
            $validated['remarks'] ?? null
        );

        return response()->json(['data' => $results]);
    }

    /**
     * ADV6 — Pending PR count for the sidebar badge.
     * Only PRs that the current user can see (assigned to their role).
     */
    public function pendingCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $roleSlugs = $this->access->approvalRoleSlugs($user);

        // Count only current approval rows the user can actually act on. The
        // scope also enforces department visibility and excludes self-created
        // requests, matching ApprovalService's server-side guard.
        $count = ApprovalRecord::query()
            ->where('approvable_type', (new PurchaseRequest)->getMorphClass())
            ->where('action', 'pending')
            ->where('is_current', true)
            ->whereIn('role_slug', $roleSlugs)
            ->whereHasMorph('approvable', [PurchaseRequest::class], function ($q) use ($user): void {
                $this->access->visibleTo($q, $user);
                $q->where('status', PurchaseRequestStatus::Pending->value)
                    ->where('requested_by', '<>', $user->id);
            })
            ->count();

        return response()->json(['data' => ['count' => $count]]);
    }

    public function convert(ConvertPrToPoRequest $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        abort_unless($this->access->canConvert($request->user(), $purchaseRequest), 403, 'You do not have permission to convert this purchase request.');
        try {
            $pos = $this->poService->convertFromPr($purchaseRequest, $request->validated()['vendor_map'], $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json([
            'data' => PurchaseOrderResource::collection(collect($pos))->resolve(),
        ], 201);
    }
}
