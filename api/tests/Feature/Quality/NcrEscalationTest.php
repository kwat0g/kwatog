<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Enums\NcrActionType;
use App\Modules\Quality\Enums\NcrSeverity;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Models\NcrAction;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\NcrEscalationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NcrEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        // Ensure recipient pools exist for each tier. All three slugs are
        // seeded by RolePermissionSeeder; firstOrCreate is defensive.
        foreach (['qc_inspector', 'production_manager', 'system_admin'] as $slug) {
            $role = Role::firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'description' => $slug, 'is_system' => true],
            );
            User::factory()->create([
                'role_id'   => $role->id,
                'is_active' => true,
            ]);
        }
    }

    private function openNcr(NcrSeverity $sev, \Carbon\Carbon $createdAt): NonConformanceReport
    {
        $ncr = NonConformanceReport::factory()->create();
        $ncr->forceFill([
            'severity'         => $sev->value,
            'status'           => NcrStatus::Open->value,
            'escalation_level' => 0,
        ])->save();
        DB::table('non_conformance_reports')->where('id', $ncr->id)
            ->update(['created_at' => $createdAt]);
        return $ncr->fresh();
    }

    public function test_open_ncr_with_corrective_already_recorded_does_not_escalate(): void
    {
        $ncr = $this->openNcr(NcrSeverity::High, now()->subHours(48));
        NcrAction::create([
            'ncr_id' => $ncr->id, 'action_type' => NcrActionType::Corrective->value,
            'description' => 'fix', 'performed_by' => User::query()->first()->id,
            'performed_at' => now()->subHour(),
        ]);

        $count = app(NcrEscalationService::class)->run();

        $this->assertSame(0, $count);
        $this->assertSame(0, (int) $ncr->fresh()->escalation_level);
    }

    public function test_critical_ncr_unactioned_8h_advances_to_tier_1(): void
    {
        $ncr = $this->openNcr(NcrSeverity::Critical, now()->subHours(9));

        $count = app(NcrEscalationService::class)->run();

        $this->assertSame(1, $count);
        $this->assertSame(1, (int) $ncr->fresh()->escalation_level);
    }

    public function test_high_ncr_under_24h_does_not_escalate(): void
    {
        $ncr = $this->openNcr(NcrSeverity::High, now()->subHours(20));

        app(NcrEscalationService::class)->run();

        $this->assertSame(0, (int) $ncr->fresh()->escalation_level);
    }

    public function test_high_ncr_at_24h_advances_to_tier_1(): void
    {
        $ncr = $this->openNcr(NcrSeverity::High, now()->subHours(25));

        app(NcrEscalationService::class)->run();

        $this->assertSame(1, (int) $ncr->fresh()->escalation_level);
    }

    public function test_tier_1_already_sent_then_24h_more_advances_to_tier_2(): void
    {
        $ncr = $this->openNcr(NcrSeverity::High, now()->subHours(50));
        $ncr->forceFill([
            'escalation_level'  => 1,
            'last_escalated_at' => now()->subHours(25),
        ])->save();

        app(NcrEscalationService::class)->run();

        $this->assertSame(2, (int) $ncr->fresh()->escalation_level);
    }

    public function test_caps_at_tier_3(): void
    {
        $ncr = $this->openNcr(NcrSeverity::High, now()->subDays(10));
        $ncr->forceFill([
            'escalation_level'  => 3,
            'last_escalated_at' => now()->subDay(),
        ])->save();

        app(NcrEscalationService::class)->run();

        $this->assertSame(3, (int) $ncr->fresh()->escalation_level);
    }
}
