<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Resources\BillResource;
use App\Modules\Accounting\Resources\InvoiceResource;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Accounting\Services\PdfService;
use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Requests\Supplier\AcknowledgePoRequest;
use App\Modules\B2B\Requests\Supplier\ShipmentUpdateRequest;
use App\Modules\B2B\Requests\Supplier\StoreDeliveryScheduleRequest;
use App\Modules\B2B\Requests\Supplier\SubmitInvoiceRequest;
use App\Modules\B2B\Requests\Supplier\UploadShippingDocumentsRequest;
use App\Modules\B2B\Resources\DeliveryScheduleResource;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Resources\PurchaseOrderResource;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class SupplierPortalController extends Controller
{
    public function __construct(
        private readonly PdfService $pdf,
        private readonly BillService $bills,
    ) {}

    private function user(Request $request): SupplierPortalUser
    {
        /** @var SupplierPortalUser $user */
        $user = $request->user('supplier_portal');
        return $user;
    }

    /**
     * GET /api/v1/b2b/supplier/dashboard
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $vendorId = $user->vendor_id;

        $openPoCount = PurchaseOrder::where('vendor_id', $vendorId)
            ->whereIn('status', ['approved', 'sent'])->count();

        $pendingDeliveryCount = PurchaseOrder::where('vendor_id', $vendorId)
            ->where('status', 'sent')->count();

        $unpaidInvoiceCount = Bill::where('vendor_id', $vendorId)
            ->whereIn('status', ['unpaid', 'partial'])->count();

        $totalUnpaid = Bill::where('vendor_id', $vendorId)
            ->whereIn('status', ['unpaid', 'partial'])->sum('balance');

        $recentPos = PurchaseOrder::where('vendor_id', $vendorId)
            ->orderByDesc('created_at')->limit(5)->get();

        $recentInvoices = Bill::where('vendor_id', $vendorId)
            ->with('purchaseOrder:id,po_number')
            ->orderByDesc('created_at')->limit(5)->get();

        return response()->json([
            'data' => [
                'open_po_count'         => $openPoCount,
                'pending_delivery_count' => $pendingDeliveryCount,
                'unpaid_invoice_count'   => $unpaidInvoiceCount,
                'total_unpaid_amount'    => number_format((float) $totalUnpaid, 2),
                'recent_pos'             => PurchaseOrderResource::collection($recentPos),
                'recent_invoices'        => BillResource::collection($recentInvoices),
            ],
        ]);
    }

    /**
     * GET /api/v1/b2b/supplier/purchase-orders
     */
    public function purchaseOrders(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);

        $query = PurchaseOrder::where('vendor_id', $user->vendor_id)
            ->with(['vendor:id,name', 'items.item:id,code,name,unit_of_measure'])
            ->withCount('goodsReceiptNotes');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->query('search')) {
            $query->where('po_number', 'like', "%{$search}%");
        }

        $sortField = $request->query('sort', 'created_at');
        $sortDir   = $request->query('dir', 'desc');
        $allowed   = ['po_number', 'date', 'total_amount', 'status', 'created_at'];
        if (in_array($sortField, $allowed, true)) {
            $query->orderBy($sortField, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        return PurchaseOrderResource::collection($query->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    /**
     * GET /api/v1/b2b/supplier/purchase-orders/{id}
     */
    public function purchaseOrderShow(PurchaseOrder $purchaseOrder, Request $request): PurchaseOrderResource
    {
        $user = $this->user($request);
        abort_if($purchaseOrder->vendor_id !== $user->vendor_id, 403);

        $purchaseOrder->load([
            'vendor:id,name,contact_person,email,phone,address',
            'items.item:id,code,name,unit_of_measure',
            'goodsReceiptNotes:id,grn_number,received_date,status',
            'bills:id,bill_number,total_amount,balance,status',
            'purchaseRequest:id,pr_number',
        ]);

        return new PurchaseOrderResource($purchaseOrder);
    }

    /**
     * GET /api/v1/b2b/supplier/purchase-orders/{id}/pdf
     * Download PO as PDF.
     */
    public function poPdf(PurchaseOrder $purchaseOrder, Request $request)
    {
        $user = $this->user($request);
        abort_if($purchaseOrder->vendor_id !== $user->vendor_id, 403);

        return $this->pdf->purchaseOrder($purchaseOrder);
    }

    /**
     * GET /api/v1/b2b/supplier/invoices/{id}/pdf
     * Download invoice as PDF.
     */
    public function invoicePdf(Invoice $invoice, Request $request)
    {
        $user = $this->user($request);
        abort_if(
            !$invoice->purchaseOrder || $invoice->purchaseOrder->vendor_id !== $user->vendor_id,
            403,
            'You do not have access to this invoice.'
        );

        return $this->pdf->invoice($invoice);
    }

    /**
     * POST /api/v1/b2b/supplier/purchase-orders/{id}/shipping-documents
     * Upload shipping documents for a PO.
     */
    public function uploadShippingDocuments(PurchaseOrder $purchaseOrder, UploadShippingDocumentsRequest $request): JsonResponse
    {
        $user = $this->user($request);
        abort_if($purchaseOrder->vendor_id !== $user->vendor_id, 403);

        $validated = $request->validated();

        $file = $request->file('file');
        $folder = "portal/shipping-docs/{$purchaseOrder->id}";
        $path = $file->store($folder, 'local');

        $doc = \App\Modules\B2B\Models\PortalShippingDocument::create([
            'purchase_order_id' => $purchaseOrder->id,
            'document_type'     => $validated['document_type'],
            'file_path'         => $path,
            'original_filename' => $file->getClientOriginalName(),
            'file_size_bytes'   => $file->getSize(),
            'mime_type'         => $file->getMimeType(),
            'notes'             => $validated['notes'] ?? null,
            'uploaded_by'       => $user->id,
            'uploaded_at'       => now(),
        ]);

        return response()->json([
            'data'    => new \App\Modules\B2B\Resources\PortalShippingDocumentResource($doc),
            'message' => 'Shipping document uploaded successfully.',
        ], 201);
    }

    /**
     * GET /api/v1/b2b/supplier/purchase-orders/{id}/shipping-documents
     * List shipping documents for a PO.
     */
    public function shippingDocuments(PurchaseOrder $purchaseOrder, Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_if($purchaseOrder->vendor_id !== $user->vendor_id, 403);

        $docs = \App\Modules\B2B\Models\PortalShippingDocument::where('purchase_order_id', $purchaseOrder->id)
            ->orderByDesc('uploaded_at')
            ->get();

        return response()->json([
            'data' => \App\Modules\B2B\Resources\PortalShippingDocumentResource::collection($docs),
        ]);
    }

    /**
     * GET /api/v1/b2b/supplier/shipping-documents/{id}/download
     * Download a shipping document file.
     */
    public function downloadShippingDocument(string $id, Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $user = $this->user($request);

        $doc = \App\Modules\B2B\Models\PortalShippingDocument::findOrFail(
            \App\Common\Support\HashIdFilter::decode($id, \App\Modules\B2B\Models\PortalShippingDocument::class)
        );

        // Ensure the document belongs to a PO owned by this vendor
        $po = $doc->purchaseOrder;
        abort_if(!$po || $po->vendor_id !== $user->vendor_id, 403);

        if (! \Illuminate\Support\Facades\Storage::disk('local')->exists($doc->file_path)) {
            abort(404, 'File not found.');
        }

        return \Illuminate\Support\Facades\Storage::disk('local')->download($doc->file_path, $doc->original_filename);
    }

    /**
     * POST /api/v1/b2b/supplier/purchase-orders/{id}/submit-invoice
     * Supplier submits their invoice; creates a draft Bill in Accounts Payable.
     */
    public function submitInvoice(PurchaseOrder $purchaseOrder, SubmitInvoiceRequest $request): JsonResponse
    {
        $user = $this->user($request);
        abort_if($purchaseOrder->vendor_id !== $user->vendor_id, 403);

        $validated = $request->validated();

        // Build bill items from PO items
        $purchaseOrder->load(['vendor:id,name', 'items.item']);

        $defaultAccountHashId = $this->defaultExpenseAccountHashId();

        $items = $purchaseOrder->items->map(fn ($poItem) => [
            'expense_account_id' => $defaultAccountHashId,
            'description'        => $poItem->description,
            'quantity'           => (string) $poItem->quantity,
            'unit'               => $poItem->unit ?? 'pcs',
            'unit_price'         => (string) $poItem->unit_price,
        ])->toArray();

        if (empty($items)) {
            return response()->json(['message' => 'This purchase order has no items to bill.'], 422);
        }

        // Portal users don't have accounting.bills.create permission;
        // use an internal system user for the audit trail.
        $internalUser = User::first();

        if (! $internalUser) {
            return response()->json(['message' => 'No internal user available to create the bill.'], 500);
        }

        try {
            $bill = $this->bills->create([
                'bill_number'      => $validated['bill_number'],
                'vendor_id'        => $purchaseOrder->vendor->hash_id,
                'purchase_order_id'=> $purchaseOrder->hash_id,
                'date'             => $validated['date'],
                'due_date'         => $validated['due_date'] ?? $validated['date'],
                'is_vatable'       => $validated['is_vatable'] ?? true,
                'remarks'          => $validated['remarks'] ?? null,
                'items'            => $items,
            ], $internalUser);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code'    => 'bill_creation_failed',
            ], 422);
        }

        // If an invoice file was uploaded, store it as a document
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $folder = "portal/supplier-invoices/{$bill->id}";
            $path = $file->store($folder, 'local');

            \App\Modules\B2B\Models\PortalShippingDocument::create([
                'purchase_order_id' => $purchaseOrder->id,
                'bill_id'           => $bill->id,
                'document_type'     => 'supplier_invoice',
                'file_path'         => $path,
                'original_filename' => $file->getClientOriginalName(),
                'file_size_bytes'   => $file->getSize(),
                'mime_type'         => $file->getMimeType(),
                'notes'             => 'Supplier-submitted invoice for bill ' . $bill->bill_number,
                'uploaded_by'       => $user->id,
                'uploaded_at'       => now(),
            ]);
        }

        return response()->json([
            'data'    => [
                'id'          => $bill->hash_id,
                'bill_number' => $bill->bill_number,
                'total_amount'=> (string) $bill->total_amount,
                'status'      => (string) $bill->status?->value,
            ],
            'message' => 'Invoice submitted successfully. Bill has been created in Accounts Payable.',
        ], 201);
    }

    /**
     * Find the default expense account hash_id for bill items.
     */
    private function defaultExpenseAccountHashId(): string
    {
        $account = Account::query()
            ->where('code', '5000')
            ->orWhere('name', 'like', '%Cost of Goods Sold%')
            ->first();

        if (! $account) {
            throw new \RuntimeException('No COGS/expense account configured. Please contact the administrator.');
        }
        return $account->hash_id;
    }

    /**
     * GET /api/v1/b2b/supplier/statement-of-account
     * Return vendor aging buckets + open bills.
     */
    public function statementOfAccount(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $vendorId = $user->vendor_id;

        $openBills = Bill::where('vendor_id', $vendorId)
            ->with('purchaseOrder:id,po_number')
            ->whereIn('status', ['unpaid', 'partial'])
            ->orderBy('due_date')
            ->get();

        $aging = ['current' => 0, 'd1_30' => 0, 'd31_60' => 0, 'd61_90' => 0, 'd91_plus' => 0];
        $totalOutstanding = 0;

        foreach ($openBills as $bill) {
            $bucket = $bill->agingBucket();
            $balance = (float) $bill->balance;
            if (isset($aging[$bucket])) {
                $aging[$bucket] += $balance;
            }
            $totalOutstanding += $balance;
        }

        $vendor = Vendor::find($vendorId);

        return response()->json([
            'data' => [
                'vendor_name'      => $vendor?->name,
                'total_outstanding' => number_format($totalOutstanding, 2),
                'aging_buckets'     => [
                    'current' => number_format($aging['current'], 2),
                    'd1_30'   => number_format($aging['d1_30'], 2),
                    'd31_60'  => number_format($aging['d31_60'], 2),
                    'd61_90'  => number_format($aging['d61_90'], 2),
                    'd91_plus'=> number_format($aging['d91_plus'], 2),
                ],
                'open_bills' => BillResource::collection($openBills),
                'as_of_date' => now()->toDateString(),
            ],
        ]);
    }

    /**
     * GET /api/v1/b2b/supplier/delivery-schedules
     * List delivery schedules for this vendor's POs.
     */
    public function deliverySchedules(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $schedules = DeliverySchedule::where('vendor_id', $user->vendor_id)
            ->with('purchaseOrder:id,po_number')
            ->orderByDesc('month')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => DeliveryScheduleResource::collection($schedules),
        ]);
    }

    /**
     * POST /api/v1/b2b/supplier/delivery-schedules
     * Supplier submits a delivery schedule for a PO.
     */
    public function storeDeliverySchedule(StoreDeliveryScheduleRequest $request): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validated();

        $decodedPoId = HashIdFilter::decode($validated['purchase_order_id'], PurchaseOrder::class);
        $po = PurchaseOrder::findOrFail($decodedPoId);

        $schedule = DeliverySchedule::create([
            'vendor_id'         => $user->vendor_id,
            'purchase_order_id' => $po->id,
            'month'             => $validated['month'],
            'status'            => 'submitted',
            'lines'             => $validated['lines'],
        ]);

        return response()->json([
            'data'    => new DeliveryScheduleResource($schedule),
            'message' => 'Delivery schedule submitted successfully.',
        ], 201);
    }

    /**
     * POST /api/v1/b2b/supplier/purchase-orders/{id}/acknowledge
     */
    public function acknowledgePo(PurchaseOrder $purchaseOrder, AcknowledgePoRequest $request): JsonResponse
    {
        $user = $this->user($request);
        abort_if($purchaseOrder->vendor_id !== $user->vendor_id, 403);

        $validated = $request->validated();

        $purchaseOrder->update([
            'status'                 => 'sent',
            'sent_to_supplier_at'    => now(),
            'expected_delivery_date' => $validated['expected_delivery_date'] ?? $purchaseOrder->expected_delivery_date,
            'remarks'                => $validated['notes'] ?? $purchaseOrder->remarks,
        ]);

        return response()->json(['message' => 'Purchase order acknowledged.']);
    }

    /**
     * POST /api/v1/b2b/supplier/purchase-orders/{id}/shipment-update
     */
    public function updateShipment(PurchaseOrder $purchaseOrder, ShipmentUpdateRequest $request): JsonResponse
    {
        $user = $this->user($request);
        abort_if($purchaseOrder->vendor_id !== $user->vendor_id, 403);

        $validated = $request->validated();

        $estimatedArrival = $validated['estimated_arrival'] ?? $purchaseOrder->expected_delivery_date;
        $carrier   = $validated['carrier'] ?? 'N/A';
        $tracking  = $validated['tracking_number'] ?? 'N/A';
        $prevNotes = $purchaseOrder->remarks ? $purchaseOrder->remarks . "\n" : '';

        $purchaseOrder->update([
            'expected_delivery_date' => $estimatedArrival,
            'remarks'                => $prevNotes . "Shipment: {$carrier} / {$tracking}",
        ]);

        return response()->json(['message' => 'Shipment information updated.']);
    }

    /**
     * GET /api/v1/b2b/supplier/invoices
     */
    public function invoices(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);

        $query = Bill::where('vendor_id', $user->vendor_id)
            ->with(['purchaseOrder:id,po_number', 'vendor:id,name'])
            ->orderByDesc('created_at');

        return BillResource::collection($query->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    /**
     * GET /api/v1/b2b/supplier/invoices/{id}
     */
    public function invoiceDetail(Bill $invoice, Request $request): BillResource
    {
        $user = $this->user($request);
        abort_if(
            $invoice->vendor_id !== $user->vendor_id,
            403,
            'You do not have access to this invoice.'
        );

        $invoice->load([
            'purchaseOrder:id,po_number,date,total_amount,status',
            'vendor:id,name',
            'items',
            'payments',
        ]);

        return new BillResource($invoice);
    }

    /**
     * GET /api/v1/b2b/supplier/deliveries
     */
    public function deliveries(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $query = GoodsReceiptNote::where('vendor_id', $user->vendor_id)
            ->with(['purchaseOrder:id,po_number'])
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    /**
     * GET /api/v1/b2b/supplier/ppap-submissions
     * Read-only list of this supplier's PPAP submissions (IATF 16949).
     */
    public function ppapSubmissions(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $query = \App\Modules\Quality\Models\PpapSubmission::query()
            ->where('vendor_id', $user->vendor_id)
            ->with(['item:id,code,name', 'elements'])
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json([
            'data' => \App\Modules\Quality\Resources\PpapSubmissionResource::collection(
                $query->paginate(min((int) $request->query('per_page', 25), 100))
            ),
        ]);
    }

}
