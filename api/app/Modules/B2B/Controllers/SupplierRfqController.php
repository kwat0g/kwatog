<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Requests\Supplier\StoreSupplierQuoteRequest;
use App\Modules\B2B\Requests\Supplier\UploadRfqDocumentRequest;
use App\Modules\B2B\Resources\SupplierRfqInvitationResource;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\RfqDocument;
use App\Modules\Purchasing\Resources\SupplierQuoteResource;
use App\Modules\Purchasing\Services\RequestForQuoteService;
use App\Modules\Purchasing\Services\SupplierQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class SupplierRfqController
{
    public function __construct(
        private readonly SupplierQuoteService $quotes,
        private readonly RequestForQuoteService $rfqs,
    ) {}

    private function user(Request $request): SupplierPortalUser
    {
        /** @var SupplierPortalUser $user */
        $user = $request->user('supplier_portal');

        return $user;
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        // No scheduler in some environments: close anything past its deadline
        // so the supplier sees the true state.
        $this->rfqs->closeAllDue();

        return SupplierRfqInvitationResource::collection($this->quotes->list($this->user($request)->vendor_id, $request->query()));
    }

    public function show(Request $request, RequestForQuote $rfq): SupplierRfqInvitationResource
    {
        return new SupplierRfqInvitationResource($this->quotes->invitation($this->user($request)->vendor_id, $this->rfqs->closeDue($rfq)));
    }

    /** Save the supplier's quotation; submit=true saves and submits in one step. */
    public function saveQuote(StoreSupplierQuoteRequest $request, RequestForQuote $rfq): SupplierQuoteResource
    {
        $user = $this->user($request);
        $data = $request->validated();
        try {
            $quote = $this->quotes->save($rfq, $user->vendor_id, $data, (bool) $data['submit'], $user);
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }

        return new SupplierQuoteResource($quote);
    }

    public function withdraw(Request $request, RequestForQuote $rfq): SupplierQuoteResource
    {
        try {
            return new SupplierQuoteResource($this->quotes->withdraw($rfq, $this->user($request)->vendor_id));
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function uploadDocument(UploadRfqDocumentRequest $request, RequestForQuote $rfq): JsonResponse
    {
        $user = $this->user($request);
        try {
            $document = $this->quotes->uploadDocument($rfq, $user->vendor_id, $user, $request->file('file'), (string) $request->validated('document_type'));
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['data' => [
            'id' => $document->hash_id,
            'document_type' => $document->document_type,
            'original_filename' => $document->original_filename,
        ]], 201);
    }

    public function downloadDocument(Request $request, RequestForQuote $rfq, RfqDocument $document)
    {
        try {
            $document = $this->quotes->documentForSupplier($this->user($request)->vendor_id, $rfq, $document);
        } catch (BusinessRuleException) {
            abort(404, 'RFQ document not found.');
        }
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->original_filename);
    }
}
