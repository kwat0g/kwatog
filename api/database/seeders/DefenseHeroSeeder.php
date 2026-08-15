<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\SupplyChain\Models\DeliveryProof;
use App\Modules\SupplyChain\Services\DeliveryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Track C — additive demo-hardening seeder (docs/superpowers/specs/
 * 2026-08-11-demo-hardening-design.md §2.2).
 *
 * Additive and immutable by contract: stable natural keys, guarded inserts,
 * no deletes, no truncation, no rewriting
 * an existing business document into a different state. Running it twice
 * leaves the database byte-identical on the second run.
 *
 * What it ensures:
 *   1. Hero AR invoice — a seeder-owned delivery with stable natural keys is
 *      confirmed through the real DeliveryService::confirm() handoff, which
 *      creates a draft invoice WITH delivery_id and invoice items (genuine
 *      provenance, not a fabricated invoice row).
 *   2. An open accounting period for the current year/month, so period locks
 *      have something to lock.
 *   3. Leave balances for every employee who has leave requests this year.
 *
 * USER-ONLY: never run this against a database you have not backed up.
 * Prefer: scripts/db-backup.sh → php artisan db:seed --class=DefenseHeroSeeder
 * → php artisan demo:verify.
 */
class DefenseHeroSeeder extends Seeder
{
    private const CUSTOMER_EMAIL = 'defense.hero.customer@ogami.test';

    private const PRODUCT_NUMBER = 'DEF-HERO-PROD-001';

    private const SALES_ORDER_NUMBER = 'SO-DEF-HERO-001';

    private const DELIVERY_NUMBER = 'DEL-DEF-HERO-001';

    public function run(): void
    {
        $this->command?->info('DefenseHeroSeeder: additive demo-hardening data (idempotent, never destructive).');

        $this->ensureOpenAccountingPeriod();
        $this->ensureLeaveBalances();
        $this->ensureHeroDeliveryInvoice();

        $this->command?->info('DefenseHeroSeeder complete.');
    }

    /** @return array{ok: bool, message: string} */
    private function ensureOpenAccountingPeriod(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('accounting_periods')) {
            $this->command?->warn('  accounting_periods table missing — skipped.');

            return;
        }

        $year  = (int) now()->format('Y');
        $month = (int) now()->format('n');
        $period = DB::table('accounting_periods')
            ->where('year', $year)
            ->where('month', $month)
            ->first();
        if ($period !== null) {
            $this->command?->info("  Accounting period {$year}-{$month} already exists ({$period->status}); left unchanged.");

            return;
        }

        $inserted = DB::table('accounting_periods')->insertOrIgnore([
            'year' => $year,
            'month' => $month,
            'status' => 'open',
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        if ($inserted === 0) {
            $this->command?->info("  Accounting period {$year}-{$month} was created concurrently; left unchanged.");

            return;
        }

        $this->command?->info("  Accounting period {$year}-{$month} created (open).");
    }

    /** @return array{ok: bool, message: string} */
    private function ensureLeaveBalances(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('employee_leave_balances')
            || ! \Illuminate\Support\Facades\Schema::hasTable('leave_requests')) {
            $this->command?->warn('  leave tables missing — skipped.');

            return;
        }

        $year = (int) now()->format('Y');
        $rows = DB::table('leave_requests as lr')
            ->join('leave_types as lt', 'lt.id', '=', 'lr.leave_type_id')
            ->whereYear('lr.start_date', $year)
            ->select('lr.employee_id', 'lr.leave_type_id', 'lt.default_balance')
            ->distinct()
            ->get();

        $created = 0;
        foreach ($rows as $row) {
            $exists = DB::table('employee_leave_balances')
                ->where('employee_id', $row->employee_id)
                ->where('leave_type_id', $row->leave_type_id)
                ->where('year', $year)
                ->exists();
            if ($exists) {
                continue;
            }

            $balance = (float) $row->default_balance;
            $created += DB::table('employee_leave_balances')->insertOrIgnore([
                'employee_id' => $row->employee_id,
                'leave_type_id' => $row->leave_type_id,
                'year' => $year,
                'total_credits' => $balance,
                'used' => 0,
                'remaining' => $balance,
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }

        $this->command?->info("  Leave balances ensured for {$rows->count()} employee/type pairs ({$created} new).");
    }

    /** @return array{ok: bool, message: string} */
    private function ensureHeroDeliveryInvoice(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('deliveries')) {
            $this->command?->warn('  deliveries table missing — skipped.');

            return;
        }

        $by = User::where('email', 'admin@ogami.test')->first() ?? User::orderBy('id')->first();
        if (! $by) {
            $this->command?->warn('  No actor available to confirm the hero delivery — skipped.');

            return;
        }

        $settings = app(SettingsService::class);
        $accountingEnabled = $settings->get('modules.accounting');
        if ($accountingEnabled === null) {
            $settings->set('modules.accounting', true, 'modules');
        } elseif ($accountingEnabled !== true) {
            $this->command?->warn('  Accounting is explicitly disabled; existing setting left unchanged and hero invoice skipped.');

            return;
        }

        $revenueCode = $settings->get('accounting.default_sales_revenue_account_code');
        if ($revenueCode === null) {
            $settings->set('accounting.default_sales_revenue_account_code', '4010', 'accounting');
        } elseif ($revenueCode !== '4010') {
            $this->command?->warn("  Existing default revenue account setting ({$revenueCode}) left unchanged; hero invoice skipped.");

            return;
        }

        $delivery = DB::transaction(fn (): Delivery => $this->createHeroDelivery($by));

        if ($delivery->invoice_id !== null || DB::table('invoices')->where('delivery_id', $delivery->id)->exists()) {
            $this->command?->info("  Hero invoice already exists for ".self::DELIVERY_NUMBER.'; left unchanged.');

            return;
        }

        $status = $delivery->status instanceof \BackedEnum ? $delivery->status->value : (string) $delivery->status;
        if ($status !== 'delivered') {
            $this->command?->warn("  Hero delivery is {$status}, not delivered; existing row left unchanged and confirmation skipped.");

            return;
        }

        try {
            app(DeliveryService::class)->confirm($delivery, $by, [
                'receiver_name'     => 'Maria Santos',
                'receiver_position' => 'Purchasing Officer',
                'delivery_remarks'  => 'Hero chain — confirmed delivery producing the draft invoice.',
            ]);

            $fresh = $delivery->fresh();
            $this->command?->info('  Hero invoice: draft created from confirmed delivery '.($fresh?->delivery_number ?? '?').' (delivery_id='.$delivery->id.').');
        } catch (\Throwable $e) {
            // Best-effort by contract — the handoff failure is visible through
            // demo:verify (delivery_to_invoice) and the delivery's own
            // invoice_handoff_status, never swallowed silently.
            \Illuminate\Support\Facades\Log::warning('DefenseHeroSeeder: hero invoice handoff failed', [
                'delivery_id' => $delivery->id,
                'error'       => $e->getMessage(),
            ]);
            $this->command?->warn('  Hero invoice handoff skipped: '.$e->getMessage());
        }
    }

    /**
     * Create only the seeder-owned, stable hero documents. Existing rows with
     * the same natural keys are reused but never rewritten.
     */
    private function createHeroDelivery(User $by): Delivery
    {
        DB::table('accounts')->insertOrIgnore([
            'code' => '4010',
            'name' => 'Sales Revenue',
            'type' => 'revenue',
            'normal_balance' => 'credit',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = Customer::firstOrCreate(
            ['email' => self::CUSTOMER_EMAIL],
            [
                'name' => 'Defense Hero Customer',
                'payment_terms_days' => 30,
                'is_active' => true,
            ],
        );

        $product = Product::firstOrCreate(
            ['part_number' => self::PRODUCT_NUMBER],
            [
                'name' => 'Defense Hero Product',
                'unit_of_measure' => 'pcs',
                'standard_cost' => '10.00',
                'is_active' => true,
            ],
        );

        $so = SalesOrder::firstOrCreate(
            ['so_number' => self::SALES_ORDER_NUMBER],
            [
                'customer_id' => $customer->id,
                'date' => today()->toDateString(),
                'subtotal' => '100.00',
                'vat_amount' => '12.00',
                'total_amount' => '112.00',
                'status' => 'confirmed',
                'payment_terms_days' => 30,
                'notes' => 'Stable defense hero chain.',
                'created_by' => $by->id,
            ],
        );
        if ((int) $so->customer_id !== (int) $customer->id) {
            throw new \RuntimeException(self::SALES_ORDER_NUMBER.' already belongs to another customer; no rows were changed.');
        }

        $soItem = SalesOrderItem::firstOrCreate(
            ['sales_order_id' => $so->id, 'product_id' => $product->id],
            [
                'quantity' => '10.00',
                'unit_price' => '10.00',
                'total' => '100.00',
                'delivery_date' => today()->addDays(7)->toDateString(),
            ],
        );

        $delivery = Delivery::firstOrCreate(
            ['delivery_number' => self::DELIVERY_NUMBER],
            [
                'sales_order_id' => $so->id,
                'status' => 'delivered',
                'scheduled_date' => today()->toDateString(),
                'delivered_at' => now(),
                'notes' => 'Stable defense hero chain.',
                'created_by' => $by->id,
            ],
        );
        if ((int) $delivery->sales_order_id !== (int) $so->id) {
            throw new \RuntimeException(self::DELIVERY_NUMBER.' already belongs to another sales order; no rows were changed.');
        }

        DeliveryItem::firstOrCreate(
            ['delivery_id' => $delivery->id, 'sales_order_item_id' => $soItem->id],
            ['quantity' => '10.000', 'unit_price' => '10.00'],
        );

        $imageBytes = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true);
        DeliveryProof::firstOrCreate(
            [
                'delivery_id' => $delivery->id,
                'proof_type' => 'signed_dr',
                'notes' => 'Demo — signed delivery receipt.',
            ],
            [
                'file_name' => 'signed_dr_defense_hero.gif',
                'file_path' => 'delivery-proofs/signed_dr_defense_hero.gif',
                'file_size' => strlen($imageBytes),
                'mime_type' => 'image/gif',
                'uploaded_by' => $by->id,
            ],
        );

        return $delivery->fresh();
    }
}
