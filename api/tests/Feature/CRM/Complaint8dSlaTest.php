<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\ComplaintStatus;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Services\Complaint8dEscalationService;
use App\Modules\CRM\Services\ComplaintService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Complaint8dSlaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // Seed recipient pool used by the service (quality + qc_inspector).
        foreach (['qc_inspector', 'quality'] as $slug) {
            $roleId = Role::query()->where('slug', $slug)->value('id');
            if ($roleId) {
                User::factory()->create(['role_id' => $roleId, 'is_active' => true]);
            }
        }
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function makeComplaint(?int $assignedTo = null): CustomerComplaint
    {
        return app(ComplaintService::class)->create([
            'customer_id'       => Customer::factory()->create()->id,
            'received_date'     => now()->toDateString(),
            'severity'          => 'medium',
            'description'       => 'Generic defect description',
            'affected_quantity' => 1,
            'assigned_to'       => $assignedTo,
        ], $this->admin());
    }

    public function test_not_yet_due_is_silent(): void
    {
        $c = $this->makeComplaint();

        $counts = app(Complaint8dEscalationService::class)->run();

        $this->assertSame(['d3' => 0, 'd4' => 0, 'finalize' => 0], $counts);
        $this->assertSame([], $c->fresh()->sla_alert_levels ?? []);
    }

    public function test_d3_overdue_with_empty_d3_containment_fires_once(): void
    {
        $c = $this->makeComplaint();
        DB::table('customer_complaints')->where('id', $c->id)->update([
            'created_at' => now()->subHours(49),
            'd3_due_at'  => now()->subHour(),
            'd4_due_at'  => now()->addDays(5),
            'finalize_due_at' => now()->addDays(28),
        ]);

        $counts = app(Complaint8dEscalationService::class)->run();

        $this->assertSame(1, $counts['d3']);
        $this->assertSame(['d3'], $c->fresh()->sla_alert_levels);
    }

    public function test_d3_idempotent_re_run_does_not_double_fire(): void
    {
        $c = $this->makeComplaint();
        DB::table('customer_complaints')->where('id', $c->id)->update([
            'created_at' => now()->subHours(49),
            'd3_due_at'  => now()->subHour(),
            'd4_due_at'  => now()->addDays(5),
            'finalize_due_at' => now()->addDays(28),
        ]);

        app(Complaint8dEscalationService::class)->run();
        $counts = app(Complaint8dEscalationService::class)->run();

        $this->assertSame(['d3' => 0, 'd4' => 0, 'finalize' => 0], $counts);
        $this->assertSame(['d3'], $c->fresh()->sla_alert_levels);
    }

    public function test_d4_fires_after_d3_already_recorded(): void
    {
        $c = $this->makeComplaint();
        DB::table('customer_complaints')->where('id', $c->id)->update([
            'created_at' => now()->subDays(8),
            'd3_due_at'  => now()->subDays(6),
            'd4_due_at'  => now()->subHour(),
            'finalize_due_at' => now()->addDays(22),
            'sla_alert_levels' => json_encode(['d3']),
        ]);

        $counts = app(Complaint8dEscalationService::class)->run();

        $this->assertSame(1, $counts['d4']);
        $levels = $c->fresh()->sla_alert_levels;
        $this->assertContains('d3', $levels);
        $this->assertContains('d4', $levels);
    }

    public function test_terminal_complaint_is_skipped(): void
    {
        $c = $this->makeComplaint();
        DB::table('customer_complaints')->where('id', $c->id)->update([
            'created_at' => now()->subDays(31),
            'd3_due_at'  => now()->subDays(29),
            'd4_due_at'  => now()->subDays(24),
            'finalize_due_at' => now()->subHour(),
            'status'     => ComplaintStatus::Closed->value,
            'closed_at'  => now()->subDay(),
        ]);

        $counts = app(Complaint8dEscalationService::class)->run();

        $this->assertSame(['d3' => 0, 'd4' => 0, 'finalize' => 0], $counts);
        // Service must not have touched sla_alert_levels for a terminal complaint.
        $this->assertSame([], $c->fresh()->sla_alert_levels ?? []);
    }
}
