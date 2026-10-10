<?php

declare(strict_types=1);

$root = is_dir(__DIR__.'/../api') ? __DIR__.'/../api' : __DIR__.'/..';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\MRP\Models\Mold;
use App\Modules\HR\Models\Employee;

$report = [
    'confirmed_without_crew' => WorkOrder::where('status', 'confirmed')->whereDoesntHave('crews', fn ($query) => $query->whereNull('ended_at')->whereNull('rework_ncr_id'))->get(['id', 'wo_number'])->toArray(),
    'missing_working_days' => \App\Modules\Attendance\Models\EmployeeShiftAssignment::whereNull('working_days')->whereHas('employee', fn ($query) => $query->where('status', 'active')->whereHas('department', fn ($department) => $department->where('code', 'PROD')))->get(['id','employee_id','shift_id','effective_date','end_date'])->toArray(),
    'invalid_commitments' => ProductionSchedule::where('reserves_capacity', true)->with('workOrder')->get()->filter(fn ($slot) => $slot->workOrder !== null)->map(fn ($slot) => ['wo_number' => $slot->workOrder->wo_number, 'readiness' => app(\App\Modules\MRP\Services\CapacityPlanningService::class)->readiness($slot)])->filter(fn ($row) => count($row['readiness']['blockers']) > 0 || count($row['readiness']['execution_blockers']) > 0)->values()->all(),
    'active_without_schedule' => WorkOrder::whereIn('status', ['planned', 'confirmed', 'in_progress', 'paused'])->whereDoesntHave('schedules', fn ($q) => $q->where('reserves_capacity', true))->get(['id','wo_number','status'])->toArray(),
    'terminal_reservations' => ProductionSchedule::where('reserves_capacity', true)->whereNull('rework_ncr_id')->whereHas('workOrder', fn ($q) => $q->whereIn('status',['completed','closed','cancelled']))->get(['id','work_order_id'])->toArray(),
    'expired_active_reservations' => ProductionSchedule::where('reserves_capacity', true)->where('scheduled_end','<',now())->whereHas('workOrder',fn ($q)=>$q->whereIn('status',['in_progress','paused']))->get(['id','work_order_id','scheduled_end'])->toArray(),
    'installed_molds' => Mold::whereNotNull('current_work_order_id')->get(['id','mold_code','current_work_order_id','current_rework_ncr_id'])->toArray(),
    'active_production_workers' => Employee::where('status','active')->whereHas('department',fn ($q)=>$q->where('code','PROD')->where('is_active',true))->count(),
    'active_repairs' => \App\Modules\Quality\Models\NonConformanceReport::whereNotNull('rework_started_at')->whereNull('rework_finished_at')->get(['id','rework_work_order_id','rework_machine_id','rework_mold_id'])->toArray(),
    'counter_policy' => 'Conservative pieces-as-cycle-estimate; historical units require company review before conversion.',
];
echo json_encode($report, JSON_PRETTY_PRINT).PHP_EOL;
