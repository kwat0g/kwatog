<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\User;
use App\Modules\Quality\Models\NonConformanceReport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class NcrOpenBadgeParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    public function test_open_ncr_badge_matches_the_filtered_area_list_for_every_status(): void
    {
        $reader = User::factory()->withRole('qc_inspector')->create();
        $other = User::factory()->withRole('qc_inspector')->create();

        foreach (['open', 'in_progress', 'closed', 'cancelled'] as $status) {
            NonConformanceReport::factory()->create([
                'status' => $status,
                'created_by' => $other->id,
                'assigned_to' => $other->id,
            ]);
        }

        $badgeCount = $this->actingAs($reader, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data.ncrs.count');
        $list = $this->actingAs($reader, 'sanctum')
            ->getJson('/api/v1/quality/ncrs?signal=open&per_page=100')
            ->assertOk();

        $this->assertSame(2, $badgeCount);
        $this->assertSame($badgeCount, $list->json('meta.total'));
        $this->assertEqualsCanonicalizing(
            ['open', 'in_progress'],
            collect($list->json('data'))->pluck('status')->all(),
        );
        $this->actingAs($reader, 'sanctum')
            ->getJson('/api/v1/quality/ncrs?signal=open&status=closed')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->actingAs($reader, 'sanctum')
            ->getJson('/api/v1/quality/ncrs?signal=unknown')
            ->assertUnprocessable();
    }

    public function test_ncr_signal_requires_the_same_permission_as_the_list_and_badge(): void
    {
        NonConformanceReport::factory()->create(['status' => 'open']);
        $reader = User::factory()->withRole('employee')->create();

        $this->assertFalse($reader->hasPermission('quality.ncr.view'));
        $this->actingAs($reader, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->assertJsonMissingPath('data.ncrs');
        $this->actingAs($reader, 'sanctum')
            ->getJson('/api/v1/quality/ncrs?signal=open')
            ->assertForbidden();
    }
}
