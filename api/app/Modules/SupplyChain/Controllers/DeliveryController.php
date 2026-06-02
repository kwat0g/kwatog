<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Controllers;

use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Requests\CreateDeliveryRequest;
use App\Modules\SupplyChain\Resources\DeliveryResource;
use App\Modules\SupplyChain\Services\DeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeliveryController
{
    public function __construct(private readonly DeliveryService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return DeliveryResource::collection($this->service->list($request->query()));
    }

    public function show(Delivery $delivery): DeliveryResource
    {
        return new DeliveryResource($this->service->show($delivery));
    }

    public function store(CreateDeliveryRequest $request): DeliveryResource
    {
        return new DeliveryResource($this->service->create($request->validated(), $request->user()));
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
            throw new RuntimeException('Receipt photo file not found on disk.');
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
}
