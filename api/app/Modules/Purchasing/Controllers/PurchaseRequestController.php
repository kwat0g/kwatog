<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Controllers;

use App\Common\Support\HashIdFilter;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use RuntimeException;

class PurchaseRequestController
{
    public function __construct(
        private readonly PurchaseRequestService $service,
        private readonly PurchaseOrderService $poService,
        private readonly PurchaseRequestPdfService $pdf,
    ) {}

    /** Sprint P9 — printable PR with 4-tier approval signature block. */
    public function printPdf(PurchaseRequest $purchaseRequest): Response
    {
        return $this->pdf->render($purchaseRequest);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return PurchaseRequestResource::collection($this->service->list($request->query(), $request->user()));
    }

    public function show(PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
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
            $pr = $this->service->update($purchaseRequest, $request->validated());
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        return new PurchaseRequestResource($pr);
    }

    public function destroy(PurchaseRequest $purchaseRequest): JsonResponse
    {
        try { $this->service->delete($purchaseRequest); }
        catch (RuntimeException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return response()->json(null, 204);
    }

    public function submit(PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        try { $pr = $this->service->submit($purchaseRequest); }
        catch (RuntimeException $e) { abort(422, $e->getMessage()); }
        return new PurchaseRequestResource($this->service->show($pr));
    }

    public function approve(Request $request, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        try { $pr = $this->service->approve($purchaseRequest, $request->user(), $request->input('remarks')); }
        catch (RuntimeException $e) { abort(422, $e->getMessage()); }
        return new PurchaseRequestResource($this->service->show($pr));
    }

    public function reject(RejectPurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        try { $pr = $this->service->reject($purchaseRequest, $request->user(), $request->validated()['reason']); }
        catch (RuntimeException $e) { abort(422, $e->getMessage()); }
        return new PurchaseRequestResource($this->service->show($pr));
    }

    public function cancel(PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        try { $pr = $this->service->cancel($purchaseRequest); }
        catch (RuntimeException $e) { abort(422, $e->getMessage()); }
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
        $roleSlug = $user->role?->slug;

        // Count pending PRs where the current approval step matches the user's role.
        $count = \App\Common\Models\ApprovalRecord::where('approvable_type', (new PurchaseRequest)->getMorphClass())
            ->where('action', 'pending')
            ->where('role_slug', $roleSlug)
            ->whereHas('approvable', fn ($q) => $q->where('status', PurchaseRequestStatus::Pending->value))
            ->count();

        return response()->json(['data' => ['count' => $count]]);
    }

    public function convert(ConvertPrToPoRequest $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        try {
            $pos = $this->poService->convertFromPr($purchaseRequest, $request->validated()['vendor_map'], $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json([
            'data' => PurchaseOrderResource::collection(collect($pos))->resolve(),
        ], 201);
    }
}
