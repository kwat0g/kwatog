<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Common\Models\ScheduledExport;
use App\Modules\Auth\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledExportExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_export_is_non_zero_and_releases_its_lease_for_retry(): void
    {
        $row = $this->dueExport(['module' => 'missing.exporter']);

        $this->artisan('exports:run-due')
            ->assertExitCode(1);

        $fresh = $row->fresh();
        $this->assertNull($fresh->processing_token);
        $this->assertNull($fresh->processing_until);
        $this->assertNotNull($fresh->last_attempt_at);
        $this->assertStringContainsString('No export class registered', (string) $fresh->last_error);
        $this->assertTrue($fresh->next_run_at->equalTo($row->next_run_at));
        $this->assertNull($fresh->last_run_at);
    }

    public function test_a_live_lease_cannot_be_claimed_by_a_second_scheduler(): void
    {
        $row = $this->dueExport([
            'processing_token' => 'runner-a',
            'processing_started_at' => now()->subMinute(),
            'processing_until' => now()->addMinutes(10),
        ]);

        $this->artisan('exports:run-due')
            ->assertExitCode(0);

        $fresh = $row->fresh();
        $this->assertSame('runner-a', $fresh->processing_token);
        $this->assertNull($fresh->last_attempt_at);
        $this->assertNull($fresh->last_run_at);
    }

    /** @param array<string, mixed> $overrides */
    private function dueExport(array $overrides = []): ScheduledExport
    {
        $owner = User::factory()->create();

        return ScheduledExport::query()->create(array_merge([
            'owner_id' => $owner->id,
            'name' => 'Nightly test export',
            'module' => 'hr.employees',
            'columns' => ['employee_no'],
            'filters' => [],
            'format' => 'csv',
            'frequency' => 'daily',
            'day_of_week' => null,
            'day_of_month' => null,
            'time_of_day' => '06:00',
            'recipients' => [$owner->email],
            'next_run_at' => Carbon::now()->subMinute(),
            'is_active' => true,
        ], $overrides));
    }
}
