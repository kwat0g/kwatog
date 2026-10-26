<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\DSS\Detectors\StuckDocumentDetector;
use App\Modules\DSS\Services\DetectorRegistry;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * D26 — stuck documents reuse ChainBottleneckService (the chain widget's own
 * engine), aggregated to one emission per bottleneck key. Dynamic
 * `next_approver` audiences stay out: D25 owns pending approvals by role.
 */
class StuckDocumentDetectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->set('dashboard.chain_bottlenecks', [
            'so_at_mrp_planned' => ['label' => 'SO awaiting production', 'hours' => 48, 'audience' => 'ppc_head'],
            'wo_confirmed_unstarted' => ['label' => 'WO awaiting material issue', 'hours' => 24, 'audience' => 'warehouse_staff'],
            'invoice_draft_overdue' => ['label' => 'Finance invoices', 'hours' => 24, 'audience' => 'next_approver'],
        ]);
    }

    private int $customerId = 0;

    private int $createdBy = 0;

    private function detector(): StuckDocumentDetector
    {
        return app(StuckDocumentDetector::class);
    }

    private function seedParties(): void
    {
        $role = Role::firstOrCreate(['slug' => 'tester'], ['name' => 'Tester']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->customerId = Customer::factory()->create()->id;
        $this->createdBy = $user->id;
    }

    private function stuckSalesOrder(int $id, int $ageHours): void
    {
        DB::table('sales_orders')->insert([
            'id' => $id,
            'so_number' => 'SO-STUCK-'.$id,
            'customer_id' => $this->customerId,
            'date' => Carbon::now()->toDateString(),
            'subtotal' => '0.00',
            'vat_amount' => '0.00',
            'total_amount' => '0.00',
            'status' => 'confirmed',
            'payment_terms_days' => 30,
            'created_by' => $this->createdBy,
            'created_at' => Carbon::now()->subHours($ageHours),
            'updated_at' => Carbon::now()->subHours($ageHours),
        ]);
    }

    public function test_clean_slate_emits_nothing(): void
    {
        $this->assertSame([], $this->detector()->detect()->emissions);
    }

    public function test_emits_one_row_per_key_with_audience_and_skips_next_approver(): void
    {
        $this->seedParties();

        $this->stuckSalesOrder(101, 72); // 72h vs 48h threshold → warning
        $wo = WorkOrder::factory()->create(['status' => 'confirmed']);
        $wo->forceFill(['updated_at' => Carbon::now()->subHours(100)])->save(); // 100h vs 24h → critical
        DB::table('invoices')->insert([
            'id' => 201,
            'invoice_number' => 'INV-STUCK-201',
            'customer_id' => $this->customerId,
            'date' => Carbon::now()->toDateString(),
            'due_date' => Carbon::now()->addDays(30)->toDateString(),
            'is_vatable' => true,
            'subtotal' => '0.00',
            'vat_amount' => '0.00',
            'total_amount' => '0.00',
            'amount_paid' => '0.00',
            'balance' => '0.00',
            'status' => 'draft',
            'created_at' => Carbon::now()->subHours(30),
            'updated_at' => Carbon::now()->subHours(30),
        ]);

        $emissions = $this->detector()->detect()->emissions;
        $byKey = [];
        foreach ($emissions as $emission) {
            $byKey[$emission->rationale['bottleneck_key']] = $emission;
        }

        $this->assertArrayHasKey('so_at_mrp_planned', $byKey);
        $this->assertArrayHasKey('wo_confirmed_unstarted', $byKey);
        $this->assertArrayNotHasKey('invoice_draft_overdue', $byKey);

        $so = $byKey['so_at_mrp_planned'];
        $this->assertSame('stuck_document', $so->type);
        $this->assertSame('warning', $so->severity);
        $this->assertSame(1, $so->rationale['affected_count']);
        $this->assertSame(48, $so->rationale['threshold_hours']);
        $this->assertSame(['ppc_head'], $so->rationale['audience_roles']);
        $this->assertContains('SO-STUCK-101', $so->rationale['evidence']);
        $this->assertSame('/chains', $so->action['route']);
        $this->assertSame(1.0, $so->confidence);

        $woEmission = $byKey['wo_confirmed_unstarted'];
        $this->assertSame('critical', $woEmission->severity);
        $this->assertSame(['warehouse_staff'], $woEmission->rationale['audience_roles']);
    }

    public function test_detector_contract_metadata(): void
    {
        $detector = $this->detector();

        $this->assertSame('dss.stuck_documents', $detector->id());
        $this->assertSame('platform', $detector->domain());
        $this->assertSame(['dss.view'], $detector->ownerPermissions());
        $this->assertSame('v1', $detector->version());
    }

    public function test_registered_in_production_registry(): void
    {
        $this->assertTrue(app(DetectorRegistry::class)->has('dss.stuck_documents'));
    }
}
