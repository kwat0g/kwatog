<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\DeliverySchedule;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B2B — internal review of portal-submitted delivery schedules.
 *
 * Portal submissions were write-only: nothing internal listed, acknowledged or
 * rejected them. These tests pin the internal HTTP surface (list, filter,
 * acknowledge, reject) and its permission gates.
 */
class DeliveryScheduleReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
    }

    /* ─── Helpers ────────────────────────────────────────────────── */

    /** A user whose role grants exactly $slugs — nothing wider. */
    private function actor(array $slugs): User
    {
        $role = Role::create([
            'name' => 'B2B Review '.substr(uniqid(), -5),
            'slug' => 'b2b_review_'.substr(uniqid(), -5),
            'description' => 'B2B review test role',
            'is_system' => false,
        ]);

        $permissionIds = [];
        foreach ($slugs as $slug) {
            $permissionIds[] = Permission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'module' => 'b2b'],
            )->id;
        }
        $role->permissions()->sync($permissionIds);

        return User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    private function customerSchedule(array $overrides = []): DeliverySchedule
    {
        return DeliverySchedule::create(array_merge([
            'customer_id' => Customer::factory()->create()->id,
            'month' => now()->addMonth()->format('Y-m'),
            'status' => 'submitted',
            'lines' => [['product_name' => 'X', 'quantity' => 5, 'notes' => null]],
        ], $overrides));
    }

    private function supplierSchedule(array $overrides = []): DeliverySchedule
    {
        return DeliverySchedule::create(array_merge([
            'vendor_id' => Vendor::factory()->create()->id,
            'month' => now()->addMonth()->format('Y-m'),
            'status' => 'submitted',
            'lines' => [['product_name' => 'X', 'quantity' => 5, 'notes' => null]],
        ], $overrides));
    }

    /* ─── List ───────────────────────────────────────────────────── */

    public function test_internal_can_list_both_customer_and_supplier_schedules(): void
    {
        $this->customerSchedule();
        $this->supplierSchedule();

        $response = $this->actingAs($this->actor(['b2b.portal_access.view']))
            ->getJson('/api/v1/b2b/portal-access/delivery-schedules')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
        $sources = $response->json('data.*.source');
        $this->assertEqualsCanonicalizing(['customer', 'supplier'], $sources);
    }

    public function test_list_filters_by_source_and_status(): void
    {
        $customer = $this->customerSchedule();
        $this->supplierSchedule();

        $ids = $this->actingAs($this->actor(['b2b.portal_access.view']))
            ->getJson('/api/v1/b2b/portal-access/delivery-schedules?source=customer&status=submitted')
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame([$customer->hash_id], $ids);
    }

    public function test_internal_can_show_a_schedule(): void
    {
        $schedule = $this->customerSchedule();

        $this->actingAs($this->actor(['b2b.portal_access.view']))
            ->getJson("/api/v1/b2b/portal-access/delivery-schedules/{$schedule->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.id', $schedule->hash_id);
    }

    /* ─── Review actions ─────────────────────────────────────────── */

    public function test_internal_can_acknowledge_submitted_schedule(): void
    {
        $schedule = $this->customerSchedule();
        $actor = $this->actor(['b2b.portal_access.manage']);

        $this->actingAs($actor)
            ->postJson("/api/v1/b2b/portal-access/delivery-schedules/{$schedule->hash_id}/acknowledge")
            ->assertOk()
            ->assertJsonPath('data.status', 'acknowledged');

        $fresh = $schedule->fresh();
        $this->assertSame('acknowledged', $fresh->status->value);
        $this->assertSame($actor->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);
    }

    public function test_internal_can_reject_with_reason(): void
    {
        $schedule = $this->customerSchedule();
        $actor = $this->actor(['b2b.portal_access.manage']);

        $this->actingAs($actor)
            ->postJson("/api/v1/b2b/portal-access/delivery-schedules/{$schedule->hash_id}/reject", [
                'reason' => 'Not enough lead time',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.reject_reason', 'Not enough lead time');

        $fresh = $schedule->fresh();
        $this->assertSame('rejected', $fresh->status->value);
        $this->assertSame('Not enough lead time', $fresh->reject_reason);
        $this->assertSame($actor->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);
    }

    public function test_cannot_acknowledge_twice(): void
    {
        $schedule = $this->customerSchedule();
        $actor = $this->actor(['b2b.portal_access.manage']);

        $this->actingAs($actor)
            ->postJson("/api/v1/b2b/portal-access/delivery-schedules/{$schedule->hash_id}/acknowledge")
            ->assertOk();

        $this->actingAs($actor)
            ->postJson("/api/v1/b2b/portal-access/delivery-schedules/{$schedule->hash_id}/acknowledge")
            ->assertStatus(422);
    }

    public function test_reject_requires_reason(): void
    {
        $schedule = $this->customerSchedule();
        $actor = $this->actor(['b2b.portal_access.manage']);

        $this->actingAs($actor)
            ->postJson("/api/v1/b2b/portal-access/delivery-schedules/{$schedule->hash_id}/reject", [
                'reason' => '',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    /* ─── Authorization ──────────────────────────────────────────── */

    public function test_list_requires_view_permission(): void
    {
        $this->customerSchedule();

        $this->actingAs($this->actor([]))
            ->getJson('/api/v1/b2b/portal-access/delivery-schedules')
            ->assertStatus(403);
    }

    public function test_manage_actions_require_manage_permission(): void
    {
        $schedule = $this->customerSchedule();
        $viewOnly = $this->actor(['b2b.portal_access.view']);

        $this->actingAs($viewOnly)
            ->postJson("/api/v1/b2b/portal-access/delivery-schedules/{$schedule->hash_id}/acknowledge")
            ->assertStatus(403);
    }
}
