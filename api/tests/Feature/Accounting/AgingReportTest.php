<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REC-15 — AR/AP aging report endpoints.
 *
 * The aging computation already lived in InvoiceService::aging() /
 * BillService::aging() (used on every finance-dashboard load); these tests
 * pin the newly-exposed report routes + CSV export.
 */
class AgingReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function financeUser(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
        ]);
    }

    public function test_ar_aging_buckets_receivables_by_customer_and_age(): void
    {
        $customer = Customer::factory()->create(['name' => 'Toyota Motor Phils']);

        // Current: due in the future.
        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status'      => 'finalized',
            'due_date'    => now()->addDays(10)->toDateString(),
            'total_amount' => '1000.00', 'amount_paid' => '0.00', 'balance' => '1000.00',
        ]);
        // 31-60 bucket: due 45 days ago.
        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status'      => 'finalized',
            'due_date'    => now()->subDays(45)->toDateString(),
            'total_amount' => '500.00', 'amount_paid' => '0.00', 'balance' => '500.00',
        ]);
        // Paid invoice must NOT appear in receivables.
        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status'      => 'paid',
            'due_date'    => now()->subDays(200)->toDateString(),
            'total_amount' => '9999.00', 'amount_paid' => '9999.00', 'balance' => '0.00',
        ]);

        $res = $this->actingAs($this->financeUser())
            ->getJson('/api/v1/accounting/statements/ar-aging')
            ->assertStatus(200);

        $res->assertJsonPath('data.buckets.current', '1000.00');
        $res->assertJsonPath('data.buckets.d31_60', '500.00');
        $res->assertJsonPath('data.buckets.total', '1500.00');
        $res->assertJsonPath('data.by_customer.0.customer_name', 'Toyota Motor Phils');
        // Never leak a raw integer id.
        $this->assertIsString($res->json('data.by_customer.0.customer_id'));
        $this->assertNotSame((string) $customer->id, $res->json('data.by_customer.0.customer_id'));
    }

    public function test_ap_aging_buckets_payables_by_vendor(): void
    {
        $vendor = Vendor::factory()->create(['name' => 'Mitsui Resins']);

        // Bill has no explicit factory (unlike Invoice); build via create() to
        // match the convention in the Purchasing 3-way-match tests.
        Bill::create([
            'bill_number'  => 'BILL-'.substr(uniqid(), -8),
            'vendor_id'    => $vendor->id,
            'date'         => now()->subDays(150)->toDateString(),
            'due_date'     => now()->subDays(120)->toDateString(),
            'is_vatable'   => false,
            'subtotal'     => '2000.00', 'vat_amount' => '0.00', 'total_amount' => '2000.00',
            'amount_paid'  => '0.00', 'balance' => '2000.00',
            'status'       => 'unpaid',
            'created_by'   => $this->financeUser()->id,
        ]);

        $res = $this->actingAs($this->financeUser())
            ->getJson('/api/v1/accounting/statements/ap-aging')
            ->assertStatus(200);

        $res->assertJsonPath('data.buckets.d91_plus', '2000.00');
        $res->assertJsonPath('data.buckets.total', '2000.00');
        $res->assertJsonPath('data.by_vendor.0.vendor_name', 'Mitsui Resins');
    }

    public function test_ar_aging_csv_export_streams_with_total_row(): void
    {
        $customer = Customer::factory()->create(['name' => 'Honda Cars Phils']);
        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status'      => 'finalized',
            'due_date'    => now()->subDays(10)->toDateString(),
            'total_amount' => '750.00', 'amount_paid' => '0.00', 'balance' => '750.00',
        ]);

        $res = $this->actingAs($this->financeUser())
            ->get('/api/v1/accounting/statements/ar-aging?format=csv')
            ->assertStatus(200);

        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $body = $res->streamedContent();
        $this->assertStringContainsString('Customer,Current,1-30', $body);
        $this->assertStringContainsString('Honda Cars Phils', $body);
        $this->assertStringContainsString('TOTAL', $body);
    }

    public function test_aging_requires_statements_permission(): void
    {
        $employee = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);

        $this->actingAs($employee)
            ->getJson('/api/v1/accounting/statements/ar-aging')
            ->assertStatus(403);
        $this->actingAs($employee)
            ->getJson('/api/v1/accounting/statements/ap-aging')
            ->assertStatus(403);
    }
}
