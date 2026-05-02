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
}
