<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Auth\Models\User;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\HasAssignedProductionCrew;
use Tests\TestCase;

class WorkOrderScheduleReminderTest extends TestCase
{
    use HasAssignedProductionCrew;
    use RefreshDatabase;

    private WorkOrder $wo;

    private ProductionSchedule $slot;

    private User $manager;

    private User $ppc;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-06 08:00:00', config('app.timezone')));
        $this->manager = User::factory()->withRole('production_manager')->create(['is_active' => true]);
        $this->ppc = User::factory()->withRole('ppc_head')->create(['is_active' => true]);
        $this->wo = WorkOrder::factory()->create(['status' => 'confirmed', 'machine_id' => Machine::factory()->create()->id,  'planned_start' => now()->addMinutes(20), 'planned_end' => now()->addHours(3)]);
        $this->wo->update(['mold_id' => Mold::create(['product_id' => $this->wo->product_id, 'mold_code' => 'REMINDER-MOLD', 'name' => 'Reminder regression mold',
            'cavity_count' => 1, 'cycle_time_seconds' => 30, 'output_rate_per_hour' => 100, 'setup_time_minutes' => 10,
            'current_shot_count' => 0, 'max_shots_before_maintenance' => 10000, 'lifetime_max_shots' => 100000, 'status' => 'available'])->id]);
        $this->slot = ProductionSchedule::create(['work_order_id' => $this->wo->id, 'machine_id' => $this->wo->machine_id, 'mold_id' => $this->wo->mold_id, 'priority_order' => 0,
            'scheduled_start' => now()->addMinutes(20), 'scheduled_end' => now()->addHours(2),
            'status' => 'confirmed', 'is_confirmed' => true, 'reserves_capacity' => true]);
        $this->worker = User::factory()->withRole('employee')->create(['is_active' => true]);
        $this->assignProductionCrew($this->wo, $this->worker);
    }

    public function test_notifies_current_crew_and_owners_once_per_stage_with_role_appropriate_links(): void
    {
        User::factory()->withRole('sales_officer')->create(['is_active' => true]);
        User::factory()->withRole('production_manager')->create(['is_active' => false]);
        foreach ([now(), $this->slot->scheduled_start, $this->slot->scheduled_start->copy()->addMinutes(15)->addSecond()] as $clock) {
            $this->travelTo($clock);
            $this->artisan('production:notify-upcoming-work-orders')->assertSuccessful();
            $this->artisan('production:notify-upcoming-work-orders')->assertSuccessful();
        }
        $this->assertSame(9, DB::table('notifications')->where('type', 'production.schedule.reminder')->count());
        foreach ([$this->worker, $this->manager, $this->ppc] as $recipient) {
            $rows = DB::table('notifications')->where('notifiable_id', $recipient->id)->where('type', 'production.schedule.reminder')->get();
            $this->assertCount(3, $rows);
            $data = json_decode($rows->last()->data, true);
            $prefix = $recipient->id === $this->worker->id ? '/self-service/work-orders/' : '/production/work-orders/';
            $this->assertSame($prefix.$this->wo->hash_id, $data['link_to']);
            $this->assertStringContainsString($recipient->id === $this->worker->id ? 'Contact Production management' : 'Reschedule', $data['message']);
        }
        $this->assertNull($this->wo->fresh()->actual_start);
        $this->assertSame('confirmed', $this->wo->fresh()->status->value);
    }

    public function test_due_reminder_stays_due_through_grace_and_missed_reminder_is_deduplicated(): void
    {
        foreach ([0, 5 * 60, 15 * 60] as $seconds) {
            $this->travelTo($this->slot->scheduled_start->copy()->addSeconds($seconds));
            $this->artisan('production:notify-upcoming-work-orders')->assertSuccessful();
            $this->assertSame(3, DB::table('notifications')->where('type', 'production.schedule.reminder')->count());
        }
        $this->travelTo($this->slot->scheduled_start->copy()->addMinutes(15)->addSecond());
        $this->artisan('production:notify-upcoming-work-orders')->assertSuccessful();
        $this->artisan('production:notify-upcoming-work-orders')->assertSuccessful();
        $this->assertSame(6, DB::table('notifications')->where('type', 'production.schedule.reminder')->count());
        $this->assertNull($this->wo->fresh()->actual_start);
    }

    public function test_ignores_unapproved_released_cancelled_and_started_orders(): void
    {
        $this->slot->update(['is_confirmed' => false]);
        $this->artisan('production:notify-upcoming-work-orders')->assertSuccessful();
        $this->slot->update(['is_confirmed' => true, 'reserves_capacity' => false]);
        $this->artisan('production:notify-upcoming-work-orders')->assertSuccessful();
        $this->slot->update(['reserves_capacity' => true]);
        foreach (['cancelled', 'in_progress', 'completed', 'closed'] as $status) {
            $this->wo->update(['status' => $status]);
            $this->artisan('production:notify-upcoming-work-orders')->assertSuccessful();
        }
        $this->assertSame(0, DB::table('notifications')->where('type', 'production.schedule.reminder')->count());
    }

    public function test_rescheduling_uses_only_latest_reservation_and_new_crew_without_duplicate_old_reminders(): void
    {
        $this->slot->update(['reserves_capacity' => false]);
        $replacement = ProductionSchedule::create(['work_order_id' => $this->wo->id, 'machine_id' => $this->wo->machine_id, 'mold_id' => $this->wo->mold_id, 'priority_order' => 0,
            'scheduled_start' => now()->addHours(3), 'scheduled_end' => now()->addHours(5),
            'status' => 'confirmed', 'is_confirmed' => true, 'reserves_capacity' => true]);
        $this->artisan('production:notify-upcoming-work-orders')->assertSuccessful();
        $this->assertSame(0, DB::table('notifications')->where('type', 'production.schedule.reminder')->count());
        $this->travelTo($replacement->scheduled_start->copy()->subMinutes(10));
        $this->wo->crews()->update(['ended_at' => now()]);
        $newWorker = User::factory()->withRole('employee')->create(['is_active' => true]);
        $this->assignProductionCrew($this->wo, $newWorker);
        $this->artisan('production:notify-upcoming-work-orders')->assertSuccessful();
        $this->assertSame(3, DB::table('notifications')->where('type', 'production.schedule.reminder')->count());
        $this->assertDatabaseMissing('notifications', ['type' => 'production.schedule.reminder', 'notifiable_id' => $this->worker->id]);
        $data = json_decode(DB::table('notifications')->where('type', 'production.schedule.reminder')->where('notifiable_id', $newWorker->id)->value('data'), true);
        $this->assertStringContainsString('11:00 AM', $data['message']);
    }

    public function test_changed_attendance_alerts_only_management_without_reassigning_crew_or_marking_missing_punch_absent(): void
    {
        $crew = app(WorkOrderAssignmentService::class)->currentCrew($this->wo);
        $attendance = Attendance::where('employee_id', $this->worker->employee_id)->firstOrFail();
        $attendance->forceDelete();
        $this->artisan('production:notify-upcoming-work-orders')->assertSuccessful();
        $this->assertSame(0, DB::table('notifications')->where('type', 'production.assignment.review')->count());
        Attendance::create(['employee_id' => $this->worker->employee_id, 'date' => $this->slot->scheduled_start->toDateString(),
            'shift_id' => $attendance->shift_id, 'status' => 'absent']);
        $this->artisan('production:notify-upcoming-work-orders')->assertSuccessful();
        $this->artisan('production:notify-upcoming-work-orders')->assertSuccessful();
        $this->assertSame(2, DB::table('notifications')->where('type', 'production.assignment.review')->count());
        $this->assertDatabaseMissing('notifications', ['type' => 'production.assignment.review', 'notifiable_id' => $this->worker->id]);
        foreach ([$this->manager, $this->ppc] as $owner) {
            $this->assertDatabaseHas('notifications', ['type' => 'production.assignment.review', 'notifiable_id' => $owner->id]);
        }
        $this->assertNull($crew->fresh()->ended_at);
        $this->assertSame(1, $this->wo->crews()->count());
        $this->assertSame('confirmed', $this->wo->fresh()->status->value);
    }
}
