<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Exceptions\InspectionCertificateException;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Services\CoCService;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * M056 — a Certificate of Conformance must not outrun its evidence.
 *
 * `status = passed` is a snapshot the inspection state machine writes at
 * completion. The measurement rows the certificate actually asserts are
 * reachable outside that state machine (imports, console tasks, direct SQL, a
 * cascade), and `Inspection::$fillable` still contains `status`, so a row can
 * claim a passed verdict it never earned. For an IATF 16949 supplier the
 * certificate is the artefact an external auditor holds, so CoCService
 * re-reads the evidence rather than trusting the verdict column.
 *
 * The negative cases below were all reproducible against real PostgreSQL rows
 * before the guard existed: each one issued a certificate.
 */
class CoCEvidenceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private InspectionSpec $spec;

    private InspectionService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $role = Role::firstOrCreate(['slug' => 'qc_inspector'], ['name' => 'QC Inspector']);
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $this->product = Product::create([
            'part_number' => 'COC-'.substr(uniqid(), -6),
            'name' => 'CoC Evidence Bushing',
            'unit_of_measure' => 'pcs',
            'standard_cost' => '10.00',
            'is_active' => true,
        ]);

        $this->spec = InspectionSpec::create([
            'product_id' => $this->product->id,
            'version' => 1,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);
        InspectionSpecItem::create([
            'inspection_spec_id' => $this->spec->id,
            'parameter_name' => 'Shaft OD',
            'parameter_type' => 'dimensional',
            'unit_of_measure' => 'mm',
            'nominal_value' => '10.0000',
            'tolerance_min' => '9.9000',
            'tolerance_max' => '10.1000',
            'is_critical' => true,
            'sort_order' => 1,
        ]);
        $this->spec->ensureCurrentRevision();

        $this->svc = app(InspectionService::class);
    }

    /**
     * The control case. A lot taken through the real lifecycle — scaffolded
     * from the spec, every sampled unit measured, completed by the service —
     * must still certify. This is what proves the guard bounds falsification
     * rather than the ordinary outgoing-QC flow.
     */
    public function test_a_properly_inspected_lot_still_receives_its_certificate(): void
    {
        $inspection = $this->passedOutgoingThroughService();

        $out = app(CoCService::class)->buildBinaryForInspection($inspection);

        $this->assertSame(
            'COC-'.str_replace('QC-', '', (string) $inspection->inspection_number),
            $out['coc_number'],
        );
        $this->assertStringContainsString('%PDF', substr($out['contents'], 0, 8));
        $this->assertStringStartsWith('CoC-', $out['file_name']);
    }

    public function test_certificate_is_refused_when_no_measurement_was_recorded(): void
    {
        $inspection = $this->fabricatePassedOutgoing(batch: 100, sample: 8);
        $this->assertSame(0, InspectionMeasurement::query()
            ->where('inspection_id', $inspection->id)->count());

        $this->assertCertificateRefused($inspection, 'COC_NO_MEASUREMENT_EVIDENCE');
    }

    public function test_certificate_is_refused_when_the_lot_is_only_part_inspected(): void
    {
        $inspection = $this->fabricatePassedOutgoing(batch: 500, sample: 50);
        for ($i = 1; $i <= 50; $i++) {
            $this->fabricateMeasurement($inspection, $i, $i <= 5 ? '10.0000' : null, $i <= 5 ? true : null);
        }

        $this->assertCertificateRefused($inspection, 'COC_EVIDENCE_INCOMPLETE');
    }

    public function test_certificate_is_refused_when_the_evidence_contradicts_the_verdict(): void
    {
        // The real reachability: a lot passes, the certificate is issued, and
        // the readings are then rewritten outside the state machine.
        $inspection = $this->passedOutgoingThroughService();
        app(CoCService::class)->buildBinaryForInspection($inspection);

        $rewritten = DB::table('inspection_measurements')
            ->where('inspection_id', $inspection->id)
            ->update(['measured_value' => '3.0000', 'is_pass' => false]);
        $this->assertGreaterThan(0, $rewritten);

        $this->assertCertificateRefused($inspection->fresh(), 'COC_EVIDENCE_CONTRADICTS_VERDICT');
    }

    public function test_certificate_is_refused_when_the_evidence_rows_are_deleted(): void
    {
        $inspection = $this->passedOutgoingThroughService();
        app(CoCService::class)->buildBinaryForInspection($inspection);

        DB::table('inspection_measurements')->where('inspection_id', $inspection->id)->delete();

        // Without the guard this re-issued the SAME certificate number with an
        // empty critical-dimension table — a bare conformance claim.
        $this->assertCertificateRefused($inspection->fresh(), 'COC_NO_MEASUREMENT_EVIDENCE');
    }

    public function test_certificate_is_refused_when_fewer_units_were_measured_than_declared(): void
    {
        $inspection = $this->fabricatePassedOutgoing(batch: 500, sample: 50);
        // Ten fully-resolved passing units against a declared sample of fifty.
        for ($i = 1; $i <= 10; $i++) {
            $this->fabricateMeasurement($inspection, $i, '10.0000', true);
        }

        $this->assertCertificateRefused($inspection, 'COC_EVIDENCE_SHORT_OF_SAMPLE');
    }

    /**
     * The certificate number is derived from the inspection number, so a
     * re-issue is the same certificate. Storing fresh bytes each time left
     * several vault documents claiming one number with different checksums
     * (the payload embeds `issued_at` and the requesting user).
     */
    public function test_reissuing_a_certificate_does_not_fork_the_vault_copy(): void
    {
        $inspection = $this->passedOutgoingThroughService();
        $coc = app(CoCService::class);

        $this->actingAs($this->user);
        $coc->generateForInspection($inspection);
        $coc->generateForInspection($inspection);

        $rows = DB::table('documents')
            ->where('entity_type', Inspection::class)
            ->where('entity_id', $inspection->id)
            ->get(['checksum_sha256']);

        $this->assertCount(1, $rows, 'One certificate number must map to one vault document.');
    }

    private function assertCertificateRefused(Inspection $inspection, string $expectedCode): void
    {
        try {
            app(CoCService::class)->buildBinaryForInspection($inspection);
            $this->fail("A Certificate of Conformance was issued; expected refusal {$expectedCode}.");
        } catch (InspectionCertificateException $e) {
            $this->assertSame($expectedCode, $e->errorCode(), $e->getMessage());
        }
    }

    private function passedOutgoingThroughService(int $good = 10): Inspection
    {
        $so = SalesOrder::factory()->create();
        $wo = WorkOrder::factory()->create([
            'product_id' => $this->product->id,
            'sales_order_id' => $so->id,
            'quantity_target' => $good,
        ]);
        $output = WorkOrderOutput::create([
            'work_order_id' => $wo->id,
            'batch_code' => 'CB-'.substr(uniqid(), -6),
            'good_count' => $good,
            'reject_count' => 0,
            'recorded_at' => now(),
            'recorded_by' => $this->user->id,
        ]);

        $inspection = $this->svc->create([
            'stage' => InspectionStage::Outgoing->value,
            'product_id' => $this->product->id,
            'batch_quantity' => $good,
            'work_order_output_id' => $output->id,
        ], $this->user);

        $patch = [];
        foreach (InspectionMeasurement::query()->where('inspection_id', $inspection->id)->get() as $m) {
            $patch[$m->id] = ['measured_value' => '10.0000'];
        }
        $this->svc->recordMeasurements($inspection, $patch, $this->user);

        return $this->svc->complete($inspection->fresh(), $this->user);
    }

    /**
     * A row asserting a passed verdict it never earned. `status` is still in
     * `Inspection::$fillable`, so this is exactly what an import or console
     * task can write today — it is the threat the guard exists for, not a
     * convenience shortcut.
     */
    private function fabricatePassedOutgoing(int $batch, int $sample): Inspection
    {
        $inspection = Inspection::query()->create([
            'inspection_number' => 'QC-F-'.substr(uniqid(), -7),
            'stage' => InspectionStage::Outgoing->value,
            'status' => InspectionStatus::Draft->value,
            'product_id' => $this->product->id,
            'inspection_spec_id' => $this->spec->id,
            'batch_quantity' => $batch,
            'sample_size' => $sample,
            'accept_count' => 1,
            'reject_count' => 2,
            'defect_count' => 0,
            'inspector_id' => $this->user->id,
            'started_at' => now(),
        ]);
        $inspection->forceFill([
            'status' => InspectionStatus::Passed->value,
            'completed_at' => now(),
        ])->save();

        return $inspection->fresh();
    }

    private function fabricateMeasurement(
        Inspection $inspection,
        int $sampleIndex,
        ?string $measured,
        ?bool $isPass,
    ): InspectionMeasurement {
        return InspectionMeasurement::query()->create([
            'inspection_id' => $inspection->id,
            'sample_index' => $sampleIndex,
            'parameter_name' => 'Shaft OD',
            'parameter_type' => 'dimensional',
            'unit_of_measure' => 'mm',
            'nominal_value' => '10.0000',
            'tolerance_min' => '9.9000',
            'tolerance_max' => '10.1000',
            'measured_value' => $measured,
            'is_critical' => true,
            'is_pass' => $isPass,
        ]);
    }
}
