<?php

declare(strict_types=1);

namespace App\Modules\Production\Controllers;

use App\Modules\Production\Enums\LateProductionReportStatus;
use App\Modules\Production\Models\LateProductionReport;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Requests\RejectLateProductionReportRequest;
use App\Modules\Production\Requests\ReviewLateProductionReportRequest;
use App\Modules\Production\Resources\LateProductionReportResource;
use App\Modules\Production\Services\LateProductionReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LateProductionReportController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'work_order_id' => ['required', 'string', 'max:32'],
            'status' => ['nullable', Rule::in(array_map(static fn (LateProductionReportStatus $status): string => $status->value, LateProductionReportStatus::cases()))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $workOrderId = WorkOrder::tryDecodeHash($filters['work_order_id']);
        abort_if($workOrderId === null, 404);
        $workOrder = WorkOrder::query()->findOrFail($workOrderId);
        $query = LateProductionReport::query()->where('work_order_id', $workOrder->id)
            ->with(['workOrder', 'crew', 'submitter', 'reviewer', 'output']);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        $page = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 15));

        return response()->json([
            'data' => $page->getCollection()->map(fn (LateProductionReport $report): array => (new LateProductionReportResource($report))->toArray($request))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function approve(ReviewLateProductionReportRequest $request, LateProductionReport $report, LateProductionReportService $service): JsonResponse
    {
        $service->approve($report, $request->user(), $request->validated('review_reason'));

        return response()->json(['data' => (new LateProductionReportResource($report->fresh(['workOrder', 'crew', 'submitter', 'reviewer', 'output'])))->toArray($request)]);
    }

    public function reject(RejectLateProductionReportRequest $request, LateProductionReport $report, LateProductionReportService $service): JsonResponse
    {
        $report = $service->reject($report, $request->user(), $request->validated('reason'));

        return response()->json(['data' => (new LateProductionReportResource($report->load(['workOrder', 'crew', 'submitter', 'reviewer', 'output'])))->toArray($request)]);
    }
}
