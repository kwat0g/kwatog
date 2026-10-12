<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Models\NonConformanceReport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class NcrRecoveryCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    public function test_quality_dashboard_counts_unlinked_rework_ncrs_and_links_to_filtered_list(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'qc_inspector')->value('id'),
        ]);
        $closedGap = NonConformanceReport::factory()->create([
            'status' => 'closed', 'closed_by' => $user->id, 'closed_at' => now(), 'disposition' => 'rework',
        ]);
        $openGap = NonConformanceReport::factory()->create(['disposition' => 'rework']);
        NonConformanceReport::factory()->create([
            'status' => 'cancelled', 'disposition' => 'rework',
        ]);
        NonConformanceReport::factory()->create([
            'disposition' => 'rework', 'rework_work_order_id' => WorkOrder::factory()->create()->id,
        ]);
        NonConformanceReport::factory()->create([
            'disposition' => 'rework', 'replacement_work_order_id' => WorkOrder::factory()->create()->id,
        ]);
        NonConformanceReport::factory()->create(['disposition' => 'scrap']);

        $dashboard = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/quality')
            ->assertOk()
            ->json('data');
        $recoveryCard = collect($dashboard['kpis'])->firstWhere('label', 'Unlinked Rework NCRs');

        $this->assertNotNull($recoveryCard);
        $this->assertSame('2', $recoveryCard['value']);

        $this->getJson('/api/v1/quality/ncrs?recovery_coverage=unlinked')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['id' => $closedGap->hash_id])
            ->assertJsonFragment(['id' => $openGap->hash_id]);
    }
}
