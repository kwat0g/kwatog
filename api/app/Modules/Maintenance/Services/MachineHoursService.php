<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Services;

use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Support\Carbon;

/** Recompute machine runtime from production intervals minus linked downtime. */
class MachineHoursService
{
    public function recompute(): int
    {
        $count = 0;
        Machine::query()->orderBy('id')->chunk(50, function ($machines) use (&$count): void {
            foreach ($machines as $machine) {
                $hours = $this->computeForMachine((int) $machine->id);
                $machine->forceFill([
                    'running_hours_total' => round($hours, 2),
                    'running_hours_updated_at' => now(),
                ])->save();
                $count++;
            }
        });

        return $count;
    }

    private function computeForMachine(int $machineId): float
    {
        $machine = Machine::query()->find($machineId);
        if (! $machine) {
            return 0.0;
        }

        $orders = WorkOrder::query()
            ->where('machine_id', $machineId)
            ->whereNotNull('actual_start')
            ->get(['id', 'actual_start', 'actual_end', 'status']);
        $downtimes = MachineDowntime::query()
            ->where('machine_id', $machineId)
            ->whereNotNull('work_order_id')
            ->orderBy('start_time')
            ->get(['work_order_id', 'start_time', 'end_time'])
            ->groupBy('work_order_id');

        $now = now();
        $nowTimestamp = $now->getTimestamp();
        $runningIntervals = [];

        foreach ($orders as $order) {
            $start = Carbon::parse($order->actual_start)->getTimestamp();
            $end = $order->actual_end !== null
                ? min(Carbon::parse($order->actual_end)->getTimestamp(), $nowTimestamp)
                : null;
            $orderDowntime = $downtimes->get($order->id, collect());

            if ($end === null) {
                $openDowntime = $orderDowntime->first(static fn (MachineDowntime $row): bool => $row->end_time === null);
                if ($openDowntime) {
                    $end = Carbon::parse($openDowntime->start_time)->getTimestamp();
                } elseif ($order->status === WorkOrderStatus::InProgress
                    && (int) $machine->current_work_order_id === (int) $order->id
                    && $machine->status === MachineStatus::Running) {
                    $end = $nowTimestamp;
                } else {
                    // An open WO without current machine ownership is stale; it
                    // must not accrue runtime indefinitely.
                    continue;
                }
            }

            if ($end <= $start) {
                continue;
            }

            $blocked = [];
            foreach ($orderDowntime as $row) {
                $downtimeStart = max($start, Carbon::parse($row->start_time)->getTimestamp());
                $downtimeEnd = min(
                    $end,
                    $row->end_time !== null
                        ? Carbon::parse($row->end_time)->getTimestamp()
                        : $nowTimestamp,
                );
                if ($downtimeEnd > $downtimeStart) {
                    $blocked[] = [$downtimeStart, $downtimeEnd];
                }
            }

            $cursor = $start;
            foreach ($this->mergeIntervals($blocked) as [$blockedStart, $blockedEnd]) {
                if ($blockedStart > $cursor) {
                    $runningIntervals[] = [$cursor, $blockedStart];
                }
                $cursor = max($cursor, $blockedEnd);
            }
            if ($cursor < $end) {
                $runningIntervals[] = [$cursor, $end];
            }
        }

        $seconds = array_sum(array_map(
            static fn (array $interval): int => $interval[1] - $interval[0],
            $this->mergeIntervals($runningIntervals),
        ));

        return $seconds / 3600;
    }

    /** @param array<int, array{0:int,1:int}> $intervals
     *  @return array<int, array{0:int,1:int}> */
    private function mergeIntervals(array $intervals): array
    {
        if ($intervals === []) {
            return [];
        }

        usort($intervals, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
        $merged = [];
        foreach ($intervals as [$start, $end]) {
            $last = array_key_last($merged);
            if ($last === null || $start > $merged[$last][1]) {
                $merged[] = [$start, $end];
            } else {
                $merged[$last][1] = max($merged[$last][1], $end);
            }
        }

        return $merged;
    }
}
