<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Middleware\SessionTimeout;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\WorkOrder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WorkOrderScheduleWarningTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_and_detail_warn_when_current_slot_ends_not_when_planning_deadline_ends(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->actingAs(User::factory()->withRole('production_manager')->create(['is_active' => true]));
        $this->travelTo(Carbon::parse('2026-10-07 06:00:00', config('app.timezone')));
        $wo = WorkOrder::factory()->create(['status' => 'confirmed', 'planned_end' => now()->addDays(3)]);
        $machine = Machine::factory()->create();
        $mold = $this->mold($wo);
        $expired = $wo->schedules()->create(['machine_id' => $machine->id, 'mold_id' => $mold->id, 'priority_order' => 0, 'scheduled_start' => now()->subHours(2),
            'scheduled_end' => now(), 'status' => 'confirmed', 'reserves_capacity' => true]);

        $url = '/api/v1/production/work-orders/'.$wo->hash_id;
        $this->getJson('/api/v1/production/work-orders?search='.$wo->wo_number)->assertOk()
            ->assertJsonPath('data.0.schedule_warning.message', 'Schedule passed. Click Start to check a late start or reschedule.')
            ->assertJsonPath('data.0.schedule_warning.scheduled_end', $expired->scheduled_end->toIso8601String());
        $this->getJson($url)->assertOk()->assertJsonPath('data.schedule_warning.message', 'Schedule passed. Click Start to check a late start or reschedule.');

        // A newer current slot clears the warning; superseded and released rows remain history.
        $expired->update(['status' => 'superseded', 'reserves_capacity' => false]);
        $wo->schedules()->create(['machine_id' => $machine->id, 'mold_id' => $mold->id, 'priority_order' => 0, 'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(2), 'status' => 'confirmed', 'reserves_capacity' => true]);
        $wo->schedules()->create(['machine_id' => $machine->id, 'mold_id' => $mold->id, 'priority_order' => 0, 'scheduled_start' => now()->subHours(4),
            'scheduled_end' => now()->subHours(3), 'status' => 'superseded', 'reserves_capacity' => false]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.schedule_warning', null);
        $this->getJson('/api/v1/production/work-orders?search='.$wo->wo_number)->assertOk()->assertJsonPath('data.0.schedule_warning', null);
    }

    public function test_warning_matches_lifecycle_and_does_not_treat_unscheduled_or_finished_orders_as_overdue(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->actingAs(User::factory()->withRole('production_manager')->create(['is_active' => true]));
        $this->travelTo(Carbon::parse('2026-10-07 06:00:00', config('app.timezone')));
        foreach (['planned', 'confirmed', 'in_progress', 'paused', 'completed', 'closed', 'cancelled'] as $status) {
            $wo = WorkOrder::factory()->create(['status' => $status]);
            $machine = Machine::factory()->create();
            $mold = $this->mold($wo);
            $wo->schedules()->create(['machine_id' => $machine->id, 'mold_id' => $mold->id, 'priority_order' => 0, 'scheduled_start' => now()->subHours(2),
                'scheduled_end' => now()->subHour(), 'status' => 'confirmed', 'reserves_capacity' => true]);
            $response = $this->getJson('/api/v1/production/work-orders?search='.$wo->wo_number)->assertOk();
            $message = match ($status) {
                'planned' => 'Proposal passed. Open Confirm to find a new slot.',
                'confirmed' => 'Schedule passed. Click Start to check a late start or reschedule.',
                'paused' => 'Schedule passed. Click Resume to check readiness or reschedule.',
                'in_progress' => 'Schedule passed. Review remaining production time.',
                default => null,
            };
            if ($message === null) {
                $response->assertJsonPath('data.0.schedule_warning', null);
            } else {
                $response->assertJsonPath('data.0.schedule_warning.message', $message);
            }
        }

        $unscheduled = WorkOrder::factory()->create(['status' => 'confirmed', 'planned_end' => now()->subDay()]);
        $this->getJson('/api/v1/production/work-orders?search='.$unscheduled->wo_number)->assertOk()->assertJsonPath('data.0.schedule_warning', null);
    }

    public function test_confirmed_start_status_observes_inclusive_fifteen_minute_grace_on_list_and_detail(): void
    {
        $this->withoutMiddleware(SessionTimeout::class);
        $this->seed(RolePermissionSeeder::class);
        $this->actingAs(User::factory()->withRole('production_manager')->create(['is_active' => true]));
        $this->travelTo(Carbon::parse('2026-10-07 08:00:00', config('app.timezone')));
        $wo = WorkOrder::factory()->create(['status' => 'confirmed']);
        $slot = $wo->schedules()->create(['machine_id' => Machine::factory()->create()->id,
            'mold_id' => $this->mold($wo)->id, 'priority_order' => 0, 'scheduled_start' => now(),
            'scheduled_end' => now()->addHours(2), 'status' => 'confirmed', 'reserves_capacity' => true]);
        foreach ([-1 => null, 0 => 'start_available', 899 => 'start_available', 900 => 'start_available',
            901 => 'start_overdue', 7200 => 'schedule_passed'] as $seconds => $kind) {
            $this->travelTo($slot->scheduled_start->copy()->addSeconds($seconds));
            foreach (['/api/v1/production/work-orders/'.$wo->hash_id => 'data',
                '/api/v1/production/work-orders?search='.$wo->wo_number => 'data.0'] as $url => $path) {
                $response = $this->getJson($url)->assertOk();
                if ($kind === null) {
                    $response->assertJsonPath($path.'.schedule_status', null)->assertJsonPath($path.'.schedule_warning', null);
                } else {
                    $response->assertJsonPath($path.'.schedule_status.kind', $kind)
                        ->assertJsonPath($path.'.schedule_status.scheduled_start', $slot->scheduled_start->toIso8601String());
                    if ($kind === 'start_available') {
                        $response->assertJsonPath($path.'.schedule_warning', null)
                            ->assertJsonPath($path.'.schedule_status.message', 'Start available. Click Start to check readiness.');
                    } else {
                        $this->assertSame($response->json($path.'.schedule_status'), $response->json($path.'.schedule_warning'));
                    }
                }
            }
        }
    }

    public function test_planned_proposal_is_awaiting_confirmation_until_expiry_and_terminal_states_are_quiet(): void
    {
        $this->withoutMiddleware(SessionTimeout::class);
        $this->seed(RolePermissionSeeder::class);
        $this->actingAs(User::factory()->withRole('production_manager')->create(['is_active' => true]));
        $this->travelTo(Carbon::parse('2026-10-07 08:00:00', config('app.timezone')));
        $wo = WorkOrder::factory()->create(['status' => 'planned']);
        $slot = $wo->schedules()->create(['machine_id' => Machine::factory()->create()->id,
            'mold_id' => $this->mold($wo)->id, 'priority_order' => 0, 'scheduled_start' => now()->subMinute(),
            'scheduled_end' => now()->addHours(2), 'status' => 'pending', 'reserves_capacity' => true]);
        foreach (['/api/v1/production/work-orders/'.$wo->hash_id => 'data',
            '/api/v1/production/work-orders?search='.$wo->wo_number => 'data.0'] as $url => $path) {
            $this->getJson($url)->assertOk()->assertJsonPath($path.'.schedule_status.kind', 'awaiting_confirmation')
                ->assertJsonPath($path.'.schedule_warning', null);
        }
        $this->travelTo($slot->scheduled_end);
        $url = '/api/v1/production/work-orders/'.$wo->hash_id;
        $this->getJson($url)->assertOk()->assertJsonPath('data.schedule_status.kind', 'proposal_expired')
            ->assertJsonPath('data.schedule_warning.message', 'Proposal passed. Open Confirm to find a new slot.');
        foreach (['completed', 'closed', 'cancelled'] as $status) {
            $wo->update(['status' => $status]);
            $this->getJson($url)->assertOk()->assertJsonPath('data.schedule_status', null)->assertJsonPath('data.schedule_warning', null);
        }
    }

    public function test_detail_keeps_production_due_separate_from_requested_and_current_delivery_dates(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->actingAs(User::factory()->withRole('production_manager')->create(['is_active' => true]));
        $line = SalesOrderItem::factory()->create(['requested_delivery_date' => '2026-10-13', 'delivery_date' => '2026-10-16']);
        $wo = WorkOrder::factory()->create(['sales_order_id' => $line->sales_order_id, 'sales_order_item_id' => $line->id,
            'product_id' => $line->product_id, 'planned_end' => '2026-10-15 00:00:00']);
        $this->getJson('/api/v1/production/work-orders/'.$wo->hash_id)->assertOk()
            ->assertJsonPath('data.sales_order_line.requested_delivery_date', '2026-10-13')
            ->assertJsonPath('data.sales_order_line.delivery_date', '2026-10-16')
            ->assertJsonPath('data.planned_end', $wo->planned_end->toIso8601String());
        $unlinked = WorkOrder::factory()->create();
        $this->getJson('/api/v1/production/work-orders/'.$unlinked->hash_id)->assertOk()
            ->assertJsonPath('data.sales_order_line', null);
    }

    private function mold(WorkOrder $wo): Mold
    {
        return Mold::create(['product_id' => $wo->product_id, 'mold_code' => 'WARNING-'.$wo->id,
            'name' => 'Schedule warning test mold', 'cavity_count' => 1, 'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100, 'max_shots_before_maintenance' => 10000, 'lifetime_max_shots' => 100000]);
    }
}
