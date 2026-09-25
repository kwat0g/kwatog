<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Controllers;

use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Requests\AssignDeliveryRequest;
use App\Modules\SupplyChain\Requests\AmendDeliveryAttemptRequest;
use App\Modules\SupplyChain\Requests\ReserveDeliveryStockRequest;
use App\Modules\SupplyChain\Requests\RetryDeliveryCostRequest;
use App\Modules\SupplyChain\Services\DeliveryStockReservationService;
use App\Modules\SupplyChain\Services\DeliveryCostRecognitionService;
use App\Modules\SupplyChain\Requests\CreateDeliveryRequest;
use App\Modules\SupplyChain\Requests\DeliveryInspectionOptionsRequest;
use App\Modules\SupplyChain\Requests\DeliveryFormOptionsRequest;
use App\Modules\SupplyChain\Requests\RescheduleDeliveryRequest;
use App\Modules\SupplyChain\Requests\StoreDeliveryAttemptOutcomeRequest;
use App\Modules\SupplyChain\Requests\StoreTruckReturnReceiptRequest;
use App\Modules\SupplyChain\Resources\DeliveryResource;
use App\Modules\SupplyChain\Services\DeliveryAttemptOutcomeService;
use App\Modules\SupplyChain\Services\DeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeliveryController
{
    public function __construct(
        private readonly DeliveryService $service,
        private readonly DeliveryAttemptOutcomeService $attemptOutcomes,
        private readonly DeliveryStockReservationService $reservations,
        private readonly DeliveryCostRecognitionService $costs,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $rows = $this->service->list($request->query());
        $rows->getCollection()->loadMissing(['stockReservationBatch', 'stockReservations.item', 'stockReservations.location', 'stockReservations.deliveryItem', 'costHandoffs']);
        return DeliveryResource::collection($rows);
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'statuses' => array_map(static fn (DeliveryStatus $status): array => [
                'value' => $status->value,
                'label' => str_replace('_', ' ', ucfirst($status->value)),
                'next_status' => self::nextStatus($status)?->value,
                'is_terminal' => $status->isTerminal(),
            ], DeliveryStatus::cases()),
        ]]);
    }

    public function formOptions(DeliveryFormOptionsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->service->formOptions($request->validated())]);
    }

    public function inspectionOptions(DeliveryInspectionOptionsRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->inspectionOptions(
                (int) $request->validated()['sales_order_id'],
            ),
        ]);
    }

    public function driverOptions(): JsonResponse
    {
        return response()->json([
            'data' => User::query()
                ->where('is_active', true)
                ->whereHas('role', static fn ($query) => $query->where('slug', 'driver'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(static fn (User $driver): array => [
                    'id' => $driver->hash_id,
                    'name' => $driver->name,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * The one forward step a plain status change may take. Confirmation is
     * its own action (proof, invoice, SO reconciliation) and the status route
     * refuses it, so offering it here rendered a button that always failed.
     */
    private static function nextStatus(DeliveryStatus $status): ?DeliveryStatus
    {
        if ($status === DeliveryStatus::ReturnPending) {
            return null;
        }
        foreach (DeliveryStatus::cases() as $candidate) {
            if (in_array($candidate, [
                DeliveryStatus::ReturnPending,
                DeliveryStatus::Returned,
                DeliveryStatus::Confirmed,
                DeliveryStatus::Cancelled,
            ], true)) {
                continue;
            }
            if ($status->canTransitionTo($candidate)) return $candidate;
        }
        return null;
    }

    public function show(Delivery $delivery): DeliveryResource
    {
        return new DeliveryResource($this->service->show($delivery));
    }

    public function store(CreateDeliveryRequest $request): DeliveryResource
    {
        return new DeliveryResource($this->service->create(
            $request->validated(),
            $request->user(),
            $request->idempotencyKey(),
        ));
    }

    public function assign(AssignDeliveryRequest $request, Delivery $delivery): DeliveryResource
    {
        return new DeliveryResource($this->service->assign(
            $delivery,
            $request->validated(),
            $request->user(),
        ));
    }

    public function reschedule(RescheduleDeliveryRequest $request, Delivery $delivery): DeliveryResource
    {
        $data = $request->validated();

        return new DeliveryResource($this->service->reschedule(
            $delivery,
            (string) $data['scheduled_date'],
            (string) $data['reason'],
            $request->user(),
        ));
    }

    public function updateStatus(Request $request, Delivery $delivery): DeliveryResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(DeliveryStatus::values())],
            'note'   => ['nullable', 'string', 'max:500'],
        ]);
        return new DeliveryResource($this->service->updateStatus(
            $delivery,
            DeliveryStatus::from((string) $data['status']),
            $data['note'] ?? null,
        ));
    }

    public function uploadReceipt(Request $request, Delivery $delivery): DeliveryResource
    {
        $request->validate(['file' => ['required', 'mimes:jpg,jpeg,png,webp', 'max:10240']]);
        return new DeliveryResource(
            $this->service->uploadReceiptPhoto($delivery, $request->file('file'), $request->user())
        );
    }

    public function confirm(Request $request, Delivery $delivery): DeliveryResource
    {
        $data = $request->validate([
            'receiver_name'     => ['nullable', 'string', 'max:200'],
            'receiver_position' => ['nullable', 'string', 'max:100'],
            'delivery_remarks'  => ['nullable', 'string', 'max:1000'],
        ]);
        return new DeliveryResource($this->service->confirm($delivery, $request->user(), $data));
    }

    public function reportAttemptOutcome(StoreDeliveryAttemptOutcomeRequest $request, Delivery $delivery): DeliveryResource
    {
        return new DeliveryResource($this->attemptOutcomes->report(
            $delivery,
            $request->user(),
            $request->validated(),
        ));
    }

    public function receiveTruckReturn(StoreTruckReturnReceiptRequest $request, Delivery $delivery): DeliveryResource
    {
        return new DeliveryResource($this->attemptOutcomes->receive(
            $delivery,
            $request->user(),
            $request->validated(),
        ));
    }

    public function amendAttemptOutcome(AmendDeliveryAttemptRequest $request, Delivery $delivery): DeliveryResource
    {
        return new DeliveryResource($this->attemptOutcomes->amend($delivery, $request->user(), $request->validated(), false));
    }

    public function receiveLateTruckReturn(StoreTruckReturnReceiptRequest $request, Delivery $delivery): DeliveryResource
    {
        return new DeliveryResource($this->attemptOutcomes->receiveLate($delivery, $request->user(), $request->validated()));
    }

    public function reserveStock(ReserveDeliveryStockRequest $request, Delivery $delivery): DeliveryResource
    {
        $this->reservations->reserveForDelivery($delivery, $request->user(), $request->validated('request_key'), true);
        return new DeliveryResource($this->service->show($delivery));
    }

    public function retryCost(RetryDeliveryCostRequest $request, Delivery $delivery): DeliveryResource
    {
        $this->costs->retry($delivery, $request->validated('kind'), $request->validated('request_key'), $request->user());
        return new DeliveryResource($this->service->show($delivery));
    }

    public function retryCoc(Request $request, Delivery $delivery): DeliveryResource
    {
        try {
            return new DeliveryResource($this->service->retryCocHandoff($delivery, $request->user()));
        } catch (\App\Common\Exceptions\BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function retryInvoice(Request $request, Delivery $delivery): DeliveryResource
    {
        try {
            return new DeliveryResource($this->service->retryInvoiceHandoff($delivery, $request->user()));
        } catch (\App\Common\Exceptions\BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }
    }

    /**
     * Stream the receipt photo for a delivery.
     * The photo lives on the local disk and is NEVER accessible via a public
     * /storage/ URL. Route must be protected by permission:supply_chain.view.
     */
    public function receiptPhoto(Delivery $delivery): StreamedResponse
    {
        if (! $delivery->receipt_photo_path) {
            abort(404, 'No receipt photo for this delivery.');
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($delivery->receipt_photo_path)) {
            abort(404, 'Receipt photo file not found on disk.');
        }

        $contents = $disk->get($delivery->receipt_photo_path);
        $mime     = $disk->mimeType($delivery->receipt_photo_path) ?? 'application/octet-stream';
        $filename = basename($delivery->receipt_photo_path);

        return response()->stream(
            fn () => print $contents,
            200,
            [
                'Content-Type'        => $mime,
                'Cache-Control'       => 'private, no-store, max-age=0',
                'Content-Disposition' => sprintf('inline; filename="%s"', $filename),
            ],
        );
    }

    public function destroy(Delivery $delivery): JsonResponse
    {
        $this->service->delete($delivery);
        return response()->json([], 204);
    }

    public function restore(Delivery $delivery): JsonResponse
    {
        $delivery->restore();
        return response()->json(['message' => 'Delivery restored.']);
    }
}
