<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChainControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeUser(): User
    {
        $roleId = Role::query()->where('slug', 'employee')->value('id');
        return User::create([
            'name'     => 'T',
            'email'    => 'u_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id'  => $roleId,
        ]);
    }

    public function test_index_lists_every_known_chain(): void
    {
        $resp = $this->actingAs($this->makeUser())
            ->getJson('/api/v1/chains')
            ->assertOk();

        $keys = collect($resp->json('data'))->pluck('key')->all();
        $this->assertContains('sales_order',     $keys);
        $this->assertContains('purchase_order',  $keys);
        $this->assertContains('work_order',      $keys);
        $this->assertContains('leave_request',   $keys);
        $this->assertContains('ncr',             $keys);
    }

    public function test_definition_returns_steps_for_known_chain(): void
    {
        $this->actingAs($this->makeUser())
            ->getJson('/api/v1/chains/sales_order/definition')
            ->assertOk()
            ->assertJsonPath('data.key',          'sales_order')
            ->assertJsonPath('data.steps.0.key',  'draft')
            ->assertJsonPath('data.steps.5.key',  'collected');
    }

    public function test_definition_404s_for_unknown_chain(): void
    {
        $this->actingAs($this->makeUser())
            ->getJson('/api/v1/chains/unknown_chain/definition')
            ->assertStatus(404);
    }

    public function test_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/chains/sales_order/definition')
            ->assertStatus(401);
    }
}
