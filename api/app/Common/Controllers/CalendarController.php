<?php

declare(strict_types=1);

namespace App\Common\Controllers;

use App\Common\Services\CalendarAggregatorService;
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
    public function __construct(private readonly CalendarAggregatorService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from'         => ['required', 'date'],
            'to'           => ['required', 'date', 'after_or_equal:from'],
            'layers'       => ['nullable', 'array'],
            'layers.*'     => ['string', 'in:holiday,leave,delivery,maintenance,payroll,wo_due'],
            'department_id' => ['nullable', 'string'], // hash id, decoded below
        ]);

        $from = Carbon::parse((string) $request->query('from'))->startOfDay();
        $to   = Carbon::parse((string) $request->query('to'))->endOfDay();

        if ($from->diffInDays($to) > CalendarAggregatorService::MAX_RANGE_DAYS) {
            return response()->json([
                'message' => 'Date range exceeds the maximum of '.CalendarAggregatorService::MAX_RANGE_DAYS.' days.',
                'errors'  => ['to' => ['Range too large.']],
            ], 422);
        }

        $layers = (array) $request->query('layers', ['holiday', 'leave', 'delivery', 'maintenance', 'payroll', 'wo_due']);

        $departmentId = null;
        if ($request->filled('department_id')) {
            $decoded = app('hashids')->decode((string) $request->query('department_id'));
            $departmentId = isset($decoded[0]) ? (int) $decoded[0] : null;
        }

        $events = $this->service->events($from, $to, $layers, $departmentId, $request->user());

        return response()->json([
            'data' => $events,
            'meta' => [
                'from'         => $from->toDateString(),
                'to'           => $to->toDateString(),
                'count'        => count($events),
                'layers'       => $layers,
            ],
        ]);
    }
}
