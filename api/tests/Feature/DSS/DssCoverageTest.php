<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Modules\Auth\Models\User;
use App\Modules\DSS\Models\DssRunLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DssCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_coverage_only_lists_detectors_owned_by_current_role(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->withRole('hr_officer')->create(['is_active' => true]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/dss/coverage')->assertOk();

        $this->assertGreaterThan(0, $response->json('data.available_detectors'));
        $this->assertTrue(collect($response->json('data.checks'))->contains('id', 'dss.payroll_anomaly_summary'));
        $this->assertTrue(collect($response->json('data.checks'))->contains('id', 'dss.training_expiry_summary'));
        $this->assertFalse(collect($response->json('data.checks'))->contains('id', 'dss.work_order_intervention'));
    }

    public function test_coverage_reports_newest_run_when_detector_has_history(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->withRole('hr_officer')->create(['is_active' => true]);

        DssRunLog::query()->create([
            'detector_id' => 'dss.payroll_anomaly_summary',
            'status' => 'failed',
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subSeconds(30),
            'duration_ms' => 100,
            'error' => 'Old failed run.',
        ]);
        DssRunLog::query()->create([
            'detector_id' => 'dss.payroll_anomaly_summary',
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 100,
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/dss/coverage')->assertOk();
        $payroll = collect($response->json('data.checks'))->firstWhere('id', 'dss.payroll_anomaly_summary');

        $this->assertSame('completed', $payroll['last_run']['status']);
        $this->assertSame(0, $response->json('data.failed_detectors'));
    }
}
