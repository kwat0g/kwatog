<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchasingReadBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_read_only_purchasing_context_roles_cannot_read_supplier_review_data(): void
    {
        foreach (['production_manager', 'impex_officer', 'department_head'] as $roleSlug) {
            $user = $this->userWithRole($roleSlug);

            $this->actingAs($user)->getJson('/api/v1/purchasing/approved-suppliers')->assertForbidden();
            $this->actingAs($user)->getJson('/api/v1/purchasing/supplier-listings')->assertForbidden();
        }
    }

    public function test_purchasing_officer_can_read_supplier_review_data(): void
    {
        $user = $this->userWithRole('purchasing_officer');

        $this->actingAs($user)->getJson('/api/v1/purchasing/approved-suppliers')->assertOk();
        $this->actingAs($user)->getJson('/api/v1/purchasing/supplier-listings')->assertOk();
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'is_active' => true,
        ]);
    }
}
