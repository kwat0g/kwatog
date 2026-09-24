<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Exceptions\ClosedPeriodException;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Requests\Supplier\SubmitInvoiceRequest;
use App\Modules\B2B\Services\SupplierInvoiceService;
use App\Modules\Purchasing\Exceptions\ThreeWayMatchException;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Supplier invoice submission against an accepted goods receipt.
 */
class SupplierInvoiceController extends Controller
{
    public function __construct(
        private readonly SupplierInvoiceService $service,
    ) {}

    private function user(Request $request): SupplierPortalUser
    {
        /** @var SupplierPortalUser $user */
        $user = $request->user('supplier_portal');
        return $user;
    }

    /**
     * POST /api/v1/b2b/supplier/purchase-orders/{id}/submit-invoice
     */
    public function submitInvoice(PurchaseOrder $purchaseOrder, SubmitInvoiceRequest $request): JsonResponse
    {
        $user = $this->user($request);

        // The arm stays because it adds `code: bill_creation_failed`, which the
        // portal branches on — it is not a bare re-emission of getMessage().
        //
        // Three classes: the rules the supplier can act on, plus BillService's
        // ThreeWayMatchException and the period guard, both reachable through
        // submitInvoice → BillService::create.
        //
        // Two things it no longer catches, both on purpose. `abort_if(...,
        // 403)` — a supplier submitting against another vendor's PO — used to
        // arrive here as `422 {"message":"", "code":"bill_creation_failed"}`,
        // because a Symfony HttpException extends RuntimeException and
        // abort_if() with no message leaves getMessage() empty; it is now the
        // 403 it was written as. And "Unable to store the supplier invoice."
        // is the storage fault 4f40a94d annotated as a 500: the upload
        // succeeded and validation passed, so there is nothing on the
        // supplier's side to fix, and calling it a bill-creation failure
        // invited them to resubmit a file that was fine.
        try {
            $result = $this->service->submitInvoice(
                $user->vendor_id,
                $user->id,
                $purchaseOrder,
                $request->validated(),
                $request->hasFile('file') ? $request->file('file') : null,
            );
        } catch (BusinessRuleException|ClosedPeriodException|ThreeWayMatchException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code'    => 'bill_creation_failed',
            ], 422);
        }

        $bill = $result['bill']->load('goodsReceiptNote:id,grn_number');

        return response()->json([
            'data'    => [
                'id'                    => $bill->hash_id,
                'bill_number'           => $bill->bill_number,
                'supplier_invoice_number' => $bill->supplier_invoice_number,
                'total_amount'          => (string) $bill->total_amount,
                'status'                => (string) $bill->status?->value,
                'status_label'          => BillStatus::tryFrom((string) $bill->status?->value)?->label() ?? (string) $bill->status,
                'goods_receipt_note'    => $bill->goodsReceiptNote ? [
                    'id'         => $bill->goodsReceiptNote->hash_id,
                    'grn_number' => $bill->goodsReceiptNote->grn_number,
                ] : null,
            ],
            'message' => $result['message'],
        ], 201);
    }
}
