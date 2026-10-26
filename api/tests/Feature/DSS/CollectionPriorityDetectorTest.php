<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\DSS\Detectors\CollectionPriorityDetector;
use App\Modules\DSS\Services\DetectorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D09 — collections priority reuses InvoiceService::aging() (the AR aging
 * report's own engine), aggregated to one emission per late bucket. Current
 * and d1_30 rows are not a collections priority: they are still "current or
 * near-due" and the per-invoice AR alerts own that window.
 */
class CollectionPriorityDetectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->set('alerts.ar.warning_overdue_days', 30);
        app(SettingsService::class)->set('alerts.ar.critical_overdue_days', 60);
    }

    private function detector(): CollectionPriorityDetector
    {
        return app(CollectionPriorityDetector::class);
    }

    private function overdueInvoice(Customer $customer, int $daysOverdue, string $total): void
    {
        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'date' => now()->subDays($daysOverdue + 30)->toDateString(),
            'due_date' => now()->subDays($daysOverdue)->toDateString(),
            'is_vatable' => true,
            'subtotal' => $total,
            'vat_amount' => '0.00',
            'total_amount' => $total,
            'amount_paid' => '0.00',
            'balance' => $total,
            'status' => 'finalized',
        ]);
    }

    public function test_clean_slate_emits_nothing(): void
    {
        $this->assertSame([], $this->detector()->detect()->emissions);
    }

    public function test_emits_one_row_per_late_bucket_and_skips_current_window(): void
    {
        $acme = Customer::factory()->create(['name' => 'Acme Corp']);
        $beta = Customer::factory()->create(['name' => 'Beta Ltd']);

        $this->overdueInvoice($acme, 45, '112.00');  // d31_60 → warning
        $this->overdueInvoice($acme, 70, '224.00');  // d61_90 → critical
        $this->overdueInvoice($beta, 120, '560.00'); // d91_plus → critical
        $this->overdueInvoice($acme, 10, '90.00');   // d1_30 → not a priority yet
        Invoice::factory()->create([                 // not yet due → current
            'customer_id' => $beta->id,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(20)->toDateString(),
            'is_vatable' => true,
            'subtotal' => '100.00',
            'vat_amount' => '12.00',
            'total_amount' => '112.00',
            'amount_paid' => '0.00',
            'balance' => '112.00',
            'status' => 'finalized',
        ]);

        $byKey = [];
        $scan = $this->detector()->detect();
        $this->assertSame(5, $scan->rowsScanned);
        foreach ($scan->emissions as $emission) {
            $byKey[$emission->dedupeKey] = $emission;
        }

        $this->assertArrayHasKey('ar-aging:d31_60', $byKey);
        $this->assertArrayHasKey('ar-aging:d61_90', $byKey);
        $this->assertArrayHasKey('ar-aging:d91_plus', $byKey);
        $this->assertArrayNotHasKey('ar-aging:current', $byKey);
        $this->assertArrayNotHasKey('ar-aging:d1_30', $byKey);

        $mid = $byKey['ar-aging:d31_60'];
        $this->assertSame('collection_priority', $mid->type);
        $this->assertSame('warning', $mid->severity);
        $this->assertSame('112.00', $mid->rationale['total_amount']);
        $this->assertSame(1, $mid->rationale['affected_customer_count']);
        $this->assertSame(['finance_officer'], $mid->rationale['audience_roles']);
        $this->assertSame('/accounting/invoices', $mid->action['route']);
        $this->assertSame(1.0, $mid->confidence);

        $this->assertSame('critical', $byKey['ar-aging:d61_90']->severity);
        $this->assertSame('critical', $byKey['ar-aging:d91_plus']->severity);
    }

    public function test_evidence_lists_top_customers_by_amount_with_cap(): void
    {
        $acme = Customer::factory()->create(['name' => 'Acme Corp']);
        $beta = Customer::factory()->create(['name' => 'Beta Ltd']);
        $gamma = Customer::factory()->create(['name' => 'Gamma SA']);

        $this->overdueInvoice($acme, 40, '100.00');
        $this->overdueInvoice($beta, 45, '5000.00');
        $this->overdueInvoice($gamma, 55, '750.00');

        $emissions = $this->detector()->detect(['max_evidence' => 2])->emissions;

        $mid = null;
        foreach ($emissions as $emission) {
            if ($emission->dedupeKey === 'ar-aging:d31_60') {
                $mid = $emission;
            }
        }

        $this->assertNotNull($mid);
        $this->assertSame(3, $mid->rationale['affected_customer_count']);
        $this->assertSame(2, count($mid->rationale['evidence']));
        $this->assertStringContainsString('Beta Ltd', $mid->rationale['evidence'][0]);
        $this->assertStringContainsString('Gamma SA', $mid->rationale['evidence'][1]);
    }

    public function test_detector_contract_metadata(): void
    {
        $detector = $this->detector();

        $this->assertSame('dss.collection_priority', $detector->id());
        $this->assertSame('finance', $detector->domain());
        $this->assertSame(['dss.view'], $detector->ownerPermissions());
        $this->assertSame('v1', $detector->version());
    }

    public function test_registered_in_production_registry(): void
    {
        $this->assertTrue(app(DetectorRegistry::class)->has('dss.collection_priority'));
    }
}
