<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Common\Support\HashIdFilter;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Requests\AcceptGrnRequest;
use App\Modules\Inventory\Requests\RejectGrnRequest;
use App\Modules\Inventory\Requests\StoreGrnRequest;
use App\Modules\Inventory\Resources\GoodsReceiptNoteResource;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class GoodsReceiptNoteController
{
    public function __construct(private readonly GrnService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return GoodsReceiptNoteResource::collection($this->service->list($request->query()));
    }

    public function show(GoodsReceiptNote $grn): GoodsReceiptNoteResource
    {
        return new GoodsReceiptNoteResource($this->service->show($grn));
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
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new GoodsReceiptNoteResource($grn))->response()->setStatusCode(201);
    }

    public function accept(AcceptGrnRequest $request, GoodsReceiptNote $grn): GoodsReceiptNoteResource
    {
        try {
            $map = $request->input('item_accepted_map');
            $result = $map
                ? $this->service->partialAccept($grn, $map, $request->user())
                : $this->service->accept($grn, $request->user());
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        return new GoodsReceiptNoteResource($this->service->show($result));
    }

    public function reject(RejectGrnRequest $request, GoodsReceiptNote $grn): GoodsReceiptNoteResource
    {
        try {
            $result = $this->service->reject($grn, $request->validated()['reason'], $request->user());
        } catch (RuntimeException $e) {
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
        } catch (RuntimeException $e) {
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
