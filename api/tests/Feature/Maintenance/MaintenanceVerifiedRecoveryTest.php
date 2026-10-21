<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Models\SparePartUsage;
use App\Modules\Maintenance\Services\SparePartUsageService;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\MachineDowntime;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MaintenanceVerifiedRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_submission_requires_the_latest_structured_work_result_to_pass(): void
    {
        $technician = $this->actor('maintenance_tech');
        $order = $this->order($technician, true);
        $url = "/api/v1/maintenance/work-orders/{$order->hash_id}";
        $evidence = [
            'diagnosis' => 'Loose connection',
            'repair_action' => 'Reconnected wiring',
            'functional_test_result' => 'Machine completed a test cycle.',
        ];

        $this->actingAs($technician)->postJson($url.'/submit-repair', $evidence)
            ->assertUnprocessable()->assertJsonValidationErrors('work_record');
        $this->postJson($url.'/work-records', [
            'finding' => 'Machine ran', 'action_taken' => 'Tested under load', 'result' => 'passed',
        ])->assertCreated();
        $this->postJson($url.'/work-records', [
            'finding' => 'Connection loosened again', 'action_taken' => 'Investigating', 'result' => 'needs_follow_up',
        ])->assertCreated();
        $this->postJson($url.'/submit-repair', $evidence)
            ->assertUnprocessable()->assertJsonValidationErrors('work_record');
        $this->postJson($url.'/work-records', [
            'finding' => 'Connection held', 'action_taken' => 'Retested under load', 'result' => 'passed',
        ])->assertCreated();
        $this->postJson($url.'/logs', ['description' => 'Evidence ready for review.'])->assertCreated();
        $this->postJson($url.'/submit-repair', $evidence)->assertOk();
    }

    public function test_verified_order_requires_technician_evidence_and_independent_acceptance(): void
    {
        $technician = $this->actor('maintenance_tech');
        $manager = $this->actor('production_manager');
        $order = $this->order($technician, true);
        $url = "/api/v1/maintenance/work-orders/{$order->hash_id}";

        $this->actingAs($technician)->patchJson($url.'/complete')->assertUnprocessable();
        $this->recordPass($order, $technician);
        $this->actingAs($technician)->postJson($url.'/submit-repair', [
            'diagnosis' => 'Drive belt snapped during production.',
            'repair_action' => 'Replaced belt and checked alignment.',
            'functional_test_result' => 'Ran unloaded for ten minutes without vibration.',
        ])->assertOk();
        $this->assertNotNull($order->fresh()->repair_submitted_at);
        $detail = $this->actingAs($manager)->getJson($url)->assertOk()
            ->assertJsonPath('data.repair_evidence.diagnosis', 'Drive belt snapped during production.')
            ->assertJsonPath('data.response_minutes', 10)
            ->assertJsonPath('data.technician_repair_minutes', 30)
            ->json('data');
        $this->assertNotNull($detail['open_downtime_started_at']);
        $this->assertNotNull($detail['server_time']);
        $this->assertContains('verify_repair', $detail['available_actions']);
        $this->actingAs($technician)->postJson($url.'/logs', ['description' => 'Changed evidence'])->assertUnprocessable();
        $this->actingAs($technician)->postJson($url.'/verify', ['decision' => 'accept'])->assertForbidden();
        $this->actingAs($manager)->postJson($url.'/verify', ['decision' => 'accept'])->assertOk();

        $this->assertSame('completed', $order->fresh()->status->value);
        $this->assertSame($manager->id, $order->fresh()->verified_by);
        $this->assertNotNull($order->fresh()->verified_at);
        $this->assertNotNull(MachineDowntime::where('maintenance_order_id', $order->id)->firstOrFail()->end_time);
        $this->assertSame('idle', $order->maintainable()->fresh()->status->value);
        $this->actingAs($manager)->getJson($url)->assertOk()
            ->assertJsonPath('data.downtime_minutes', 30)
            ->assertJsonPath('data.verifier.name', $manager->name);
        $logs = $order->logs()->count();
        $this->actingAs($manager)->postJson($url.'/verify', ['decision' => 'accept'])->assertOk();
        $this->assertSame($logs, $order->logs()->count());
    }

    public function test_invalid_historical_timestamps_do_not_show_negative_recovery_metrics(): void
    {
        $technician = $this->actor('maintenance_tech');
        $order = $this->order($technician, false);
        $order->forceFill([
            'created_at' => now(),
            'started_at' => now()->subDays(2),
            'repair_submitted_at' => now()->subDays(3),
        ])->save();

        $this->actingAs($technician)
            ->getJson("/api/v1/maintenance/work-orders/{$order->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.response_minutes', null)
            ->assertJsonPath('data.technician_repair_minutes', null);
    }

    public function test_rejection_keeps_downtime_open_and_allows_resubmission(): void
    {
        $technician = $this->actor('maintenance_tech');
        $manager = $this->actor('production_manager');
        $order = $this->order($technician, true);
        $url = "/api/v1/maintenance/work-orders/{$order->hash_id}";
        $evidence = [
            'diagnosis' => 'Thermal overload tripped.',
            'repair_action' => 'Cleaned motor cooling path.',
            'functional_test_result' => 'Load trial pending verifier review.',
        ];

        $this->recordPass($order, $technician);
        $this->actingAs($technician)->postJson($url.'/submit-repair', $evidence)->assertOk();
        $this->actingAs($manager)->postJson($url.'/verify', ['decision' => 'reject', 'reason' => 'Load test not documented.'])->assertOk();
        $this->assertNull($order->fresh()->repair_submitted_at);
        $this->assertSame('in_progress', $order->fresh()->status->value);
        $this->assertNull(MachineDowntime::where('maintenance_order_id', $order->id)->firstOrFail()->end_time);
        $this->actingAs($technician)->postJson($url.'/submit-repair', array_replace($evidence,
            ['functional_test_result' => 'Loaded for ten minutes at rated speed.']))
            ->assertUnprocessable()->assertJsonValidationErrors('work_record');
        $this->recordPass($order, $technician);
        $this->actingAs($technician)->postJson($url.'/submit-repair', array_replace($evidence,
            ['functional_test_result' => 'Loaded for ten minutes at rated speed.']))->assertOk();
        $this->actingAs($manager)->postJson($url.'/verify', ['decision' => 'accept'])->assertOk();
        $this->assertSame('completed', $order->fresh()->status->value);
        $this->assertGreaterThanOrEqual(4, $order->logs()->count());
    }

    public function test_repair_rejection_links_employee_assignee_to_self_service_order(): void
    {
        $technician = $this->actor('employee');
        $manager = $this->actor('production_manager');
        $order = $this->order($technician, true);
        $url = "/api/v1/maintenance/work-orders/{$order->hash_id}";
        $this->recordPass($order, $technician);
        $otherEmployee = $this->actor('employee');
        $this->actingAs($otherEmployee)->postJson($url.'/submit-repair', [
            'diagnosis' => 'Not assigned.',
            'repair_action' => 'Attempted unrelated update.',
            'functional_test_result' => 'Not applicable.',
        ])->assertForbidden();
        $submitResponse = $this->actingAs($technician)->postJson($url.'/submit-repair', [
            'diagnosis' => 'Loose connection.',
            'repair_action' => 'Reconnected wiring.',
            'functional_test_result' => 'Load test passed.',
        ]);
        $submitResponse->assertOk($submitResponse->getContent());

        $this->actingAs($manager)->postJson($url.'/verify', [
            'decision' => 'reject',
            'reason' => 'Document the second load test.',
        ])->assertOk();

        $notice = DB::table('notifications')
            ->where('notifiable_id', $technician->id)
            ->where('type', 'maintenance.repair_rejected')
            ->first();
        $this->assertNotNull($notice);
        $this->assertSame(
            '/self-service/maintenance-work-orders/'.$order->hash_id,
            json_decode($notice->data, true)['link_to'],
        );
    }

    public function test_repair_submission_notice_excludes_verifiers_with_revoked_permission(): void
    {
        $technician = $this->actor('maintenance_tech');
        $eligibleManager = $this->actor('production_manager');
        $revokedVerifier = $this->actor('production_manager');
        $revokedViewer = $this->actor('production_manager');
        foreach ([
            [$revokedVerifier, 'maintenance.wo.verify'],
            [$revokedViewer, 'maintenance.view'],
        ] as [$revokedUser, $permission]) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $revokedUser->id,
                'permission_id' => DB::table('permissions')->where('slug', $permission)->value('id'),
                'type' => 'revoke',
                'granted_by' => $eligibleManager->id,
                'reason' => 'Maintenance notification access revoked for regression test.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $order = $this->order($technician, true);
        $this->recordPass($order, $technician);

        $this->actingAs($technician)->postJson("/api/v1/maintenance/work-orders/{$order->hash_id}/submit-repair", [
            'diagnosis' => 'Loose connection.',
            'repair_action' => 'Reconnected wiring.',
            'functional_test_result' => 'Load test passed.',
        ])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $eligibleManager->id,
            'type' => 'maintenance.repair_submitted',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $revokedVerifier->id,
            'type' => 'maintenance.repair_submitted',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $revokedViewer->id,
            'type' => 'maintenance.repair_submitted',
        ]);
    }

    public function test_preventive_recovery_requires_supervisor_and_legacy_complete_remains_available(): void
    {
        $technician = $this->actor('maintenance_tech');
        $manager = $this->actor('production_manager');
        $supervisor = $this->actor('maintenance_supervisor');
        $verified = $this->order($technician, true, 'preventive');
        $url = "/api/v1/maintenance/work-orders/{$verified->hash_id}";

        $this->recordPass($verified, $technician);
        $this->actingAs($technician)->postJson($url.'/submit-repair', [
            'diagnosis' => 'Scheduled bearing inspection.',
            'repair_action' => 'Lubricated bearings.',
            'functional_test_result' => 'Test cycle passed.',
        ])->assertOk();
        $this->actingAs($manager)->postJson($url.'/verify', ['decision' => 'accept'])->assertForbidden();
        $this->actingAs($supervisor)->postJson($url.'/verify', ['decision' => 'accept'])->assertOk();

        $legacy = $this->order($technician, false);
        $legacy->forceFill(['mwo_number' => 'MWO-202610-0007'])->save();
        $this->actingAs($technician)->postJson("/api/v1/maintenance/work-orders/{$legacy->hash_id}/work-records", [
            'finding' => 'Inspection complete', 'action_taken' => 'Serviced machine', 'result' => 'passed',
        ])->assertCreated();
        $this->actingAs($technician)->patchJson("/api/v1/maintenance/work-orders/{$legacy->hash_id}/complete")
            ->assertOk();
        $this->assertSame('completed', $legacy->fresh()->status->value);
    }

    public function test_submission_validates_evidence_and_assignee_and_pending_freezes_mutations(): void
    {
        $technician = $this->actor('maintenance_tech');
        $other = $this->actor('maintenance_tech');
        $supervisor = $this->actor('maintenance_supervisor');
        $order = $this->order($technician, true);
        $url = "/api/v1/maintenance/work-orders/{$order->hash_id}";
        $evidence = ['diagnosis' => 'Fault identified', 'repair_action' => 'Repaired drive', 'functional_test_result' => 'Load test passed'];
        $this->actingAs($technician)->postJson($url.'/submit-repair', array_replace($evidence, ['diagnosis' => '   ']))->assertUnprocessable();
        $this->actingAs($other)->postJson($url.'/submit-repair', $evidence)->assertForbidden();
        $this->recordPass($order, $technician);
        $this->actingAs($technician)->postJson($url.'/submit-repair', $evidence)->assertOk();
        $this->postJson($url.'/submit-repair', $evidence)->assertUnprocessable();
        $this->patchJson($url.'/cancel', ['reason' => 'Withdraw repair'])->assertUnprocessable();
        $this->actingAs($supervisor)->patchJson($url.'/assign', [
            'employee_id' => $other->employee->hash_id,
            'expected_assignee_id' => $technician->employee->hash_id,
            'handover_note' => 'Handing over repair',
        ])->assertUnprocessable();
        $service = app(SparePartUsageService::class);
        try {
            $service->record($order, ['item_id' => 0, 'location_id' => 0, 'quantity' => '1'], $technician);
            $this->fail('Pending repair accepted a part issue.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
        try {
            $service->returnPart($order, new SparePartUsage,
                '1', $technician, (string) Str::uuid());
            $this->fail('Pending repair accepted a part return.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
        $this->assertSame('in_progress', $order->fresh()->status->value);
    }

    public function test_correct_role_cannot_verify_own_submission_or_an_unsubmitted_repair(): void
    {
        $technician = $this->actor('maintenance_tech');
        $manager = $this->actor('production_manager');
        $order = $this->order($technician, true);
        $url = "/api/v1/maintenance/work-orders/{$order->hash_id}";
        $this->actingAs($manager)->postJson($url.'/verify', ['decision' => 'accept'])->assertUnprocessable();
        $order->forceFill(['diagnosis' => 'Fault', 'repair_action' => 'Repair',
            'functional_test_result' => 'Passed', 'repair_submitted_at' => now(),
            'repair_submitted_by' => $manager->id])->save();
        $this->postJson($url.'/verify', ['decision' => 'accept'])->assertForbidden();
        $otherManager = $this->actor('production_manager');
        $this->actingAs($otherManager)->postJson($url.'/verify', ['decision' => 'reject', 'reason' => ' '])->assertUnprocessable();
        $this->assertNull($order->fresh()->verified_at);
    }

    public function test_corrective_mold_recovery_requires_supervisor_and_acceptance_runs_once(): void
    {
        $technician = $this->actor('maintenance_tech');
        $manager = $this->actor('production_manager');
        $supervisor = $this->actor('maintenance_supervisor');
        $mold = Mold::create([
            'mold_code' => 'VERIFY-MOLD', 'name' => 'Verification mold',
            'product_id' => Product::factory()->create()->id,
            'cavity_count' => 1, 'cycle_time_seconds' => 10, 'output_rate_per_hour' => 300,
            'max_shots_before_maintenance' => 100000, 'lifetime_max_shots' => 1000000,
            'status' => 'maintenance', 'current_shot_count' => 5000, 'maintenance_count' => 0,
        ]);
        $order = $this->order($technician, true);
        $order->forceFill(['maintainable_type' => 'mold', 'maintainable_id' => $mold->id])->save();
        $url = "/api/v1/maintenance/work-orders/{$order->hash_id}";
        $this->recordPass($order, $technician);
        $this->actingAs($technician)->postJson($url.'/submit-repair', [
            'diagnosis' => 'Worn guide', 'repair_action' => 'Replaced guide', 'functional_test_result' => 'Trial shots passed',
        ])->assertOk();
        $this->actingAs($manager)->postJson($url.'/verify', ['decision' => 'accept'])->assertForbidden();
        $this->actingAs($supervisor)->postJson($url.'/verify', ['decision' => 'accept'])->assertOk();
        $this->postJson($url.'/verify', ['decision' => 'accept'])->assertOk();
        $this->assertSame(1, $mold->fresh()->maintenance_count);
        $this->assertSame(0, $mold->fresh()->current_shot_count);
        $this->assertSame('available', $mold->fresh()->status->value);
        $this->assertSame(1, $mold->history()->where('event_type', 'maintenance_completed')->count());
    }

    private function actor(string $roleSlug): User
    {
        $department = Department::firstOrCreate(['code' => 'MAINT'], ['name' => 'Maintenance']);
        $employee = Employee::factory()->create(['department_id' => $department->id, 'status' => 'active']);

        return User::factory()->create([
            'role_id' => Role::where('slug', $roleSlug)->firstOrFail()->id,
            'employee_id' => $employee->id,
            'is_active' => true,
        ]);
    }

    private function recordPass(MaintenanceWorkOrder $order, User $technician): void
    {
        $this->actingAs($technician)->postJson("/api/v1/maintenance/work-orders/{$order->hash_id}/work-records", [
            'finding' => 'Fault corrected', 'action_taken' => 'Performed recovery test', 'result' => 'passed',
        ])->assertCreated();
    }

    private function order(User $technician, bool $verificationRequired, string $type = 'corrective'): MaintenanceWorkOrder
    {
        $machine = Machine::factory()->create(['status' => 'maintenance']);
        $order = MaintenanceWorkOrder::create([
            'mwo_number' => 'VERIFY-'.uniqid(),
            'maintainable_type' => 'machine',
            'maintainable_id' => $machine->id,
            'type' => $type,
            'priority' => 'high',
            'description' => 'Repair verification fixture',
            'status' => 'in_progress',
            'assigned_to' => $technician->employee_id,
            'started_at' => now()->subMinutes(30),
            'verification_required' => $verificationRequired,
            'created_by' => $technician->id,
        ]);
        MachineDowntime::create([
            'machine_id' => $machine->id,
            'maintenance_order_id' => $order->id,
            'start_time' => now()->subMinutes(30),
            'category' => 'planned_maintenance',
        ]);
        $order->forceFill(['created_at' => now()->subMinutes(40)])->save();

        return $order;
    }
}
