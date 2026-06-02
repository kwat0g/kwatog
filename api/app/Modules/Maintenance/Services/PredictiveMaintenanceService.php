<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Services;

use App\Modules\Maintenance\Enums\MaintenancePriority;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderType;
use App\Modules\Maintenance\Models\MachineConditionReading;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Models\Machine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADV8 — Maintenance Automation.
 * Analyzes machine condition readings against configurable thresholds and
 * auto-generates corrective maintenance work orders when readings indicate
 * impending failure.
 *
 * Thresholds are hard-coded per metric for the MVP; future iterations can
 * make them machine-specific and editable via settings.
 */
class PredictiveMaintenanceService
{
    /**
     * Default thresholds per metric. When a reading exceeds (or drops below)
     * these bounds a corrective WO is triggered.
     */
    private const THRESHOLDS = [
        'temperature' => ['min' => null, 'max' => 85.0],   // °C — normal ~40-60
        'vibration'   => ['min' => null, 'max' => 7.1],    // mm/s ISO 10816 severe
        'pressure'    => ['min' => 2.0,  'max' => 12.0],  // bar hydraulic
        'current'     => ['min' => null, 'max' => 150.0], // % of rated draw
        'oil_quality' => ['min' => 70.0, 'max' => null],  // % cleanliness index
    ];

    /**
     * Number of consecutive threshold breaches required before a WO is
     * materialised (prevents false positives from single bad readings).
     */
    private const BREACH_WINDOW = 3;

    public function __construct(
        private readonly MaintenanceWorkOrderService $workOrders,
    ) {}

    /**
     * Record a new condition reading and evaluate whether a corrective WO
     * should be created.
     *
     * @return array{reading: MachineConditionReading, triggered: bool, reason?: string}
     */
    public function recordAndEvaluate(array $data, \App\Modules\Auth\Models\User $by): array
    {
        $reading = DB::transaction(function () use ($data, $by) {
            return MachineConditionReading::create([
                'machine_id'  => (int) $data['machine_id'],
                'metric'      => $data['metric'],
                'value'       => $data['value'],
                'unit'        => $data['unit'] ?? self::defaultUnit($data['metric']),
                'recorded_at' => $data['recorded_at'] ?? now(),
                'source'      => $data['source'] ?? 'manual',
                'notes'       => $data['notes'] ?? null,
                'recorded_by' => $by->id,
            ]);
        });

        $result = ['reading' => $reading, 'triggered' => false];

        if ($this->isBreach((string) $data['metric'], (float) $data['value'])) {
            $reason = sprintf(
                '%s reading %.3f %s exceeds safe threshold.',
                $data['metric'],
                (float) $data['value'],
                $result['reading']->unit,
            );

            if ($this->shouldTriggerWorkOrder((int) $data['machine_id'], (string) $data['metric'])) {
                $wo = $this->createCorrectiveWorkOrder((int) $data['machine_id'], $reason, $by);
                $result['triggered'] = true;
                $result['reason'] = $reason;
                $result['work_order'] = $wo;
                Log::info('PredictiveMaintenance: triggered corrective WO', [
                    'machine_id' => $data['machine_id'],
                    'metric'     => $data['metric'],
                    'value'      => $data['value'],
                    'mwo_number' => $wo->mwo_number,
                ]);
            } else {
                $result['reason'] = $reason . ' (insufficient consecutive breaches)';
            }
        }

        return $result;
    }

    /**
     * Evaluate all machines for threshold breaches and create WOs where needed.
     * Designed to be called from a scheduled command or the daily cron.
     */
    public function evaluateAllMachines(\App\Modules\Auth\Models\User $by): int
    {
        $count = 0;
        // Exclude 'maintenance' status — readings during repair work
        // (e.g. high vibration) could produce false-positive triggers.
        $machines = Machine::query()
            ->whereIn('status', ['idle', 'running'])
            ->get();

        foreach ($machines as $machine) {
            foreach (array_keys(self::THRESHOLDS) as $metric) {
                $latest = $this->latestReading((int) $machine->id, $metric);
                if ($latest && $this->isBreach($metric, (float) $latest->value)) {
                    if ($this->shouldTriggerWorkOrder((int) $machine->id, $metric)) {
                        $reason = sprintf(
                            '%s reading %.3f %s exceeds safe threshold.',
                            $metric,
                            (float) $latest->value,
                            $latest->unit,
                        );
                        $this->createCorrectiveWorkOrder((int) $machine->id, $reason, $by);
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Latest reading for a machine + metric.
     */
    public function latestReading(int $machineId, string $metric): ?MachineConditionReading
    {
        return MachineConditionReading::query()
            ->where('machine_id', $machineId)
            ->where('metric', $metric)
            ->orderByDesc('recorded_at')
            ->first();
    }

    /**
     * Trend for a machine + metric over the last N readings.
     *
     * @return array<int, array{recorded_at: string, value: float}>
     */
    public function trend(int $machineId, string $metric, int $limit = 30): array
    {
        return MachineConditionReading::query()
            ->where('machine_id', $machineId)
            ->where('metric', $metric)
            ->orderByDesc('recorded_at')
            ->limit($limit)
            ->get()
            ->map(fn (MachineConditionReading $r) => [
                'recorded_at' => $r->recorded_at->toISOString(),
                'value'       => (float) $r->value,
            ])
            ->reverse()
            ->values()
            ->toArray();
    }

    /**
     * All metrics and their latest values for a machine.
     *
     * @return array<int, array{metric: string, value: float, unit: string, recorded_at: string|null, status: 'ok'|'warning'|'critical'}>
     */
    public function machineHealthSnapshot(int $machineId): array
    {
        $out = [];
        foreach (array_keys(self::THRESHOLDS) as $metric) {
            $r = $this->latestReading($machineId, $metric);
            if (! $r) {
                $out[] = [
                    'metric'       => $metric,
                    'value'        => null,
                    'unit'         => self::defaultUnit($metric),
                    'recorded_at'  => null,
                    'status'       => 'ok',
                ];
                continue;
            }
            $breach = $this->isBreach($metric, (float) $r->value);
            $consecutive = $this->consecutiveBreachCount($machineId, $metric);
            $out[] = [
                'metric'       => $metric,
                'value'        => (float) $r->value,
                'unit'         => $r->unit,
                'recorded_at'  => $r->recorded_at->toISOString(),
                'status'       => $breach && $consecutive >= self::BREACH_WINDOW ? 'critical' : ($breach ? 'warning' : 'ok'),
            ];
        }
        return $out;
    }

    private function isBreach(string $metric, float $value): bool
    {
        $cfg = self::THRESHOLDS[$metric] ?? null;
        if (! $cfg) return false;
        if ($cfg['max'] !== null && $value > $cfg['max']) return true;
        if ($cfg['min'] !== null && $value < $cfg['min']) return true;
        return false;
    }

    private function shouldTriggerWorkOrder(int $machineId, string $metric): bool
    {
        return $this->consecutiveBreachCount($machineId, $metric) >= self::BREACH_WINDOW
            && ! $this->hasOpenCorrectiveWoForMachine($machineId, $metric);
    }

    private function consecutiveBreachCount(int $machineId, string $metric): int
    {
        $recent = MachineConditionReading::query()
            ->where('machine_id', $machineId)
            ->where('metric', $metric)
            ->orderByDesc('recorded_at')
            ->limit(self::BREACH_WINDOW * 2)
            ->get();

        $count = 0;
        foreach ($recent as $reading) {
            if ($this->isBreach($metric, (float) $reading->value)) {
                $count++;
            } else {
                break; // stop at first non-breach (consecutive only)
            }
        }
        return $count;
    }

    private function hasOpenCorrectiveWoForMachine(int $machineId, string $metric): bool
    {
        $keyword = 'predictive';
        return MaintenanceWorkOrder::query()
            ->where('maintainable_type', 'machine')
            ->where('maintainable_id', $machineId)
            ->where('type', MaintenanceWorkOrderType::Corrective->value)
            ->whereIn('status', ['open', 'assigned', 'in_progress'])
            ->where('description', 'like', "%{$keyword}%")
            ->exists();
    }

    private function createCorrectiveWorkOrder(int $machineId, string $reason, \App\Modules\Auth\Models\User $by): MaintenanceWorkOrder
    {
        $machine = Machine::find($machineId);
        $description = sprintf(
            '[Predictive] %s — Auto-generated from condition monitoring.',
            $reason,
        );

        return $this->workOrders->create([
            'maintainable_type' => 'machine',
            'maintainable_id'   => $machineId,
            'type'              => MaintenanceWorkOrderType::Corrective->value,
            'priority'          => MaintenancePriority::High->value,
            'description'       => $description,
        ], $by);
    }

    private static function defaultUnit(string $metric): string
    {
        return match ($metric) {
            'temperature' => 'celsius',
            'vibration'   => 'mm/s',
            'pressure'    => 'bar',
            'current'     => 'amp',
            'oil_quality' => 'percent',
            default       => 'unit',
        };
    }
}
