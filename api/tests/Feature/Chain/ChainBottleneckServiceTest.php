<?php

declare(strict_types=1);

namespace Tests\Feature\Chain;

use App\Common\Services\ChainBottleneckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Series C — Task C5. Bottleneck detection feature test.
 *
 * Seeds rows directly via DB::table to keep the test independent of
 * domain service wiring (those are exercised by their own tests).
 */
class ChainBottleneckServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_so_at_mrp_planned_detects_only_old_confirmed_records(): void
    {
        $this->bootstrap();
        // Two SOs at confirmed: one stuck > 48h, one fresh.
        $this->insertSalesOrder(101, 'SO-202604-0001', 'confirmed', Carbon::now()->subHours(72));
        $this->insertSalesOrder(102, 'SO-202604-0002', 'confirmed', Carbon::now()->subHours(2));
        $this->insertSalesOrder(103, 'SO-202604-0003', 'draft',     Carbon::now()->subHours(72));

        $svc = app(ChainBottleneckService::class);
        $rows = $svc->detect('so_at_mrp_planned');

        $this->assertCount(1, $rows);
        $this->assertSame('SO-202604-0001', $rows[0]['doc_number']);
        $this->assertSame('sales_order', $rows[0]['entity_type']);
        $this->assertSame('confirmed', $rows[0]['status']);
        $this->assertGreaterThanOrEqual(48, (int) $rows[0]['hours_stuck']);
    }

    public function test_unknown_detector_key_returns_empty(): void
    {
        $svc = app(ChainBottleneckService::class);
        $this->assertSame([], $svc->detect('does_not_exist'));
    }

    public function test_detect_all_returns_keys_for_every_configured_bottleneck(): void
    {
        $svc = app(ChainBottleneckService::class);
        $all = $svc->detectAll();

        $expected = array_keys(config('chain.bottlenecks'));
        foreach ($expected as $k) {
            $this->assertArrayHasKey($k, $all);
        }
    }

    public function test_invoice_draft_overdue_finds_stuck_drafts(): void
    {
        $this->bootstrap();
        $this->insertInvoice(201, 'INV-202604-0001', 'draft',     Carbon::now()->subHours(48));
        $this->insertInvoice(202, 'INV-202604-0002', 'draft',     Carbon::now()->subHours(1));
        $this->insertInvoice(203, 'INV-202604-0003', 'finalized', Carbon::now()->subHours(48));

        $svc = app(ChainBottleneckService::class);
        $rows = $svc->detect('invoice_draft_overdue');

        $this->assertCount(1, $rows);
        $this->assertSame('INV-202604-0001', $rows[0]['doc_number']);
    }

    // ─── Helpers ───────────────────────────────────────────────────

    /**
     * Seed minimal role + user + customer so FK constraints pass.
     */
    private function bootstrap(): void
    {
        DB::table('roles')->insertOrIgnore([
            'id'         => 1,
            'name'       => 'Tester',
            'slug'       => 'tester',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        DB::table('users')->insertOrIgnore([
            'id'        => 1,
            'name'      => 'Test Creator',
            'email'     => 'creator@test.local',
            'password'  => bcrypt('Password1!'),
            'role_id'   => 1,
            'created_at'=> Carbon::now(),
            'updated_at'=> Carbon::now(),
        ]);
        DB::table('customers')->insertOrIgnore([
            'id'                 => 1,
            'name'               => 'Acme',
            'address'            => 'Test Street, Cavite',
            'is_active'          => true,
            'payment_terms_days' => 30,
            'created_at'         => Carbon::now(),
            'updated_at'         => Carbon::now(),
        ]);
    }

    private function insertSalesOrder(int $id, string $number, string $status, Carbon $updatedAt): void
    {
        DB::table('sales_orders')->insert([
            'id'                 => $id,
            'so_number'          => $number,
            'customer_id'        => 1,
            'date'               => Carbon::now()->toDateString(),
            'subtotal'           => '0.00',
            'vat_amount'         => '0.00',
            'total_amount'       => '0.00',
            'status'             => $status,
            'payment_terms_days' => 30,
            'created_by'         => 1,
            'created_at'         => $updatedAt,
            'updated_at'         => $updatedAt,
        ]);
    }

    private function insertInvoice(int $id, string $number, string $status, Carbon $updatedAt): void
    {
        DB::table('invoices')->insert([
            'id'             => $id,
            'invoice_number' => $number,
            'customer_id'    => 1,
            'date'           => Carbon::now()->toDateString(),
            'due_date'       => Carbon::now()->addDays(30)->toDateString(),
            'is_vatable'     => true,
            'subtotal'       => '0.00',
            'vat_amount'     => '0.00',
            'total_amount'   => '0.00',
            'amount_paid'    => '0.00',
            'balance'        => '0.00',
            'status'         => $status,
            'created_at'     => $updatedAt,
            'updated_at'     => $updatedAt,
        ]);
    }
}
