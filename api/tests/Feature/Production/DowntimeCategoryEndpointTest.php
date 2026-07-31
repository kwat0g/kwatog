<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DowntimeCategoryEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_returns_authoritative_pause_categories(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->firstOrFail()->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/production/downtime-categories');

        $response->assertOk()->assertJsonCount(5, 'data');
        $response->assertJsonFragment([
            'value' => 'material_shortage',
            'label' => 'Material shortage',
            'is_planned' => false,
        ]);
    }
}
