<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Models\NonConformanceReport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class NcrSlaOverdueBadgeParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
        $this->travelTo(now()->setTime(12, 0));

        foreach (['critical' => 8, 'high' => 24, 'medium' => 72, 'low' => 168] as $severity => $hours) {
            app(SettingsService::class)->set("quality.ncr.sla_{$severity}_hours", $hours, 'quality');
        }
    }

    public function test_overdue_badge_matches_the_sla_filtered_company_list(): void
    {
        $reader = User::factory()->withRole('qc_inspector')->create();
        $this->ncr('critical', 'open', 8); // SLA boundary is overdue.
        $this->ncr('critical', 'open', 7); // Not yet overdue.
        $this->ncr('high', 'in_progress', 30);
        $this->ncr('critical', 'open', 48, ['last_escalated_at' => now()->subHour()]);
        $this->ncr('medium', 'open', 80);
        $this->ncr('low', 'open', 200);
        $this->ncr('critical', 'closed', 48);
        $this->ncr('critical', 'cancelled', 48);

        $badgeCount = $this->actingAs($reader, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data.overdue_ncrs.count');
        $list = $this->actingAs($reader, 'sanctum')
            ->getJson('/api/v1/quality/ncrs?signal=overdue&per_page=100')
            ->assertOk();

        $this->assertSame(4, $badgeCount);
        $this->assertSame($badgeCount, $list->json('meta.total'));
        $this->actingAs($reader, 'sanctum')
            ->getJson('/api/v1/quality/ncrs?signal=overdue&status=closed')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->actingAs($reader, 'sanctum')
            ->getJson('/api/v1/quality/ncrs?signal=unknown')
            ->assertUnprocessable();
    }

    public function test_overdue_signal_uses_the_same_quality_permission_as_its_list(): void
    {
        $reader = User::factory()->withRole('employee')->create();
        $this->ncr('critical', 'open', 48);

        $this->actingAs($reader, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->assertJsonMissingPath('data.overdue_ncrs');
        $this->actingAs($reader, 'sanctum')
            ->getJson('/api/v1/quality/ncrs?signal=overdue')
            ->assertForbidden();
    }

    /** @param array<string, mixed> $overrides */
    private function ncr(string $severity, string $status, int $ageHours, array $overrides = []): NonConformanceReport
    {
        return NonConformanceReport::factory()->create(array_merge([
            'severity' => $severity,
            'status' => $status,
            'created_at' => now()->subHours($ageHours),
        ], $overrides));
    }
}
