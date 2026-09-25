<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O2C audit 2026-09-25 — the delivery page renders "Mark <next status>" from
 * these options. A delivered shipment offered `confirmed`, which the status
 * route always refuses (confirmation is its own action), so the button failed.
 */
class DeliveryNextStatusOptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_forward_status_never_offers_confirmation_or_cancellation(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create(['role_id' => Role::query()->where('slug', 'system_admin')->value('id')]);

        $statuses = collect($this->actingAs($user)
            ->getJson('/api/v1/supply-chain/deliveries/options')
            ->assertOk()
            ->json('data.statuses'))
            ->keyBy('value');

        $this->assertSame('loading', $statuses['scheduled']['next_status']);
        $this->assertSame('in_transit', $statuses['loading']['next_status']);
        $this->assertSame('delivered', $statuses['in_transit']['next_status']);
        $this->assertNull($statuses['delivered']['next_status']);
        $this->assertNull($statuses['confirmed']['next_status']);
    }
}
