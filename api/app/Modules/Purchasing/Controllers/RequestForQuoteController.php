<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\RfqDocument;
use App\Modules\Purchasing\Policies\PurchaseRequestAccessPolicy;
use App\Modules\Purchasing\Requests\AwardRfqRequest;
use App\Modules\Purchasing\Requests\CancelRequestForQuoteRequest;
use App\Modules\Purchasing\Requests\ExtendRequestForQuoteRequest;
use App\Modules\Purchasing\Requests\ManualCaptureRfqQuoteRequest;
use App\Modules\Purchasing\Requests\StoreRequestForQuoteRequest;
use App\Modules\Purchasing\Requests\UpdateRequestForQuoteRequest;
use App\Modules\Purchasing\Requests\UploadRfqDocumentRequest;
use App\Modules\Purchasing\Resources\PurchaseOrderResource;
use App\Modules\Purchasing\Resources\RequestForQuoteResource;
use App\Modules\Purchasing\Resources\RfqDocumentResource;
use App\Modules\Purchasing\Resources\SupplierQuoteResource;
use App\Modules\Purchasing\Services\RequestForQuoteService;
use App\Modules\Purchasing\Services\RfqAwardService;
use App\Modules\Purchasing\Services\SupplierQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class RequestForQuoteController
{
    public function __construct(
        private readonly RequestForQuoteService $rfqs,
        private readonly RfqAwardService $awards,
        private readonly SupplierQuoteService $quotes,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->rfqs->closeAllDue();

        return RequestForQuoteResource::collection($this->rfqs->list($request->query(), $request->user()));
    }

    /** The RFQ form's inputs for a PR: lines still to source and suppliers (qualified first, with reach). */
    public function setup(Request $request, PurchaseRequest $purchaseRequest, PurchaseRequestAccessPolicy $access): JsonResponse
    {
        abort_unless($access->canView($request->user(), $purchaseRequest), 403);

        return response()->json(['data' => $this->rfqs->setup($purchaseRequest)]);
    }

    public function store(StoreRequestForQuoteRequest $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        return $this->respond(fn () => $this->rfqs->createFromPurchaseRequest($purchaseRequest, $request->validated(), $request->user()), 201);
    }

    public function show(Request $request, RequestForQuote $rfq): RequestForQuoteResource
    {
        try {
            return new RequestForQuoteResource($this->rfqs->show($this->rfqs->closeDue($rfq), $request->user()));
        } catch (BusinessRuleException $e) {
            abort(403, $e->getMessage());
        }
    }

    public function update(UpdateRequestForQuoteRequest $request, RequestForQuote $rfq): JsonResponse
    {
        return $this->respond(fn () => $this->rfqs->update($rfq, $request->validated(), $request->user()));
    }

    public function publish(Request $request, RequestForQuote $rfq): JsonResponse
    {
        return $this->respond(fn () => $this->rfqs->publish($rfq, $request->user()));
    }

    public function extend(ExtendRequestForQuoteRequest $request, RequestForQuote $rfq): JsonResponse
    {
        return $this->respond(fn () => $this->rfqs->extend($rfq, $request->validated(), $request->user()));
    }

    public function close(Request $request, RequestForQuote $rfq): JsonResponse
    {
        return $this->respond(fn () => $this->rfqs->closeNow($rfq, $request->user()));
    }

    public function cancel(CancelRequestForQuoteRequest $request, RequestForQuote $rfq): JsonResponse
    {
        return $this->respond(fn () => $this->rfqs->cancel($rfq, (string) $request->validated('reason'), $request->user()));
    }

    public function comparison(Request $request, RequestForQuote $rfq): JsonResponse
    {
        return $this->respond(fn () => $this->rfqs->comparison($rfq, $request->user()));
    }

    public function award(AwardRfqRequest $request, RequestForQuote $rfq): JsonResponse
    {
        try {
            $result = $this->awards->award($rfq, (string) $request->validated('award_reason'), $request->validated('lines'), $request->user());
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json([
            'data' => new RequestForQuoteResource($this->rfqs->show($result['rfq'], $request->user())),
            'purchase_orders' => PurchaseOrderResource::collection($result['purchase_orders']),
        ]);
    }

    public function uploadDocument(UploadRfqDocumentRequest $request, RequestForQuote $rfq): JsonResponse
    {
        try {
            $document = $this->rfqs->uploadInternalDocument(
                $rfq,
                $request->file('file'),
                (string) $request->validated('document_type'),
                $request->validated('vendor_id') !== null ? (int) $request->validated('vendor_id') : null,
                $request->user(),
            );
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }

        return (new RfqDocumentResource($document))->response()->setStatusCode(201);
    }

    /** A phone or email quotation entered by the buyer; uses the vendor's latest uploaded quotation PDF. */
    public function manualQuote(ManualCaptureRfqQuoteRequest $request, RequestForQuote $rfq): JsonResponse
    {
        $data = $request->validated();
        try {
            $quote = $this->quotes->save($rfq, (int) $data['vendor_id'], $data, true, null, $request->user());
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }

        return (new SupplierQuoteResource($quote))->response()->setStatusCode(201);
    }

    public function purchaseOrders(Request $request, RequestForQuote $rfq): AnonymousResourceCollection
    {
        try {
            $rfq = $this->rfqs->show($rfq, $request->user());
        } catch (BusinessRuleException $e) {
            abort(403, $e->getMessage());
        }

        return PurchaseOrderResource::collection($rfq->purchaseOrders()->with('vendor')->orderBy('id')->get());
    }

    public function downloadDocument(Request $request, RfqDocument $document)
    {
        abort_unless($this->rfqs->canDownload($request->user(), $document), 403, 'You do not have access to this RFQ document.');
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->original_filename);
    }

    /** @param callable(): RequestForQuote $action */
    private function respond(callable $action, int $status = 200): JsonResponse
    {
        try {
            $rfq = $action();
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }

        return (new RequestForQuoteResource($rfq))->response()->setStatusCode($status);
    }
}
