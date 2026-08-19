<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Controllers;

use App\Common\Services\SettingsService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Requests\CancelPurchaseOrderRequest;
use App\Modules\Purchasing\Requests\StorePurchaseOrderRequest;
use App\Modules\Purchasing\Requests\UpdatePurchaseOrderRequest;
use App\Modules\Purchasing\Resources\PurchaseOrderResource;
use App\Modules\Purchasing\Services\PurchaseOrderPdfService;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;
use App\Common\Exceptions\BusinessRuleException;

class PurchaseOrderController
{
    public function __construct(
        private readonly PurchaseOrderService $service,
        private readonly PurchaseOrderPdfService $pdf,
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PurchaseOrderResource::collection($this->service->list($request->query(), $request->user()));
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'statuses' => array_map(static fn (PurchaseOrderStatus $status): array => [
                'value' => $status->value,
                'label' => str_replace('_', ' ', ucfirst($status->value)),
            ], PurchaseOrderStatus::cases()),
            'approval_sla_hours' => $this->settings->requiredInt('approvals.reminder_hours', 1),
        ]]);
    }

    public function show(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return new PurchaseOrderResource($this->service->show($purchaseOrder));
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        try {
            $po = $this->service->create($request->validated(), $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new PurchaseOrderResource($po))->response()->setStatusCode(201);
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        try { $po = $this->service->update($purchaseOrder, $request->validated()); }
        catch (BusinessRuleException $e) { abort(422, $e->getMessage()); }
        return new PurchaseOrderResource($po);
    }

    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        try { $this->service->delete($purchaseOrder); }
        catch (BusinessRuleException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return response()->json(null, 204);
    }

    public function restore(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->restore();
        return response()->json(['message' => 'Purchase order restored.']);
    }

    public function submit(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        try { $po = $this->service->submit($purchaseOrder); }
        catch (BusinessRuleException $e) { abort(422, $e->getMessage()); }
        return new PurchaseOrderResource($this->service->show($po));
    }

    public function acknowledgeBudget(Request $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        try { $po = $this->service->acknowledgeBudget($purchaseOrder, $request->user()); }
        catch (BusinessRuleException $e) { abort(422, $e->getMessage()); }
        return new PurchaseOrderResource($this->service->show($po));
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        try { $po = $this->service->approve($purchaseOrder, $request->user(), $request->input('remarks')); }
        catch (BusinessRuleException $e) { abort(422, $e->getMessage()); }
        return new PurchaseOrderResource($this->service->show($po));
    }

    public function reject(Request $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $reason = $request->input('reason');
        if (! $reason) abort(422, 'Reason is required.');
        try { $po = $this->service->reject($purchaseOrder, $request->user(), $reason); }
        catch (BusinessRuleException $e) { abort(422, $e->getMessage()); }
        return new PurchaseOrderResource($this->service->show($po));
    }

    public function send(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        try { $po = $this->service->markAsSent($purchaseOrder); }
        catch (BusinessRuleException $e) { abort(422, $e->getMessage()); }
        return new PurchaseOrderResource($this->service->show($po));
    }

    public function cancel(CancelPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        try { $po = $this->service->cancel($purchaseOrder, $request->validated()['reason']); }
        catch (BusinessRuleException $e) { abort(422, $e->getMessage()); }
        return new PurchaseOrderResource($this->service->show($po));
    }

    public function close(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        try { $po = $this->service->close($purchaseOrder); }
        catch (BusinessRuleException $e) { abort(422, $e->getMessage()); }
        return new PurchaseOrderResource($this->service->show($po));
    }

    public function pdf(PurchaseOrder $purchaseOrder): Response
    {
        return $this->pdf->render($purchaseOrder);
    }
}
