<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Requests\ApproveOvertimeRequestRequest;
use App\Modules\Attendance\Requests\RejectOvertimeRequestRequest;
use App\Modules\Attendance\Requests\StoreOvertimeRequestRequest;
use App\Modules\Attendance\Resources\OvertimeRequestResource;
use App\Modules\Attendance\Services\OvertimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OvertimeController
{
    public function __construct(private readonly OvertimeService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return OvertimeRequestResource::collection($this->service->list($request->query(), $request->user()));
    }

    public function store(StoreOvertimeRequestRequest $request): JsonResponse
    {
        $ot = $this->service->create($request->validatedData());
        return (new OvertimeRequestResource($ot))->response()->setStatusCode(201);
    }

    public function show(OvertimeRequest $overtime): OvertimeRequestResource
    {
        return new OvertimeRequestResource($overtime->load(['employee', 'approver']));
    }

    public function approve(ApproveOvertimeRequestRequest $request, OvertimeRequest $overtime): OvertimeRequestResource
    {
        $ot = $this->service->approve($overtime, $request->user(), $request->input('remarks'));
        return new OvertimeRequestResource($ot);
    }

    public function reject(RejectOvertimeRequestRequest $request, OvertimeRequest $overtime): OvertimeRequestResource
    {
        $ot = $this->service->reject($overtime, $request->user(), $request->input('reason'));
        return new OvertimeRequestResource($ot);
    }

    /**
     * L-23 — Bulk approve. Body: { ids: ["hash1", "hash2", ...], remarks?: string }.
     * Returns 200 with summary {approved_count, failed} so the SPA can surface
     * partial successes; per-row failures don't abort the batch.
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids'     => ['required', 'array', 'min:1', 'max:100'],
            'ids.*'   => ['required', 'string'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $decoded = collect($validated['ids'])
            ->map(fn (string $hash) => \App\Common\Support\HashIdFilter::decode($hash, OvertimeRequest::class))
            ->filter()
            ->values()
            ->all();

        $result = $this->service->bulkApprove(
            $decoded,
            $request->user(),
            $validated['remarks'] ?? null,
        );

        return response()->json([
            'message'        => sprintf('%d approved, %d failed.', count($result['approved']), count($result['failed'])),
            'approved_count' => count($result['approved']),
            'failed'         => $result['failed'],
            'data'           => OvertimeRequestResource::collection($result['approved']),
        ]);
    }
}
