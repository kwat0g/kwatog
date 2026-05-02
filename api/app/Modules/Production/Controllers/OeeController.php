<?php

declare(strict_types=1);

namespace App\Modules\Production\Controllers;

use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Services\OeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class OeeController
{
    public function __construct(private readonly OeeService $service) {}

    /** GET /production/oee/machine/{machine}?from=&to= */
    public function forMachine(Request $request, Machine $machine): JsonResponse
    {
        [$from, $to] = $this->window($request);
        return response()->json(['data' => $this->service->calculate($machine, $from, $to)]);
    }

    /** GET /production/oee/today  — bulk over all active machines, today only. */
    public function todayAll(): JsonResponse
    {
        $today = Carbon::today();
        return response()->json(['data' => $this->service->calculateForAllMachines($today, $today->copy()->endOfDay())]);
    }

    private function window(Request $request): array
    {
        $from = Carbon::parse($request->query('from', Carbon::today()->toDateString()));
        $to   = Carbon::parse($request->query('to', Carbon::today()->toDateString()))->endOfDay();
        return [$from, $to];
    }
}
