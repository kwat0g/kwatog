<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\SupplyChain\Models\Delivery;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Delivery reschedule — a scheduled delivery's date could not be moved after
 * creation. Covers the rule gate (scheduled only, must differ), original-date
 * preservation across repeated moves, append-only history, note capture, and
 * the permission boundary.
 */
class DeliveryRescheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_scheduled_delivery_can_be_rescheduled(): void
    {
        $officer = $this->officer();
        $original = now()->addDays(3)->toDateString();
        $delivery = $this->seedDelivery($officer, 'scheduled', $original);
        $newDate = now()->addDays(7)->toDateString();

        $response = $this->actingAs($officer)
            ->patchJson($this->url($delivery), [
                'scheduled_date' => $newDate,
                'reason' => 'Customer requested a later window.',
            ])
            ->assertOk();

        $response->assertJsonPath('data.scheduled_date', $newDate);
        $response->assertJsonPath('data.original_scheduled_date', $original);
        $response->assertJsonPath('data.reschedule_count', 1);

        $fresh = $delivery->fresh();
        $this->assertSame($newDate, $fresh->scheduled_date->toDateString());
        $this->assertSame($original, $fresh->original_scheduled_date->toDateString());
        $this->assertSame(1, $fresh->reschedule_count);
        $this->assertStringContainsString('[Reschedule ', (string) $fresh->notes);
        $this->assertStringContainsString('Customer requested a later window.', (string) $fresh->notes);

        $this->assertDatabaseHas('delivery_reschedules', [
            'delivery_id' => $delivery->id,
            'from_date' => $original,
            'to_date' => $newDate,
            'rescheduled_by' => $officer->id,
        ]);

        $this->assertCount(1, $response->json('data.reschedules'));
        $this->assertSame($original, $response->json('data.reschedules.0.from_date'));
        $this->assertSame($newDate, $response->json('data.reschedules.0.to_date'));
        $this->assertSame($officer->id, $this->decode($response->json('data.reschedules.0.rescheduled_by.id')));
    }

    public function test_second_reschedule_keeps_the_original_date_and_increments_count(): void
    {
        $officer = $this->officer();
        $original = now()->addDays(2)->toDateString();
        $delivery = $this->seedDelivery($officer, 'scheduled', $original);

        $this->actingAs($officer)
            ->patchJson($this->url($delivery), [
                'scheduled_date' => now()->addDays(5)->toDateString(),
                'reason' => 'First move.',
            ])
            ->assertOk();

        $this->actingAs($officer)
            ->patchJson($this->url($delivery), [
                'scheduled_date' => now()->addDays(9)->toDateString(),
                'reason' => 'Second move.',
            ])
            ->assertOk()
            ->assertJsonPath('data.original_scheduled_date', $original)
            ->assertJsonPath('data.reschedule_count', 2);

        $fresh = $delivery->fresh();
        $this->assertSame($original, $fresh->original_scheduled_date->toDateString());
        $this->assertDatabaseCount('delivery_reschedules', 2);
    }

    public function test_non_scheduled_delivery_is_rejected(): void
    {
        $officer = $this->officer();
        $delivery = $this->seedDelivery($officer, 'loading');

        $this->actingAs($officer)
            ->patchJson($this->url($delivery), [
                'scheduled_date' => now()->addDays(4)->toDateString(),
                'reason' => 'Trying to move an active load.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only scheduled deliveries can be rescheduled.');

        $this->assertDatabaseCount('delivery_reschedules', 0);
    }

    public function test_same_date_is_rejected(): void
    {
        $officer = $this->officer();
        $sameDate = now()->addDays(3)->toDateString();
        $delivery = $this->seedDelivery($officer, 'scheduled', $sameDate);

        $this->actingAs($officer)
            ->patchJson($this->url($delivery), [
                'scheduled_date' => $sameDate,
                'reason' => 'No actual change here.',
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('delivery_reschedules', 0);
    }

    public function test_past_date_is_rejected_by_validation(): void
    {
        $officer = $this->officer();
        $delivery = $this->seedDelivery($officer, 'scheduled', now()->addDays(3)->toDateString());

        $this->actingAs($officer)
            ->patchJson($this->url($delivery), [
                'scheduled_date' => now()->subDay()->toDateString(),
                'reason' => 'Moving to the past is not allowed.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scheduled_date');
    }

    public function test_missing_reason_is_rejected(): void
    {
        $officer = $this->officer();
        $delivery = $this->seedDelivery($officer, 'scheduled', now()->addDays(3)->toDateString());

        $this->actingAs($officer)
            ->patchJson($this->url($delivery), [
                'scheduled_date' => now()->addDays(6)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_user_without_create_permission_gets_403(): void
    {
        $officer = $this->officer();
        $delivery = $this->seedDelivery($officer, 'scheduled', now()->addDays(3)->toDateString());

        $outsider = User::factory()->create([
            'role_id' => $this->roleWithoutPermissions()->id,
        ]);

        $this->actingAs($outsider)
            ->patchJson($this->url($delivery), [
                'scheduled_date' => now()->addDays(8)->toDateString(),
                'reason' => 'Not allowed to move this.',
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('delivery_reschedules', 0);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function officer(): User
    {
        $role = Role::create([
            'name' => 'Reschedule Officer '.uniqid(),
            'slug' => 'reschedule_officer_'.uniqid(),
            'description' => 'Test',
        ]);
        $perm = Permission::firstOrCreate(
            ['slug' => 'supply_chain.deliveries.create'],
            ['name' => 'Create Deliveries', 'module' => 'supply_chain'],
        );
        $role->permissions()->syncWithoutDetaching([$perm->id]);

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function roleWithoutPermissions(): Role
    {
        return Role::create([
            'name' => 'Reschedule Outsider '.uniqid(),
            'slug' => 'reschedule_outsider_'.uniqid(),
            'description' => 'Test',
        ]);
    }

    private function seedDelivery(User $user, string $status = 'scheduled', ?string $scheduledDate = null): Delivery
    {
        $customer = Customer::create([
            'name' => 'Reschedule Customer '.uniqid(),
            'is_active' => true,
        ]);

        $so = SalesOrder::create([
            'so_number' => 'SO-RS-'.substr(uniqid(), -10),
            'customer_id' => $customer->id,
            'date' => now()->toDateString(),
            'subtotal' => '1000.00',
            'vat_amount' => '120.00',
            'total_amount' => '1120.00',
            'created_by' => $user->id,
        ]);
        $so->forceFill(['status' => 'confirmed'])->save();

        $delivery = Delivery::create([
            'delivery_number' => 'DL-RS-'.substr(uniqid(), -10),
            'sales_order_id' => $so->id,
            'scheduled_date' => $scheduledDate ?? now()->addDays(3)->toDateString(),
            'created_by' => $user->id,
        ]);
        $delivery->forceFill(['status' => $status])->save();

        return $delivery;
    }

    private function url(Delivery $delivery): string
    {
        return '/api/v1/supply-chain/deliveries/'.$delivery->hash_id.'/reschedule';
    }

    private function decode(string $hashId): int
    {
        return (int) (app('hashids')->decode($hashId)[0] ?? 0);
    }
}
