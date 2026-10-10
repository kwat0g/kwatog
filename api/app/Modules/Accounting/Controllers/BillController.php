<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillPayment;
use App\Modules\Accounting\Models\SupplierInvoice;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Requests\StoreBillPaymentRequest;
use App\Modules\Accounting\Requests\DecideBillPaymentRequest;
use App\Modules\Accounting\Requests\StoreBillRequest;
use App\Modules\Accounting\Requests\VoidBillPaymentRequest;
use App\Modules\Accounting\Resources\BillPaymentResource;
use App\Modules\Accounting\Resources\BillResource;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Purchasing\Exceptions\ThreeWayMatchException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Exceptions\ClosedPeriodException;
use App\Modules\B2B\Models\PortalShippingDocument;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class BillController
{
    public function __construct(private readonly BillService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return BillResource::collection($this->service->list($request->query()));
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'statuses' => array_map(static fn (BillStatus $status): array => [
                'value' => $status->value,
                'label' => ucfirst($status->value),
            ], BillStatus::cases()),
        ]]);
    }

    public function show(Bill $bill): BillResource
    {
        return new BillResource($this->service->show($bill));
    }

    public function store(StoreBillRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $bill = ($data['provenance_type'] ?? 'stock') === 'stock'
                ? $this->service->createDraft($data, $request->user())
                : $this->service->create($data, $request->user());
        } catch (\App\Modules\Purchasing\Exceptions\ThreeWayMatchException $e) {
            return response()->json([
                'message'           => $e->getMessage(),
                'code'              => 'three_way_match_blocked',
                'three_way_match'   => $e->details,
            ], 422);
        } catch (BusinessRuleException|ClosedPeriodException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new BillResource($bill))->response()->setStatusCode(201);
    }

    /** 2026-08-08 — post an auto-created draft bill (builds + posts the JE, flips to unpaid). */
    public function postDraft(Request $request, Bill $bill): BillResource|JsonResponse
    {
        $request->validate([
            'allow_override' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:1000'],
        ]);
        // This residual arm was `catch (\Throwable)`, i.e. the same leak as the
        // \RuntimeException arms but total: every SQL fault, every abort(), and
        // BillService's "Required account {code} not found in COA" all came back
        // as a 422 telling the AP clerk to fix the bill. It is not in the 39-file
        // \RuntimeException set the task named, but it is one method away from
        // store() and strictly worse, so it is narrowed with them.
        try {
            $bill = $this->service->postDraft(
                $bill,
                $request->user(),
                $request->boolean('allow_override'),
                $request->input('override_reason'),
            );
        } catch (ThreeWayMatchException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'three_way_match_blocked',
                'three_way_match' => $e->details,
            ], 422);
        } catch (BusinessRuleException|ClosedPeriodException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new BillResource($bill);
    }

    public function cancel(Request $request, Bill $bill): BillResource|JsonResponse
    {
        $data = $request->validate([
            'reversal_date' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $bill = $this->service->cancel(
                $bill,
                $request->user(),
                ! empty($data['reversal_date']) ? Carbon::createFromFormat('!Y-m-d', $data['reversal_date']) : null,
                $data['reason'] ?? null,
            );
        } catch (BusinessRuleException|ClosedPeriodException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new BillResource($bill);
    }

    public function voidSupplierInvoice(Request $request, SupplierInvoice $supplierInvoice): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $invoice = $this->service->voidSupplierInvoice($supplierInvoice, $request->user(), $data['reason']);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['id' => $invoice->hash_id, 'voided_at' => $invoice->voided_at]]);
    }

    public function recordPayment(StoreBillPaymentRequest $request, Bill $bill): JsonResponse
    {
        $data = $request->validated();
        $proof = $request->file('proof_file');
        $proofPath = $proof?->store('bill-payment-proofs', 'local');
        if ($proof && ! $proofPath) {
            return response()->json(['message' => 'Unable to store payment proof.'], 422);
        }
        if ($proof) {
            $data['proof_path'] = $proofPath;
            $data['proof_file_name'] = $proof->getClientOriginalName();
            $data['proof_sha256'] = hash_file('sha256', $proof->getRealPath());
        }

        try {
            $payment = $this->service->recordPayment($bill, $data, $request->user());
        } catch (BusinessRuleException|ClosedPeriodException $e) {
            if ($proofPath) Storage::disk('local')->delete($proofPath);
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($proofPath) Storage::disk('local')->delete($proofPath);
            throw $e;
        }
        if ($proofPath && $payment->proof_path !== $proofPath) {
            Storage::disk('local')->delete($proofPath);
        }
        return (new BillPaymentResource($payment))->response()->setStatusCode(201);
    }

    public function downloadPaymentProof(Bill $bill, BillPayment $payment): mixed
    {
        abort_unless((int) $payment->bill_id === (int) $bill->id && $payment->proof_path, 404);
        abort_unless(Storage::disk('local')->exists($payment->proof_path), 404);

        return Storage::disk('local')->download($payment->proof_path, $payment->proof_file_name);
    }

    public function approvePayment(DecideBillPaymentRequest $request, Bill $bill, BillPayment $payment): JsonResponse
    {
        try {
            $payment = $this->service->approvePayment($bill, $payment, $request->user());
        } catch (BusinessRuleException|ClosedPeriodException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new BillPaymentResource($payment))->response()->setStatusCode(200);
    }

    public function rejectPayment(DecideBillPaymentRequest $request, Bill $bill, BillPayment $payment): JsonResponse
    {
        try {
            $payment = $this->service->rejectPayment(
                $bill,
                $payment,
                $request->user(),
                (string) ($request->validated()['remarks'] ?? ''),
            );
        } catch (BusinessRuleException|ClosedPeriodException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new BillPaymentResource($payment))->response()->setStatusCode(200);
    }

    public function voidPayment(VoidBillPaymentRequest $request, Bill $bill, BillPayment $payment): JsonResponse
    {
        try {
            $payment = $this->service->voidPayment($bill, $payment, $request->validated(), $request->user());
        } catch (BusinessRuleException|ClosedPeriodException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new BillPaymentResource($payment))->response()->setStatusCode(200);
    }

    /**
     * Download a portal shipping document (supplier-submitted invoice).
     */
    public function downloadSupplierInvoice(Bill $bill, PortalShippingDocument $document): mixed
    {
        // Only the supplier's invoice attached to THIS bill; shipping documents
        // have their own PO-scoped download.
        $linked = (int) $document->bill_id === (int) $bill->id
            || ($bill->supplier_invoice_id !== null
                && (int) $document->supplier_invoice_id === (int) $bill->supplier_invoice_id);
        abort_if(! $linked || $document->document_type !== 'supplier_invoice', 404);

        if (! Storage::disk('local')->exists($document->file_path)) {
            abort(404, 'Document file not found.');
        }

        return Storage::disk('local')->download(
            $document->file_path,
            $document->original_filename
        );
    }
}
