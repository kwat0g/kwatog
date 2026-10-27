<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Models\NcrAction;
use App\Modules\Quality\Models\NonConformanceReport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class EffectivenessDueBadgeParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    public function test_current_user_badge_matches_due_and_soon_effectiveness_queue(): void
    {
        $owner = User::factory()->withRole('system_admin')->create();
        $other = User::factory()->withRole('system_admin')->create();
        $today = today();

        $this->action($owner, $today->copy()->subDay()->toDateString());
        $this->action($owner, $today->toDateString());
        $this->action($owner, $today->copy()->addDays(3)->toDateString());
        $this->action($owner, $today->copy()->addDays(4)->toDateString());
        $this->action($other, $today->toDateString());
        $this->action($owner, $today->toDateString(), 'effective');
        $this->action($owner, $today->toDateString(), 'pending_verification', 'containment');
        $this->action($owner, $today->toDateString(), 'pending_verification', 'corrective', 'open');

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->assertJsonPath('data.effectiveness_due.count', 3);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/quality/ncrs/effectiveness/due?signal=awaiting_my_action')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/quality/ncrs/effectiveness/due')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        app(SettingsService::class)->set('quality.effectiveness.due_soon_days', 4, 'quality');
        Cache::flush();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->assertJsonPath('data.effectiveness_due.count', 4);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/quality/ncrs/effectiveness/due?signal=awaiting_my_action')
            ->assertOk()
            ->assertJsonPath('meta.total', 4);

        $unprivileged = User::factory()->withRole('employee')->create();
        $this->assertFalse($unprivileged->hasPermission('quality.ncr.view'));
        $this->actingAs($unprivileged, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->assertJsonMissingPath('data.effectiveness_due');
        $this->actingAs($unprivileged, 'sanctum')
            ->getJson('/api/v1/quality/ncrs/effectiveness/due?signal=awaiting_my_action')
            ->assertForbidden();
    }

    private function action(
        User $owner,
        string $due,
        string $effectivenessStatus = 'pending_verification',
        string $actionType = 'corrective',
        string $ncrStatus = 'closed',
    ): void {
        $ncr = NonConformanceReport::factory()->create([
            'status' => $ncrStatus,
        ]);

        NcrAction::create([
            'ncr_id' => $ncr->id,
            'action_type' => $actionType,
            'description' => 'Verify action effectiveness',
            'performed_by' => $owner->id,
            'owner_id' => $owner->id,
            'performed_at' => now(),
            'effectiveness_status' => $effectivenessStatus,
            'next_effectiveness_check_at' => $due,
        ]);
    }
}
