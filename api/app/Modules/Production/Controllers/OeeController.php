<?php

declare(strict_types=1);

namespace App\Modules\Production\Controllers;

use App\Common\Services\SettingsService;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Services\OeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class OeeController
{
    public function __construct(
        private readonly OeeService $service,
        private readonly SettingsService $settings,
    ) {}

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

    /**
     * Sprint P10 — full OEE report.
     *
     * GET /production/oee/report?from=YYYY-MM-DD&to=YYYY-MM-DD&machine_id={hash}
     *
     * `from` and `to` default to the current month if omitted.
     */
    public function report(Request $request): JsonResponse
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->string('from')->toString())->startOfDay()
            : Carbon::now()->startOfMonth();
        $to = $request->filled('to')
            ? Carbon::parse($request->string('to')->toString())->endOfDay()
            : Carbon::now()->endOfDay();

        $machine = null;
        if ($request->filled('machine_id')) {
            $raw = $request->string('machine_id')->toString();
            $id = Machine::tryDecodeHash($raw);
            if ($id !== null) {
                $machine = Machine::query()->find($id);
            }
        }

        $data = $this->service->report($from, $to, $machine);
        $data['benchmark_pct'] = round($this->settings->requiredFloat('alerts.oee.quality_rate_threshold', 0, 1) * 100, 1);
        $data['display_policy'] = [
            'world_class_ratio' => $this->settings->requiredFloat('production.oee.world_class_ratio', 0, 1),
            'on_track_ratio' => $this->settings->requiredFloat('production.oee.on_track_ratio', 0, 1),
        ];

        return response()->json(['data' => $data]);
    }

    private function window(Request $request): array
    {
        $from = Carbon::parse($request->query('from', Carbon::today()->toDateString()));
        $to   = Carbon::parse($request->query('to', Carbon::today()->toDateString()))->endOfDay();
        return [$from, $to];
    }
}
