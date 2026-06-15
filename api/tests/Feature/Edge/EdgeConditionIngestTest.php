<?php

declare(strict_types=1);

namespace Tests\Feature\Edge;

use App\Modules\Auth\Models\Role;
use App\Modules\Edge\Models\EdgeDevice;
use App\Modules\Maintenance\Models\MachineConditionReading;
use App\Modules\MRP\Models\Machine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * T2.3 — Edge condition ingest via IoT sensor.
 *
 * Verifies POST /api/v1/edge/v1/condition delegates to
 * PredictiveMaintenanceService so breach detection, the consecutive-breach
 * gate, and corrective-MWO creation behave identically to the SPA
 * condition-reading path.
 */
class EdgeConditionIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Role::query()->where('slug', 'employee')->exists()) {
            Role::create([
                'name'        => 'Employee',
                'slug'        => 'employee',
                'description' => 'Default test role',
                'is_system'   => false,
            ]);
        }
        Cache::store('array')->flush();
        Cache::flush();
        Cache::forget('edge:system_user_id');
    }

    private function iotDevice(?Machine $machine = null): EdgeDevice
    {
        return EdgeDevice::create([
            'serial_number' => 'IOT-' . uniqid(),
            'name'          => 'Test Sensor',
            'device_type'   => 'iot_sensor',
            'location'      => 'Press 1',
            'machine_id'    => $machine?->id,
        ]);
    }

    private function tokenFor(EdgeDevice $d, array $abilities = ['edge:condition']): string
    {
        return $d->createToken('t', $abilities)->plainTextToken;
    }

    private function machine(): Machine
    {
        return Machine::factory()->create();
    }

    private function send(string $token, array $body, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(array_merge([
            'Authorization' => "Bearer {$token}",
        ], $headers))->postJson('/api/v1/edge/v1/condition', $body);
    }

    public function test_normal_reading_persists_no_trigger(): void
    {
        $m = $this->machine();
        $r = $this->send($this->tokenFor($this->iotDevice($m)), [
            'metric' => 'vibration',
            'value'  => 1.2,
            'unit'   => 'mm/s',
        ]);

        $r->assertStatus(201);
        $r->assertJsonPath('data.triggered', false);
        $this->assertSame(1, MachineConditionReading::query()->where('machine_id', $m->id)->count());
    }

    public function test_single_breach_does_not_yet_trigger_mwo(): void
    {
        $m = $this->machine();
        // temperature max threshold is 85.0 °C; 999 is way above.
        $r = $this->send($this->tokenFor($this->iotDevice($m)), [
            'metric' => 'temperature',
            'value'  => 999,
        ]);

        $r->assertStatus(201);
        $r->assertJsonPath('data.triggered', false);
        $this->assertStringContainsString('exceeds safe threshold', (string) $r->json('data.reason'));
    }

    public function test_consecutive_breaches_create_mwo(): void
    {
        $m      = $this->machine();
        $device = $this->iotDevice($m);
        $tok    = $this->tokenFor($device);

        // BREACH_WINDOW = 3 → need 3 consecutive breaches to trip.
        $this->send($tok, ['metric' => 'temperature', 'value' => 999])->assertStatus(201);
        $this->send($tok, ['metric' => 'temperature', 'value' => 999])->assertStatus(201);
        $r = $this->send($tok, ['metric' => 'temperature', 'value' => 999]);

        $r->assertStatus(201);
        $r->assertJsonPath('data.triggered', true);
        $this->assertNotEmpty($r->json('data.work_order.mwo_number'));
    }

    public function test_unknown_metric_is_422(): void
    {
        $m = $this->machine();
        $r = $this->send($this->tokenFor($this->iotDevice($m)), [
            'metric' => 'humidity',
            'value'  => 50,
        ]);
        $r->assertStatus(422);
    }

    public function test_non_numeric_value_is_422(): void
    {
        $m = $this->machine();
        $r = $this->send($this->tokenFor($this->iotDevice($m)), [
            'metric' => 'temperature',
            'value'  => 'hot',
        ]);
        $r->assertStatus(422);
    }

    public function test_device_not_bound_is_422(): void
    {
        $r = $this->send($this->tokenFor($this->iotDevice(null)), [
            'metric' => 'temperature',
            'value'  => 50,
        ]);
        $r->assertStatus(422);
        $this->assertStringContainsString('device_not_bound_to_machine', $r->getContent());
    }

    public function test_idempotent_replay(): void
    {
        $m   = $this->machine();
        $tok = $this->tokenFor($this->iotDevice($m));

        $first  = $this->send($tok, [
            'metric'          => 'vibration',
            'value'           => 0.5,
            'idempotency_key' => 'iot-001',
        ]);
        $second = $this->send($tok, [
            'metric'          => 'vibration',
            'value'           => 0.5,
            'idempotency_key' => 'iot-001',
        ]);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertSame($first->json('data.reading_id'), $second->json('data.reading_id'));
        $this->assertSame(1, MachineConditionReading::query()->where('machine_id', $m->id)->count());
    }

    public function test_plc_token_cannot_post_condition(): void
    {
        $m   = $this->machine();
        $d   = $this->iotDevice($m);
        $tok = $d->createToken('p', ['edge:output'])->plainTextToken;
        $r   = $this->send($tok, ['metric' => 'temperature', 'value' => 50]);
        $r->assertStatus(403);
    }

    public function test_no_token_is_401(): void
    {
        $this->postJson('/api/v1/edge/v1/condition', [
            'metric' => 'temperature',
            'value'  => 50,
        ])->assertStatus(401);
    }

    public function test_unit_default_applied_when_omitted(): void
    {
        $m = $this->machine();
        $r = $this->send($this->tokenFor($this->iotDevice($m)), [
            'metric' => 'temperature',
            'value'  => 30, // no unit provided
        ]);

        $r->assertStatus(201);
        $reading = MachineConditionReading::query()->where('machine_id', $m->id)->first();
        $this->assertSame('celsius', $reading?->unit);
    }
}
