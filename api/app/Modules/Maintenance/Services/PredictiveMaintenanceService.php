<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
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
 */
class PredictiveMaintenanceService
{
    private const METRICS = ['temperature', 'vibration', 'pressure', 'current', 'oil_quality'];

    /** @var array{thresholds: array<string, array{min:?float,max:?float}>, breach_window:int}|null */
    private ?array $configuration = null;

    public function __construct(
        private readonly MaintenanceWorkOrderService $workOrders,
        private readonly SettingsService $settings,
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
            foreach (self::METRICS as $metric) {
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
        $configuration = $this->configuration();
        foreach (self::METRICS as $metric) {
            $threshold = $configuration['thresholds'][$metric];
            $r = $this->latestReading($machineId, $metric);
            if (! $r) {
                $out[] = [
                    'metric'       => $metric,
                    'value'        => null,
                    'unit'         => self::defaultUnit($metric),
                    'recorded_at'  => null,
                    'status'       => 'ok',
                    'min_threshold'=> $threshold['min'],
                    'max_threshold'=> $threshold['max'],
                    'breach_window'=> $configuration['breach_window'],
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
                'status'       => $breach && $consecutive >= $configuration['breach_window'] ? 'critical' : ($breach ? 'warning' : 'ok'),
                'min_threshold'=> $threshold['min'],
                'max_threshold'=> $threshold['max'],
                'breach_window'=> $configuration['breach_window'],
            ];
        }
        return $out;
    }

    private function isBreach(string $metric, float $value): bool
    {
        $cfg = $this->configuration()['thresholds'][$metric] ?? null;
        if (! $cfg) return false;
        if ($cfg['max'] !== null && $value > $cfg['max']) return true;
        if ($cfg['min'] !== null && $value < $cfg['min']) return true;
        return false;
    }

    private function shouldTriggerWorkOrder(int $machineId, string $metric): bool
    {
        return $this->consecutiveBreachCount($machineId, $metric) >= $this->configuration()['breach_window']
            && ! $this->hasOpenCorrectiveWoForMachine($machineId, $metric);
    }

    private function consecutiveBreachCount(int $machineId, string $metric): int
    {
        $recent = MachineConditionReading::query()
            ->where('machine_id', $machineId)
            ->where('metric', $metric)
            ->orderByDesc('recorded_at')
            ->limit($this->configuration()['breach_window'] * 2)
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

    /** @return array{thresholds: array<string, array{min:?float,max:?float}>, breach_window:int} */
    private function configuration(): array
    {
        if ($this->configuration !== null) {
            return $this->configuration;
        }

        $values = $this->settings->getGroup('maintenance');
        $thresholds = [];
        foreach (self::METRICS as $metric) {
            $minKey = "maintenance.predictive.{$metric}.min";
            $maxKey = "maintenance.predictive.{$metric}.max";
            $min = array_key_exists($minKey, $values) ? (float) $values[$minKey] : null;
            $max = array_key_exists($maxKey, $values) ? (float) $values[$maxKey] : null;
            if ($min === null && $max === null) {
                throw new BusinessRuleException("No predictive-maintenance threshold is configured for {$metric}.");
            }
            if ($min !== null && $max !== null && $min >= $max) {
                throw new BusinessRuleException("Predictive-maintenance thresholds for {$metric} are invalid.");
            }
            $thresholds[$metric] = ['min' => $min, 'max' => $max];
        }

        $window = (int) ($values['maintenance.predictive.breach_window'] ?? 0);
        if ($window < 1) {
            throw new BusinessRuleException('Predictive-maintenance breach window must be at least one.');
        }

        return $this->configuration = ['thresholds' => $thresholds, 'breach_window' => $window];
    }
}
