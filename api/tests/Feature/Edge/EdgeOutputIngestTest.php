<?php

declare(strict_types=1);

namespace Tests\Feature\Edge;

use App\Modules\Auth\Models\Role;
use App\Modules\Edge\Models\EdgeDevice;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * T2.2 — Edge WO output ingest via PLC counter device.
 *
 * Verifies POST /api/v1/edge/v1/output resolves the active WO on the
 * device's bound machine and delegates to WorkOrderOutputService::record(),
 * inheriting idempotency, mold shot bumps, scrap-rate updates, and dashboard
 * event dispatch for free.
 */
class EdgeOutputIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed the role the lazy system-user provisioning relies on.
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
        // Defensive: also clear any production idempotency keys from prior tests
        // so duplicates don't replay a stale (now-rolled-back) output id.
        foreach (['press3-001'] as $k) {
            Cache::forget("production:idem:{$k}");
        }
    }

    private function plcDevice(?Machine $machine = null): EdgeDevice
    {
        return EdgeDevice::create([
            'serial_number' => 'PLC-' . uniqid(),
            'name'          => 'Test PLC',
            'device_type'   => 'plc_counter',
            'location'      => 'Press 1',
            'machine_id'    => $machine?->id,
        ]);
    }

    private function tokenFor(EdgeDevice $d, array $abilities = ['edge:output']): string
    {
        return $d->createToken('t', $abilities)->plainTextToken;
    }

    private function machine(): Machine
    {
        return Machine::factory()->create();
    }

    private function activeWo(Machine $m, int $target = 100, int $produced = 0): WorkOrder
    {
        return WorkOrder::factory()->create([
            'machine_id'        => $m->id,
            'status'            => WorkOrderStatus::InProgress->value,
            'quantity_target'   => $target,
            'quantity_produced' => $produced,
            'actual_start'      => now()->subHour(),
        ]);
    }

    private function makeDefect(): DefectType
    {
        // defect_types.code is varchar(10); keep it short.
        static $n = 0;
        $n++;
        return DefectType::create([
            'code'        => sprintf('DT%06d', $n),
            'name'        => 'Test Defect',
            'description' => null,
            'is_active'   => true,
        ]);
    }

    private function ingest(string $token, array $body, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(array_merge([
            'Authorization' => "Bearer {$token}",
        ], $headers))->postJson('/api/v1/edge/v1/output', $body);
    }

    public function test_happy_path_records_output(): void
    {
        $m  = $this->machine();
        $wo = $this->activeWo($m);
        $r  = $this->ingest($this->tokenFor($this->plcDevice($m)), ['good_count' => 10]);

        $r->assertStatus(201);
        $r->assertJsonPath('data.good_count', 10);
        $this->assertSame(10, (int) $wo->fresh()->quantity_produced);
        $this->assertSame(10, (int) $wo->fresh()->quantity_good);
    }

    public function test_reject_with_matching_defects(): void
    {
        $m      = $this->machine();
        $wo     = $this->activeWo($m);
        $defect = $this->makeDefect();
        $r      = $this->ingest($this->tokenFor($this->plcDevice($m)), [
            'good_count'   => 8,
            'reject_count' => 2,
            'defects'      => [['defect_type_id' => $defect->hash_id, 'count' => 2]],
        ]);

        $r->assertStatus(201);
        $this->assertSame(2, (int) $wo->fresh()->quantity_rejected);
    }

    public function test_mismatched_defect_sum_is_422(): void
    {
        $m      = $this->machine();
        $this->activeWo($m);
        $defect = $this->makeDefect();
        $r      = $this->ingest($this->tokenFor($this->plcDevice($m)), [
            'good_count'   => 1,
            'reject_count' => 2,
            'defects'      => [['defect_type_id' => $defect->hash_id, 'count' => 1]],
        ]);
        $r->assertStatus(422);
    }

    public function test_zero_counts_is_422(): void
    {
        $m = $this->machine();
        $this->activeWo($m);
        $r = $this->ingest($this->tokenFor($this->plcDevice($m)), [
            'good_count' => 0, 'reject_count' => 0,
        ]);
        $r->assertStatus(422);
    }

    public function test_exceed_target_is_422(): void
    {
        $m = $this->machine();
        $this->activeWo($m, target: 10, produced: 5);
        $r = $this->ingest($this->tokenFor($this->plcDevice($m)), ['good_count' => 10]);
        $r->assertStatus(422);
    }

    public function test_device_not_bound_is_422(): void
    {
        $r = $this->ingest($this->tokenFor($this->plcDevice(null)), ['good_count' => 1]);
        $r->assertStatus(422);
        $this->assertStringContainsString('device_not_bound_to_machine', $r->getContent());
    }

    public function test_no_active_wo_is_422(): void
    {
        $m = $this->machine();
        $r = $this->ingest($this->tokenFor($this->plcDevice($m)), ['good_count' => 1]);
        $r->assertStatus(422);
        $this->assertStringContainsString('no_active_work_order', $r->getContent());
    }

    public function test_idempotent_replay(): void
    {
        $m   = $this->machine();
        $wo  = $this->activeWo($m);
        $tok = $this->tokenFor($this->plcDevice($m));

        $first  = $this->ingest($tok, ['good_count' => 5, 'idempotency_key' => 'press3-001']);
        $first->assertStatus(201);
        // Second call uses the header path to also exercise that route.
        $second = $this->ingest($tok, ['good_count' => 5], ['X-Idempotency-Key' => 'press3-001']);
        $second->assertStatus(201);
        // Same idempotency key → cached output replayed (note: WO is the cache key, not
        // shared with other tests because setUp flushes the array cache).
        $this->assertSame($first->json('data.output_id'), $second->json('data.output_id'));
        // Only one bump on the WO.
        $this->assertSame(5, (int) $wo->fresh()->quantity_produced);
    }

    public function test_scanner_token_cannot_ingest_output(): void
    {
        $m   = $this->machine();
        $d   = $this->plcDevice($m);
        $tok = $d->createToken('s', ['edge:scan'])->plainTextToken;
        $r   = $this->ingest($tok, ['good_count' => 1]);
        $r->assertStatus(403);
    }

    public function test_no_token_is_401(): void
    {
        $this->postJson('/api/v1/edge/v1/output', ['good_count' => 1])
            ->assertStatus(401);
    }
}
