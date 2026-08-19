<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Common\Support\HashIdFilter;
use App\Common\Services\SettingsService;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Requests\AcceptGrnRequest;
use App\Modules\Inventory\Requests\FinalizeGrnRequest;
use App\Modules\Inventory\Requests\RejectGrnRequest;
use App\Modules\Inventory\Requests\StoreGrnRequest;
use App\Modules\Inventory\Resources\GoodsReceiptNoteResource;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Exceptions\ClosedPeriodException;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\InvalidMovementException;

class GoodsReceiptNoteController
{
    /**
     * accept() and receiveWithQc() name four classes; the other arms name one.
     *
     * The difference is that accepting stock runs
     * StockMovementService::move(), which raises InsufficientStockException /
     * InvalidMovementException ("needed 100, available 20") and then posts the
     * GL inline via MovementGlPostingService → JournalEntryService::postSystem →
     * assertPostingAllowed, which raises ClosedPeriodException. None of those
     * three extends BusinessRuleException, and every one of them tells the
     * receiving clerk what to do, so narrowing to BusinessRuleException alone
     * would have turned three actionable 422s into "Server Error".
     *
     * Two failures on this path DO become 500s now, on purpose. GrnGlPostingService's
     * "GRNI clearing account {code} missing from chart of accounts" and "GRN
     * inventory and GRNI deltas are out of balance" are the bare
     * RuntimeExceptions 4f40a94d annotated as misconfiguration and internal
     * invariant: the code comes from `accounting.accounts.grni_code`, the remedy
     * is a COA or seed fix, and no receiving clerk can make it. A 422 there told
     * them to correct a GRN form that was already correct.
     */
    public function __construct(
        private readonly GrnService $service,
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return GoodsReceiptNoteResource::collection($this->service->list($request->query()));
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'statuses' => array_map(static fn (GrnStatus $status): array => ['value' => $status->value, 'label' => str_replace('_', ' ', ucfirst($status->value))], GrnStatus::cases()),
            'default_qc_result' => (string) $this->settings->get('inventory.grn.default_qc_result', ''),
        ]]);
    }

    public function show(GoodsReceiptNote $grn): GoodsReceiptNoteResource
    {
        return new GoodsReceiptNoteResource($this->service->show($grn));
    }

    /** Retry a failed GRN → Quality incoming-QC handoff without changing receipt facts. */
    public function retryIncomingQc(GoodsReceiptNote $grn): GoodsReceiptNoteResource
    {
        try {
            return new GoodsReceiptNoteResource($this->service->retryIncomingQcHandoff($grn));
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function store(StoreGrnRequest $request): JsonResponse
    {
        $data = $request->validated();
        $poId = HashIdFilter::decode($data['purchase_order_id'], PurchaseOrder::class) ?? (int) $data['purchase_order_id'];
        $po = PurchaseOrder::findOrFail($poId);
        try {
            $grn = $this->service->create($po, $data['items'],
                ['received_date' => $data['received_date'] ?? null, 'remarks' => $data['remarks'] ?? null],
                $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new GoodsReceiptNoteResource($grn))->response()->setStatusCode(201);
    }

    /**
     * 2026-08-08 — Complete a draft (expected) GRN: the warehouse assigns a
     * bin + actual quantity per line, and the GRN becomes pending_qc (incoming
     * QC + stock-on-accept take over from there).
     */
    public function finalize(FinalizeGrnRequest $request, GoodsReceiptNote $grn): GoodsReceiptNoteResource
    {
        try {
            $result = $this->service->finalizeDraft($grn, $request->validated()['items'], $request->user());
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }
        return new GoodsReceiptNoteResource($this->service->show($result));
    }

    public function accept(AcceptGrnRequest $request, GoodsReceiptNote $grn): GoodsReceiptNoteResource
    {
        try {
            $map = $request->input('item_accepted_map');
            if ($map) {
                // Frontend sends HashID keys; the service matches raw integer
                // line ids. Decode each key, skipping undecodable entries.
                $decoded = [];
                foreach ($map as $key => $qty) {
                    $id = ctype_digit((string) $key)
                        ? (int) $key
                        : \App\Modules\Inventory\Models\GrnItem::tryDecodeHash((string) $key);
                    if ($id !== null) {
                        $decoded[$id] = $qty;
                    }
                }
                $map = $decoded;
            }
            $result = $map
                ? $this->service->partialAccept($grn, $map, $request->user())
                : $this->service->accept($grn, $request->user());
        } catch (BusinessRuleException|ClosedPeriodException|InsufficientStockException|InvalidMovementException $e) {
            abort(422, $e->getMessage());
        }
        return new GoodsReceiptNoteResource($this->service->show($result));
    }

    public function reject(RejectGrnRequest $request, GoodsReceiptNote $grn): GoodsReceiptNoteResource
    {
        try {
            $result = $this->service->reject($grn, $request->validated()['reason'], $request->user());
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }
        return new GoodsReceiptNoteResource($this->service->show($result));
    }

    /**
     * CA2 — Single-screen receiving: GRN + QC inspection + inventory in one call.
     */
    public function receiveWithQc(Request $request): JsonResponse
    {
        $request->validate([
            'purchase_order_id'                  => ['required', 'string'],
            'received_date'                      => ['nullable', 'date'],
            'remarks'                            => ['nullable', 'string'],
            'items'                              => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id'     => ['required', 'string'],
            'items.*.item_id'                    => ['required', 'string'],
            'items.*.location_id'                => ['required', 'string'],
            'items.*.quantity_received'           => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_cost'                  => ['nullable', 'numeric', 'min:0'],
            'items.*.remarks'                    => ['nullable', 'string'],
            'qc.result'                          => ['required', 'in:passed,failed,passed_with_remarks,pending'],
            'qc.inspector_id'                    => ['nullable', 'string'],
            'qc.product_id'                      => ['nullable', 'string'],
            'qc.checks'                          => ['nullable', 'array'],
            'qc.remarks'                         => ['nullable', 'string'],
            'qc.failure_reason'                  => ['nullable', 'required_if:qc.result,failed', 'string'],
            'qc.disposition'                     => ['nullable', 'string', 'in:return_to_supplier,use_under_concession,partial_accept'],
        ]);

        // Receiving and Quality are separate responsibilities. Inventory may
        // stage a receipt as pending_qc, but only Quality may submit a
        // terminal verdict that completes an inspection or changes stock.
        if (in_array($request->input('qc.result'), ['passed', 'passed_with_remarks', 'failed'], true)) {
            abort_unless(
                $request->user()?->hasPermission('quality.inspections.manage'),
                403,
                'Only Quality may submit a terminal QC result.',
            );
        }

        $poId = HashIdFilter::decode(
            $request->input('purchase_order_id'),
            PurchaseOrder::class,
        );
        $po = PurchaseOrder::findOrFail($poId);

        try {
            $result = $this->service->receiveWithQc(
                $po,
                $request->input('items'),
                [
                    'received_date' => $request->input('received_date'),
                    'remarks'       => $request->input('remarks'),
                ],
                $request->input('qc', []),
                $request->user(),
            );
        } catch (BusinessRuleException|ClosedPeriodException|InsufficientStockException|InvalidMovementException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data'          => (new GoodsReceiptNoteResource($result['grn']))->resolve(),
            'qc_result'     => $result['qc_result'],
            'disposition'   => $result['disposition'],
            'stock_updated' => $result['stock_updated'],
        ], 201);
    }
}
