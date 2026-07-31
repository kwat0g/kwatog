<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Maintenance\Models\MachineConditionReading;
use App\Modules\MRP\Models\Machine;
use Database\Seeders\MachineSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: condition-reading endpoints must accept hash_ids.
 *
 * The SPA sends `machine_id` as a hash_id (per the ID-obfuscation rule — see
 * spa/src/api/maintenance/conditionReadings.ts, whose param type is `string`).
 * These endpoints validated `machine_id` as `integer`, so every call from the
 * Machine Health page and the mobile condition-reading page 422'd.
 *
 * The pre-existing tests all passed raw integer ids, which is why the break was
 * invisible: they exercised a payload the frontend never sends.
 */
class ConditionReadingHashIdTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Machine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(MachineSeeder::class);

        $this->admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        $this->machine = Machine::query()->firstOrFail();
    }

    private function seedReading(string $metric = 'vibration', float $value = 1.5): void
    {
        MachineConditionReading::create([
            'machine_id' => $this->machine->id,
            'metric' => $metric,
            'value' => $value,
            'unit' => 'mm/s',
            'source' => 'manual',
            'recorded_at' => now(),
        ]);
    }

    public function test_index_accepts_hash_id(): void
    {
        $this->seedReading();

        $this->actingAs($this->admin)
            ->getJson('/api/v1/maintenance/condition-readings?machine_id='.$this->machine->hash_id)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_trend_accepts_hash_id(): void
    {
        $this->seedReading();

        $this->actingAs($this->admin)
            ->getJson('/api/v1/maintenance/condition-readings/trend'
                .'?machine_id='.$this->machine->hash_id.'&metric=vibration')
            ->assertOk();
    }

    public function test_health_snapshot_accepts_hash_id(): void
    {
        $this->seedReading();

        $this->actingAs($this->admin)
            ->getJson('/api/v1/maintenance/condition-readings/health-snapshot'
                .'?machine_id='.$this->machine->hash_id)
            ->assertOk();
    }

    public function test_store_accepts_hash_id(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/maintenance/condition-readings', [
                'machine_id' => $this->machine->hash_id,
                'metric' => 'temperature',
                'value' => 42.0,
                'source' => 'manual',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('machine_condition_readings', [
            'machine_id' => $this->machine->id,
            'metric' => 'temperature',
        ]);
    }

    /** Raw integer ids must keep working — existing tests and internal callers use them. */
    public function test_raw_integer_id_still_accepted(): void
    {
        $this->seedReading();

        $this->actingAs($this->admin)
            ->getJson('/api/v1/maintenance/condition-readings?machine_id='.$this->machine->id)
            ->assertOk();
    }

    /** An undecodable id must fail validation cleanly, never 500. */
    public function test_garbage_id_fails_validation_not_fatally(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/maintenance/condition-readings?machine_id=not-a-real-id')
            ->assertStatus(422);
    }
}
