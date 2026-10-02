<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Common\Models\AuditLog;
use App\Common\Models\ScheduledExport;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SoftDeleteAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_soft_delete_and_restore_audit_the_actual_before_and_after_values(): void
    {
        $export = $this->scheduledExport();

        $export->delete();

        $deleted = AuditLog::query()
            ->where('model_type', $export->getMorphClass())
            ->where('model_id', $export->id)
            ->where('action', 'deleted')
            ->firstOrFail();
        $this->assertNull($deleted->old_values['deleted_at'] ?? null);
        $this->assertNotNull($deleted->new_values['deleted_at'] ?? null);

        $export->restore();

        $restored = AuditLog::query()
            ->where('model_type', $export->getMorphClass())
            ->where('model_id', $export->id)
            ->where('action', 'updated')
            ->latest('id')
            ->firstOrFail();
        $this->assertNotNull($restored->old_values['deleted_at'] ?? null);
        $this->assertArrayHasKey('deleted_at', $restored->new_values);
        $this->assertNull($restored->new_values['deleted_at']);
    }

    public function test_force_delete_keeps_the_before_snapshot_and_records_no_after_state(): void
    {
        $export = $this->scheduledExport();

        $export->forceDelete();

        $deleted = AuditLog::query()
            ->where('model_type', $export->getMorphClass())
            ->where('model_id', $export->id)
            ->where('action', 'deleted')
            ->firstOrFail();
        $this->assertNull($deleted->old_values['deleted_at'] ?? null);
        $this->assertNull($deleted->new_values);
        $this->assertDatabaseMissing('scheduled_exports', ['id' => $export->id]);
    }

    private function scheduledExport(): ScheduledExport
    {
        $role = Role::create(['name' => 'Audit test', 'slug' => 'audit-test']);
        $owner = User::factory()->create(['role_id' => $role->id]);

        return ScheduledExport::create([
            'owner_id' => $owner->id,
            'name' => 'Audit test export',
            'module' => 'hr.employees',
            'columns' => ['employee_no'],
            'filters' => [],
            'format' => 'csv',
            'frequency' => 'daily',
            'time_of_day' => '06:00',
            'recipients' => [$owner->email],
            'next_run_at' => now()->addDay(),
            'is_active' => true,
        ]);
    }
}
