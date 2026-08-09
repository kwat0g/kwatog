<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Maintenance\Models\MachineConditionReading;
use App\Modules\Maintenance\Services\PredictiveMaintenanceService;
use App\Modules\MRP\Models\Machine;
use Database\Seeders\MachineSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: condition-reading logic must accept hash_ids.
 *
 * The SPA sends `machine_id` as a hash_id (per the ID-obfuscation rule — see
 * spa/src/api/maintenance/conditionReadings.ts, whose param type is `string`).
 * The HTTP surface that used to decode it (controller + request) is HIDDEN
 * 2026-08-08 (scope cut — no direct machine connection), so the kept path is
 * PredictiveMaintenanceService fed a decoded integer. These asserts pin the
 * contract that survives: hash_id → decodeHash() → service → persisted row.
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

    /** A hash_id must decode to the same machine a raw id names. */
    public function test_hash_id_decodes_to_same_machine(): void
    {
        $this->assertSame($this->machine->id, Machine::decodeHash($this->machine->hash_id));
    }

    /** The kept service accepts the decoded integer id and persists the row. */
    public function test_service_accepts_decoded_hash_id(): void
    {
        $result = app(PredictiveMaintenanceService::class)->recordAndEvaluate([
            'machine_id' => Machine::decodeHash($this->machine->hash_id),
            'metric' => 'temperature',
            'value' => 42.0,
            'source' => 'manual',
        ], $this->admin);

        $this->assertFalse($result['triggered']);
        $this->assertDatabaseHas('machine_condition_readings', [
            'machine_id' => $this->machine->id,
            'metric' => 'temperature',
            'value' => 42.0,
        ]);
    }

    /** Raw integer ids must keep working — internal callers use them. */
    public function test_raw_integer_id_still_accepted(): void
    {
        MachineConditionReading::create([
            'machine_id' => $this->machine->id,
            'metric' => 'vibration',
            'value' => 1.5,
            'unit' => 'mm/s',
            'source' => 'manual',
            'recorded_at' => now(),
        ]);

        $result = app(PredictiveMaintenanceService::class)->recordAndEvaluate([
            'machine_id' => $this->machine->id,
            'metric' => 'vibration',
            'value' => 1.6,
            'source' => 'manual',
        ], $this->admin);

        $this->assertFalse($result['triggered']);
        $this->assertDatabaseHas('machine_condition_readings', [
            'machine_id' => $this->machine->id,
            'metric' => 'vibration',
            'value' => 1.6,
        ]);
    }
}
