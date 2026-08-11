<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Requests\StoreBillPaymentRequest;
use App\Modules\Accounting\Requests\StoreBillRequest;
use App\Modules\Accounting\Resources\BillPaymentResource;
use App\Modules\Accounting\Resources\BillResource;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Purchasing\Exceptions\ThreeWayMatchException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
            $bill = $this->service->create($request->validated(), $request->user());
        } catch (\App\Modules\Purchasing\Exceptions\ThreeWayMatchException $e) {
            return response()->json([
                'message'           => $e->getMessage(),
                'code'              => 'three_way_match_blocked',
                'three_way_match'   => $e->details,
            ], 422);
        } catch (\RuntimeException $e) {
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
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new BillResource($bill);
    }

    public function cancel(Request $request, Bill $bill): BillResource|JsonResponse
    {
        try {
            $bill = $this->service->cancel($bill, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new BillResource($bill);
    }

    public function recordPayment(StoreBillPaymentRequest $request, Bill $bill): JsonResponse
    {
        try {
            $payment = $this->service->recordPayment($bill, $request->validated(), $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new BillPaymentResource($payment))->response()->setStatusCode(201);
    }
}
