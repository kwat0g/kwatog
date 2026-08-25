<?php

declare(strict_types=1);

namespace App\Common\Controllers;

use App\Common\Services\CalendarAggregatorService;
use App\Common\Services\SettingsService;
use App\Common\Support\HashIdFilter;
use App\Modules\HR\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Series F — Task F1. Cross-module calendar.
 *
 * GET /api/v1/calendar/events?from=2026-05-01&to=2026-05-31&layers[]=holiday&layers[]=leave
 */
class CalendarController
{
    public function __construct(
        private readonly CalendarAggregatorService $service,
        private readonly SettingsService $settings,
    ) {}

    public function options(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'layers' => $this->service->layerOptions($request->user()),
                'departments' => $this->service->departmentOptions($request->user()),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from'         => ['required', 'date'],
            'to'           => ['required', 'date', 'after_or_equal:from'],
            'layers'       => ['nullable', 'array'],
            'layers.*'     => ['string', 'distinct', 'in:holiday,leave,delivery,maintenance,payroll,wo_due'],
            'department_id' => ['nullable', 'string'], // hash id, decoded below
        ]);

        $from = Carbon::parse((string) $request->query('from'))->startOfDay();
        $to   = Carbon::parse((string) $request->query('to'))->endOfDay();

        $maxRangeDays = $this->settings->requiredInt('calendar.max_range_days', 1, 3650);
        if ($from->diffInDays($to, true) > $maxRangeDays) {
            return response()->json([
                'message' => 'Date range exceeds the maximum of '.$maxRangeDays.' days.',
                'errors'  => ['to' => ['Range too large.']],
            ], 422);
        }

        $layers = $request->has('layers')
            ? array_values(array_unique(array_map('strval', (array) $request->query('layers'))))
            : ['holiday', 'leave', 'delivery', 'maintenance', 'payroll', 'wo_due'];

        $departmentId = null;
        if ($request->filled('department_id')) {
            $departmentId = HashIdFilter::decode($request->query('department_id'), Department::class);
            if ($departmentId === null || ! Department::query()
                ->where('is_active', true)
                ->whereKey($departmentId)
                ->exists()) {
                return response()->json([
                    'message' => 'The selected department is invalid.',
                    'errors' => ['department_id' => ['Select a valid department.']],
                ], 422);
            }

            abort_unless(
                $this->service->canFilterDepartment($request->user(), $departmentId),
                403,
                'You are not allowed to view that department.',
            );
        }

        $result = $this->service->eventsWithMeta($from, $to, $layers, $departmentId, $request->user());

        return response()->json([
            'data' => $result['events'],
            'meta' => [
                'from'         => $from->toDateString(),
                'to'           => $to->toDateString(),
                'count'        => count($result['events']),
                'requested_layers' => $layers,
                'layers'       => $result['meta']['layers'],
                'layer_counts' => $result['meta']['layer_counts'],
            ],
        ]);
    }
}
