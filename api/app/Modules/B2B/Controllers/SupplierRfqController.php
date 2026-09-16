<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Requests\Supplier\StoreSupplierQuoteRequest;
use App\Modules\B2B\Requests\Supplier\UploadRfqDocumentRequest;
use App\Modules\B2B\Requests\Supplier\WithdrawSupplierQuoteRequest;
use App\Modules\B2B\Resources\SupplierRfqInvitationResource;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\RfqDocument;
use App\Modules\Purchasing\Models\RfqQuoteReconfirmation;
use App\Modules\Purchasing\Models\SupplierQuote;
use App\Modules\Purchasing\Resources\SupplierQuoteResource;
use App\Modules\Purchasing\Services\SupplierQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class SupplierRfqController
{
    public function __construct(private readonly SupplierQuoteService $quotes) {}

    private function user(Request $request): SupplierPortalUser
    {
        /** @var SupplierPortalUser $user */
        $user = $request->user('supplier_portal');

        return $user;
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return SupplierRfqInvitationResource::collection($this->quotes->list($this->user($request)->vendor_id, $request->query()));
    }

    public function show(Request $request, RequestForQuote $rfq): SupplierRfqInvitationResource
    {
        try {
            return new SupplierRfqInvitationResource($this->quotes->invitation($this->user($request)->vendor_id, $rfq));
        } catch (BusinessRuleException $e) {
            abort(404, 'RFQ invitation not found.');
        }
    }

    public function store(StoreSupplierQuoteRequest $request, RequestForQuote $rfq): JsonResponse
    {
        $user = $this->user($request);
        try {
            $quote = $this->quotes->createDraft($rfq, $user->vendor_id, $user, $request->validated());
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }

        return (new SupplierQuoteResource($quote))->response()->setStatusCode(201);
    }

    public function update(StoreSupplierQuoteRequest $request, RequestForQuote $rfq, SupplierQuote $quote): SupplierQuoteResource
    {
        abort_unless((int) $quote->request_for_quote_id === (int) $rfq->id, 404);
        try {
            return new SupplierQuoteResource($this->quotes->update($quote, $this->user($request)->vendor_id, $request->validated()));
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function submit(Request $request, RequestForQuote $rfq, SupplierQuote $quote): SupplierQuoteResource
    {
        abort_unless((int) $quote->request_for_quote_id === (int) $rfq->id, 404);
        try {
            return new SupplierQuoteResource($this->quotes->submit($quote, $this->user($request)->vendor_id));
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function withdraw(WithdrawSupplierQuoteRequest $request, RequestForQuote $rfq, SupplierQuote $quote): SupplierQuoteResource
    {
        abort_unless((int) $quote->request_for_quote_id === (int) $rfq->id, 404);
        try {
            return new SupplierQuoteResource($this->quotes->withdraw($quote, $this->user($request)->vendor_id, $request->validated()['reason']));
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function uploadDocument(UploadRfqDocumentRequest $request, RequestForQuote $rfq): JsonResponse
    {
        $user = $this->user($request);
        $validated = $request->validated();
        $quote = ! empty($validated['quote_id'])
            ? SupplierQuote::query()->findOrFail((int) $validated['quote_id'])
            : null;
        try {
            $document = $this->quotes->uploadDocument($rfq, $user->vendor_id, $user, $request->file('file'), $validated['document_type'], $quote);
        } catch (BusinessRuleException $e) {
            abort(404, 'RFQ invitation not found.');
        }

        return response()->json(['data' => ['id' => $document->hash_id, 'document_type' => $document->document_type, 'original_filename' => $document->original_filename]], 201);
    }

    public function versions(Request $request, RequestForQuote $rfq, SupplierQuote $quote): AnonymousResourceCollection
    {
        $user = $this->user($request);
        abort_unless((int) $quote->vendor_id === (int) $user->vendor_id && (int) $quote->request_for_quote_id === (int) $rfq->id, 404);

        return SupplierQuoteResource::collection(SupplierQuote::query()->where('request_for_quote_id', $rfq->id)->where('vendor_id', $user->vendor_id)->with('items.rfqItem')->orderByDesc('version')->get());
    }

    public function downloadDocument(Request $request, RequestForQuote $rfq, RfqDocument $document)
    {
        abort_unless((int) $document->request_for_quote_id === (int) $rfq->id && $document->vendor_id === null && $document->document_type === 'requirement_document', 404);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->original_filename);
    }

    public function confirmReconfirmation(Request $request, PurchaseOrder $purchaseOrder, RfqQuoteReconfirmation $reconfirmation): JsonResponse
    {
        abort_unless((int) $reconfirmation->purchase_order_id === (int) $purchaseOrder->id, 404);
        try {
            $confirmed = $this->quotes->confirmReconfirmation($reconfirmation, $this->user($request)->vendor_id, $this->user($request));
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['data' => ['id' => $confirmed->hash_id, 'status' => $confirmed->status]]);
    }
}
