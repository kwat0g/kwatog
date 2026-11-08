<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Models\NonConformanceReport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NcrAvailableActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancel_action_matches_the_server_cancellation_guards(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);

        $open = NonConformanceReport::factory()->create();
        $lateIncoming = NonConformanceReport::factory()->create(['is_late_incoming' => true]);
        $mrbSettled = NonConformanceReport::factory()->create(['mrb_decided_at' => now()]);
        $closed = NonConformanceReport::factory()->create(['status' => 'closed']);

        $this->actingAs($user)
            ->getJson('/api/v1/quality/ncrs/'.$open->hash_id)
            ->assertOk()
            ->assertJsonPath('data.available_actions.can_cancel', true);

        foreach ([$lateIncoming, $mrbSettled, $closed] as $ncr) {
            $this->getJson('/api/v1/quality/ncrs/'.$ncr->hash_id)
                ->assertOk()
                ->assertJsonPath('data.available_actions.can_cancel', false);
        }
    }
}
