<?php

declare(strict_types=1);

namespace Tests\Feature\Edge;

use App\Modules\Auth\Models\Role;
use App\Modules\CRM\Models\Product;
use App\Modules\Edge\Models\EdgeDevice;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionParameterType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * T2.4 — Edge inspection measurement ingest.
 *
 * Verifies POST /api/v1/edge/v1/measurement delegates to
 * InspectionService::recordMeasurements() so tolerance auto-evaluation,
 * critical-fail flagging, and status transition (`draft` → `in_progress`)
 * behave identically to the SPA QC operator flow.
 */
class EdgeMeasurementIngestTest extends TestCase
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

    private function caliperDevice(): EdgeDevice
    {
        return EdgeDevice::create([
            'serial_number' => 'CAL-'.uniqid(),
            'name'          => 'Test Caliper',
            'device_type'   => 'caliper',
            'location'      => 'QC Bench',
        ]);
    }

    private function tokenFor(EdgeDevice $d, array $abilities = ['edge:measurement']): string
    {
        return $d->createToken('t', $abilities)->plainTextToken;
    }

    /**
     * Build a draft inspection plus a single dimensional measurement row.
     * No Inspection factory exists — we mirror the working seed from
     * tests/Feature/Quality/OutgoingQcIdempotencyTest.php.
     *
     * @return array{0: Inspection, 1: InspectionMeasurement}
     */
    private function draftInspectionWithMeasurement(
        float $nominal = 12.0,
        float $tolMin = 11.95,
        float $tolMax = 12.05,
        bool $critical = false,
    ): array {
        $product = Product::factory()->create();

        $inspection = Inspection::create([
            'inspection_number' => 'QC-T-'.substr(uniqid(), -8),
            'stage'             => InspectionStage::InProcess->value,
            'status'            => InspectionStatus::Draft->value,
            'product_id'        => $product->id,
            'entity_type'       => InspectionEntityType::WorkOrder->value,
            'entity_id'         => random_int(10_000, 99_999),
            'batch_quantity'    => 1,
            'sample_size'       => 1,
            'accept_count'      => 0,
            'reject_count'      => 1,
            'defect_count'      => 0,
        ]);

        $measurement = InspectionMeasurement::create([
            'inspection_id'           => $inspection->id,
            'inspection_spec_item_id' => null,
            'sample_index'            => 1,
            'parameter_name'          => 'Outer Diameter',
            'parameter_type'          => InspectionParameterType::Dimensional->value,
            'unit_of_measure'         => 'mm',
            'nominal_value'           => $nominal,
            'tolerance_min'           => $tolMin,
            'tolerance_max'           => $tolMax,
            'measured_value'          => null,
            'is_critical'             => $critical,
            'is_pass'                 => null,
        ]);

        return [$inspection, $measurement];
    }

    private function send(string $token, array $body, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(array_merge([
            'Authorization' => "Bearer {$token}",
        ], $headers))->postJson('/api/v1/edge/v1/measurement', $body);
    }

    public function test_within_tolerance_auto_passes(): void
    {
        [$insp, $m] = $this->draftInspectionWithMeasurement();

        $r = $this->send($this->tokenFor($this->caliperDevice()), [
            'inspection_id'  => $insp->hash_id,
            'measurement_id' => $m->hash_id,
            'measured_value' => 12.000,
        ]);

        $r->assertStatus(201);
        $r->assertJsonPath('data.is_pass', true);
        $this->assertSame('in_progress', $insp->fresh()->status->value);
    }

    public function test_out_of_tolerance_fails(): void
    {
        [$insp, $m] = $this->draftInspectionWithMeasurement();

        $r = $this->send($this->tokenFor($this->caliperDevice()), [
            'inspection_id'  => $insp->hash_id,
            'measurement_id' => $m->hash_id,
            'measured_value' => 15.000,
        ]);

        $r->assertStatus(201);
        $r->assertJsonPath('data.is_pass', false);
        $r->assertJsonPath('data.inspection.defect_count', 1);
        $r->assertJsonPath('data.inspection.status', 'in_progress');
    }

    public function test_critical_failure_flagged(): void
    {
        [$insp, $m] = $this->draftInspectionWithMeasurement(critical: true);

        $r = $this->send($this->tokenFor($this->caliperDevice()), [
            'inspection_id'  => $insp->hash_id,
            'measurement_id' => $m->hash_id,
            'measured_value' => 99.000,
        ]);

        $r->assertStatus(201);
        $r->assertJsonPath('data.is_pass', false);
        $r->assertJsonPath('data.is_critical', true);
    }

    public function test_invalid_inspection_id_is_422(): void
    {
        $r = $this->send($this->tokenFor($this->caliperDevice()), [
            'inspection_id'  => 'NOPE',
            'measurement_id' => 'NOPE',
            'measured_value' => 1,
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('invalid_inspection', $r->getContent());
    }

    public function test_invalid_measurement_id_is_422(): void
    {
        [$insp, ] = $this->draftInspectionWithMeasurement();

        $r = $this->send($this->tokenFor($this->caliperDevice()), [
            'inspection_id'  => $insp->hash_id,
            'measurement_id' => 'NOPE',
            'measured_value' => 1,
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('invalid_measurement', $r->getContent());
    }

    public function test_measurement_from_different_inspection_is_422(): void
    {
        [$inspA, ] = $this->draftInspectionWithMeasurement();
        [, $mB]    = $this->draftInspectionWithMeasurement();

        $r = $this->send($this->tokenFor($this->caliperDevice()), [
            'inspection_id'  => $inspA->hash_id,
            'measurement_id' => $mB->hash_id,
            'measured_value' => 12.0,
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('measurement_not_in_inspection', $r->getContent());
    }

    public function test_finalised_inspection_rejects_writes(): void
    {
        [$insp, $m] = $this->draftInspectionWithMeasurement();
        $insp->forceFill(['status' => InspectionStatus::Passed->value])->save();

        $r = $this->send($this->tokenFor($this->caliperDevice()), [
            'inspection_id'  => $insp->hash_id,
            'measurement_id' => $m->hash_id,
            'measured_value' => 12.0,
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('inspection_finalised', $r->getContent());
    }

    public function test_idempotent_replay(): void
    {
        [$insp, $m] = $this->draftInspectionWithMeasurement();
        $tok = $this->tokenFor($this->caliperDevice());

        $first = $this->send($tok, [
            'inspection_id'   => $insp->hash_id,
            'measurement_id'  => $m->hash_id,
            'measured_value'  => 12.0,
            'idempotency_key' => 'cal-001',
        ]);

        $second = $this->send($tok, [
            'inspection_id'   => $insp->hash_id,
            'measurement_id'  => $m->hash_id,
            'measured_value'  => 999.0, // different value — must be ignored on replay
            'idempotency_key' => 'cal-001',
        ]);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertSame(
            $first->json('data.measurement_id'),
            $second->json('data.measurement_id'),
        );
        // The underlying row must keep the FIRST value because the second
        // call short-circuits in the ingest service BEFORE delegating to
        // InspectionService::recordMeasurements().
        $this->assertSame('12.0000', (string) $m->fresh()->measured_value);
    }

    public function test_plc_token_cannot_post_measurement(): void
    {
        $d = $this->caliperDevice();
        $tok = $d->createToken('p', ['edge:output'])->plainTextToken;

        $r = $this->send($tok, [
            'inspection_id'  => 'X',
            'measurement_id' => 'X',
            'measured_value' => 1,
        ]);

        $r->assertStatus(403);
    }

    public function test_no_token_is_401(): void
    {
        $this->postJson('/api/v1/edge/v1/measurement', [
            'inspection_id'  => 'X',
            'measurement_id' => 'X',
            'measured_value' => 1,
        ])->assertStatus(401);
    }
}
