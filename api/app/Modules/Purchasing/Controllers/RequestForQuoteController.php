<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\RfqDocument;
use App\Modules\Purchasing\Models\SupplierQuoteItem;
use App\Modules\Purchasing\Requests\AwardRfqRequest;
use App\Modules\Purchasing\Requests\CancelRequestForQuoteRequest;
use App\Modules\Purchasing\Requests\ExtendRequestForQuoteRequest;
use App\Modules\Purchasing\Requests\ManualCaptureRfqQuoteRequest;
use App\Modules\Purchasing\Requests\ReviewRfqQualityRequest;
use App\Modules\Purchasing\Requests\StoreRequestForQuoteRequest;
use App\Modules\Purchasing\Requests\StoreRfqAddendumRequest;
use App\Modules\Purchasing\Requests\UpdateRequestForQuoteRequest;
use App\Modules\Purchasing\Requests\UploadRfqDocumentRequest;
use App\Modules\Purchasing\Resources\PurchaseOrderResource;
use App\Modules\Purchasing\Resources\RequestForQuoteResource;
use App\Modules\Purchasing\Resources\RfqDocumentResource;
use App\Modules\Purchasing\Resources\SupplierQuoteItemResource;
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
        return RequestForQuoteResource::collection($this->rfqs->list($request->query(), $request->user()));
    }

    public function store(StoreRequestForQuoteRequest $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        try {
            $rfq = $this->rfqs->createFromPurchaseRequest($purchaseRequest, $request->validated(), $request->user());
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }

        return (new RequestForQuoteResource($rfq))->response()->setStatusCode(201);
    }

    public function show(Request $request, RequestForQuote $rfq): RequestForQuoteResource
    {
        try {
            return new RequestForQuoteResource($this->rfqs->show($rfq, $request->user()));
        } catch (BusinessRuleException $e) {
            abort(403, $e->getMessage());
        }
    }

    public function update(UpdateRequestForQuoteRequest $request, RequestForQuote $rfq): RequestForQuoteResource
    {
        try {
            return new RequestForQuoteResource($this->rfqs->update($rfq, $request->validated(), $request->user()));
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function publish(Request $request, RequestForQuote $rfq): RequestForQuoteResource
    {
        try {
            return new RequestForQuoteResource($this->rfqs->publish($rfq, $request->user()));
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function extend(ExtendRequestForQuoteRequest $request, RequestForQuote $rfq): RequestForQuoteResource
    {
        try {
            return new RequestForQuoteResource($this->rfqs->extend($rfq, $request->validated(), $request->user()));
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function addendum(StoreRfqAddendumRequest $request, RequestForQuote $rfq): RequestForQuoteResource
    {
        try {
            return new RequestForQuoteResource($this->rfqs->addendum($rfq, $request->validated(), $request->user()));
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function comparison(Request $request, RequestForQuote $rfq): RequestForQuoteResource
    {
        try {
            return new RequestForQuoteResource($this->rfqs->comparison($rfq, $request->user()));
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function award(AwardRfqRequest $request, RequestForQuote $rfq): JsonResponse
    {
        try {
            $result = $this->awards->award($rfq, $request->validated()['awards'] ?? [], $request->user());
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json([
            'data' => new RequestForQuoteResource($this->rfqs->show($result['rfq'], $request->user())),
            'purchase_orders' => PurchaseOrderResource::collection($result['purchase_orders']),
        ]);
    }

    public function reviewQuality(ReviewRfqQualityRequest $request, RequestForQuote $rfq, SupplierQuoteItem $quoteItem): SupplierQuoteItemResource
    {
        try {
            return new SupplierQuoteItemResource($this->awards->reviewQuality($rfq, $quoteItem, $request->validated(), $request->user()));
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }
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

    public function manualQuote(ManualCaptureRfqQuoteRequest $request, RequestForQuote $rfq): JsonResponse
    {
        try {
            $quote = $this->quotes->captureManual($rfq, $request->validated(), $request->user());
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }

        return (new SupplierQuoteResource($quote))->response()->setStatusCode(201);
    }

    public function cancel(CancelRequestForQuoteRequest $request, RequestForQuote $rfq): RequestForQuoteResource
    {
        try {
            return new RequestForQuoteResource($this->rfqs->cancel($rfq, (string) $request->input('reason'), $request->user()));
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function purchaseOrders(Request $request, RequestForQuote $rfq): AnonymousResourceCollection
    {
        try {
            $rfq = $this->rfqs->show($rfq, $request->user());
        } catch (BusinessRuleException $e) {
            abort(403, $e->getMessage());
        }

        return PurchaseOrderResource::collection($rfq->purchaseOrders()->with('vendor')->get());
    }

    public function downloadDocument(Request $request, RfqDocument $document)
    {
        $user = $request->user();
        abort_unless($user, 401);
        $document->loadMissing('rfq');
        try {
            $this->rfqs->show($document->rfq, $user);
        } catch (BusinessRuleException $e) {
            abort(403, 'You do not have access to this RFQ document.');
        }
        $commercial = $document->document_type === 'quotation_pdf';
        $requirement = $document->document_type === 'requirement_document';
        $authorized = $requirement
            ? $user->hasPermission('purchasing.rfq.view')
            : ($commercial
                ? $user->hasPermission('purchasing.rfq.evaluate') || $user->hasPermission('purchasing.rfq.manage')
                : $user->hasPermission('purchasing.rfq.quality_review')
                    || $user->hasPermission('purchasing.rfq.evaluate')
                    || $user->hasPermission('purchasing.rfq.manage'));
        abort_unless($authorized, 403);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->original_filename);
    }
}
