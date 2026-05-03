<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Common\Services\DocumentSequenceService;
use App\Modules\Assets\Enums\AssetCategory;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ClearanceStatus;
use App\Modules\HR\Enums\SeparationReason;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\SeparationService;
use App\Modules\Maintenance\Enums\MaintainableType;
use App\Modules\Maintenance\Enums\MaintenancePriority;
use App\Modules\Maintenance\Enums\MaintenanceScheduleInterval;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderStatus;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderType;
use App\Modules\Maintenance\Models\MaintenanceLog;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 8 — Task 80 supplement.
 *
 * Adds maintenance schedules + work orders + asset register entries on top
 * of whatever the main DemoDataSeeder produces. Idempotent.
 */
class Sprint8DemoSeeder extends Seeder
{
    public function run(DocumentSequenceService $sequences): void
    {
        $admin = User::query()->orderBy('id')->first();
        if (! $admin) {
            $this->command?->warn('Sprint8DemoSeeder skipped: no users exist.');
            return;
        }

        $this->seedAssets();
        $this->seedMaintenanceSchedules();
        $this->seedMaintenanceWorkOrders($admin, $sequences);
        $this->seedSeparations($admin, $sequences);
        $this->seedNotifications();
        $this->command?->info('Sprint 8 demo data seeded.');
    }

    /* ─── Assets ─── */

    private function seedAssets(): void
    {
        if (Asset::count() > 0) return;

        // Create one asset row per machine + mold + vehicle
        $today = Carbon::today();
        $machines = Machine::all();
        $molds    = Mold::all();
        $vehicles = DB::table('vehicles')->get();

        foreach ($machines as $machine) {
            $asset = Asset::create([
                'asset_code'        => 'AST-MCH-'.str_pad((string) $machine->id, 4, '0', STR_PAD_LEFT),
                'name'              => $machine->name.' ('.$machine->machine_code.')',
                'description'       => 'Injection molding machine',
                'category'          => AssetCategory::Machine->value,
                'acquisition_date'  => $today->copy()->subYears(rand(1, 8)),
                'acquisition_cost'  => rand(2_000_000, 8_000_000),
                'useful_life_years' => 10,
                'salvage_value'     => 100_000,
                'status'            => AssetStatus::Active->value,
                'location'          => 'Production Floor',
            ]);
            DB::table('machines')->where('id', $machine->id)->update(['asset_id' => $asset->id]);
        }
        foreach ($molds as $mold) {
            $asset = Asset::create([
                'asset_code'        => 'AST-MLD-'.str_pad((string) $mold->id, 4, '0', STR_PAD_LEFT),
                'name'              => $mold->name.' ('.$mold->mold_code.')',
                'description'       => 'Injection mold',
                'category'          => AssetCategory::Mold->value,
                'acquisition_date'  => $today->copy()->subYears(rand(1, 5)),
                'acquisition_cost'  => rand(300_000, 1_500_000),
                'useful_life_years' => 5,
                'salvage_value'     => 25_000,
                'status'            => AssetStatus::Active->value,
                'location'          => 'Mold Storage Bay',
            ]);
            DB::table('molds')->where('id', $mold->id)->update(['asset_id' => $asset->id]);
        }
        foreach ($vehicles as $v) {
            $asset = Asset::create([
                'asset_code'        => 'AST-VEH-'.str_pad((string) $v->id, 4, '0', STR_PAD_LEFT),
                'name'              => $v->name.' ('.$v->plate_number.')',
                'description'       => 'Delivery vehicle',
                'category'          => AssetCategory::Vehicle->value,
                'acquisition_date'  => $today->copy()->subYears(rand(2, 6)),
                'acquisition_cost'  => rand(800_000, 1_800_000),
                'useful_life_years' => 7,
                'salvage_value'     => 50_000,
                'status'            => AssetStatus::Active->value,
                'location'          => 'Vehicle Yard',
            ]);
            DB::table('vehicles')->where('id', $v->id)->update(['asset_id' => $asset->id]);
        }
    }

    /* ─── Maintenance schedules ─── */

    private function seedMaintenanceSchedules(): void
    {
        if (MaintenanceSchedule::count() > 0) return;

        $machines = Machine::limit(6)->get();
        $molds    = Mold::limit(4)->get();

        foreach ($machines as $i => $machine) {
            MaintenanceSchedule::create([
                'maintainable_type' => MaintainableType::Machine->value,
                'maintainable_id'   => $machine->id,
                'description'       => 'Quarterly preventive maintenance — '.$machine->machine_code,
                'interval_type'     => MaintenanceScheduleInterval::Days->value,
                'interval_value'    => 90,
                'last_performed_at' => now()->subDays(rand(20, 80)),
                'next_due_at'       => now()->addDays(rand(5, 80)),
                'is_active'         => true,
            ]);
        }
        foreach ($molds as $mold) {
            MaintenanceSchedule::create([
                'maintainable_type' => MaintainableType::Mold->value,
                'maintainable_id'   => $mold->id,
                'description'       => 'Shot-count refurbishment — '.$mold->mold_code,
                'interval_type'     => MaintenanceScheduleInterval::Shots->value,
                'interval_value'    => max(50_000, (int) ($mold->max_shots_before_maintenance ?? 100_000)),
                'last_performed_at' => now()->subMonths(rand(1, 4)),
                'is_active'         => true,
            ]);
        }
    }

    /* ─── Separations + clearances ─── */

    private function seedSeparations(User $admin, DocumentSequenceService $sequences): void
    {
        if (Clearance::count() > 0) return;

        // Pick three employees to put through different states.
        $employees = Employee::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->limit(3)
            ->get();

        if ($employees->isEmpty()) return;

        // Reuse the SeparationService default checklist for shape consistency.
        $checklist = SeparationService::defaultChecklist();
        $now = now();

        // 1) In-progress clearance — 4 of 11 items signed.
        $emp1 = $employees->get(0);
        if ($emp1) {
            $items = $this->buildItems($checklist, $admin->id, partial: true, signedCount: 4);
            Clearance::create([
                'clearance_no'      => $sequences->generate('clearance'),
                'employee_id'       => $emp1->id,
                'separation_date'   => $now->copy()->addDays(14)->toDateString(),
                'separation_reason' => SeparationReason::Resigned->value,
                'clearance_items'   => $items,
                'status'            => ClearanceStatus::InProgress->value,
                'initiated_by'      => $admin->id,
            ]);
        }

        // 2) Completed clearance — all items signed, final pay not yet computed.
        $emp2 = $employees->get(1);
        if ($emp2) {
            $items = $this->buildItems($checklist, $admin->id, partial: false);
            Clearance::create([
                'clearance_no'      => $sequences->generate('clearance'),
                'employee_id'       => $emp2->id,
                'separation_date'   => $now->copy()->subDays(7)->toDateString(),
                'separation_reason' => SeparationReason::Retired->value,
                'clearance_items'   => $items,
                'status'            => ClearanceStatus::Completed->value,
                'initiated_by'      => $admin->id,
            ]);
        }

        // 3) Pending — no items signed yet.
        $emp3 = $employees->get(2);
        if ($emp3) {
            $items = $this->buildItems($checklist, $admin->id, partial: true, signedCount: 0);
            Clearance::create([
                'clearance_no'      => $sequences->generate('clearance'),
                'employee_id'       => $emp3->id,
                'separation_date'   => $now->copy()->addDays(30)->toDateString(),
                'separation_reason' => SeparationReason::EndOfContract->value,
                'clearance_items'   => $items,
                'status'            => ClearanceStatus::Pending->value,
                'initiated_by'      => $admin->id,
            ]);
        }
    }

    /**
     * @param array<int, array<string, string>> $checklist
     * @return array<int, array<string, mixed>>
     */
    private function buildItems(array $checklist, int $signedBy, bool $partial, int $signedCount = -1): array
    {
        $now = now()->toIso8601String();
        $rows = [];
        foreach ($checklist as $i => $row) {
            $cleared = ! $partial || ($signedCount >= 0 && $i < $signedCount);
            $rows[] = [
                'department' => $row['department'],
                'item_key'   => $row['item_key'],
                'label'      => $row['label'],
                'status'     => $cleared ? 'cleared' : 'pending',
                'signed_by'  => $cleared ? $signedBy : null,
                'signed_at'  => $cleared ? $now : null,
                'remarks'    => null,
            ];
        }
        return $rows;
    }

    /* ─── Notifications ─── */

    private function seedNotifications(): void
    {
        // Skip if already populated.
        if (DB::table('notifications')->count() > 0) return;

        $users = User::query()->orderBy('id')->limit(5)->get();
        if ($users->isEmpty()) return;

        $now = now();
        $samples = [
            ['type' => 'leave.submitted',   'data' => ['message' => 'Leave request LR-202604-0010 awaiting your approval.']],
            ['type' => 'pr.urgent',         'data' => ['message' => 'Urgent PR PR-202604-0008 due to low spare-part stock.']],
            ['type' => 'wo.completed',      'data' => ['message' => 'Work order WO-202604-0006 completed (10,000 good / 45 reject).']],
            ['type' => 'machine.breakdown', 'data' => ['message' => 'IMM-04 entered breakdown. WO paused.']],
            ['type' => 'maintenance.assigned', 'data' => ['message' => 'You were assigned MWO-202604-0001.']],
            ['type' => 'ncr.opened',        'data' => ['message' => 'NCR-202604-0003 opened from outgoing inspection failure.']],
            ['type' => 'mold.shot_limit',   'data' => ['message' => 'Mold MOLD-08 reached 82% of shot threshold.']],
            ['type' => 'payroll.finalized', 'data' => ['message' => 'Payroll period 2026-04 first half finalized.']],
        ];

        foreach ($users as $u) {
            foreach ($samples as $i => $s) {
                DB::table('notifications')->insert([
                    'id'              => (string) \Illuminate\Support\Str::uuid(),
                    'type'            => $s['type'],
                    'notifiable_type' => User::class,
                    'notifiable_id'   => $u->id,
                    'data'            => json_encode($s['data']),
                    'read_at'         => $i % 3 === 0 ? null : $now->copy()->subHours($i + 1),
                    'created_at'      => $now->copy()->subHours($i + 1),
                    'updated_at'      => $now->copy()->subHours($i + 1),
                ]);
            }
        }
    }

    /* ─── Maintenance work orders ─── */

    private function seedMaintenanceWorkOrders(User $admin, DocumentSequenceService $sequences): void
    {
        if (MaintenanceWorkOrder::count() > 0) return;

        $tech = Employee::query()
            ->whereHas('department', fn ($q) => $q->where('code', 'MAINT')->orWhere('name', 'ilike', '%maintenance%'))
            ->first();

        $schedules = MaintenanceSchedule::query()->limit(3)->get();
        $now = now();

        foreach ($schedules as $i => $schedule) {
            $isCompleted = $i === 0;
            $wo = MaintenanceWorkOrder::create([
                'mwo_number'        => $sequences->generate('maintenance_wo'),
                'maintainable_type' => $schedule->maintainable_type instanceof \BackedEnum ? $schedule->maintainable_type->value : $schedule->maintainable_type,
                'maintainable_id'   => $schedule->maintainable_id,
                'schedule_id'       => $schedule->id,
                'type'              => MaintenanceWorkOrderType::Preventive->value,
                'priority'          => MaintenancePriority::Medium->value,
                'description'       => $schedule->description,
                'assigned_to'       => $tech?->id,
                'status'            => $isCompleted ? MaintenanceWorkOrderStatus::Completed->value : MaintenanceWorkOrderStatus::InProgress->value,
                'started_at'        => $now->copy()->subDays($i + 1),
                'completed_at'      => $isCompleted ? $now->copy()->subDays($i)->addHours(3) : null,
                'downtime_minutes'  => $isCompleted ? 180 : 0,
                'cost'              => 0,
                'created_by'        => $admin->id,
            ]);
            MaintenanceLog::create([
                'work_order_id' => $wo->id,
                'description'   => 'Auto-seeded sample maintenance log entry.',
                'logged_by'     => $admin->id,
                'created_at'    => $now,
            ]);
        }

        // One corrective WO without schedule
        $machine = Machine::first();
        if ($machine) {
            MaintenanceWorkOrder::create([
                'mwo_number'        => $sequences->generate('maintenance_wo'),
                'maintainable_type' => MaintainableType::Machine->value,
                'maintainable_id'   => $machine->id,
                'type'              => MaintenanceWorkOrderType::Corrective->value,
                'priority'          => MaintenancePriority::High->value,
                'description'       => 'Hydraulic pressure dropped during 2nd shift; investigate and repair.',
                'assigned_to'       => $tech?->id,
                'status'            => MaintenanceWorkOrderStatus::Open->value,
                'created_by'        => $admin->id,
            ]);
        }
    }
}
