<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Support\Money;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Resources\CustomerResource;
use App\Modules\Accounting\Services\CustomerService;
use App\Modules\Accounting\Services\InvoiceService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M028 accounts-receivable — hardening regressions from the 2026-08-30 re-audit.
 *
 * Each case below was measured red against the source at that commit:
 *
 *  - a customer whose `credit_limit` is 0.00 raised DivisionByZeroError out of
 *    CustomerResource, i.e. HTTP 500 on the whole customer list;
 *  - AR aging raised "Attempt to read property on null" once any customer with
 *    invoices was archived, taking down both the report and the finance
 *    dashboard, which calls aging() on every load;
 *  - AR money fields accepted `1.999` (stored as 2.00, a figure the operator
 *    never entered) and turned `1e3` / `1e17` into HTTP 500 out of BCMath and
 *    PostgreSQL respectively.
 *
 * The aging-boundary case is a pass-either-way lock: it held before the audit
 * and is pinned here so the bucket edges cannot drift silently.
 */
class AccountsReceivableHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Finance',
            'email' => 'ar_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function customer(array $attrs = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'Acme '.substr(uniqid(), -5),
            'code' => 'C-T-'.substr(uniqid(), -5),
            'payment_terms_days' => 30,
        ], $attrs));
    }

    private function revenueAccountHashId(): string
    {
        return Account::query()->where('code', '4010')->firstOrFail()->hash_id;
    }

    private function finalizedInvoice(
        User $by,
        Customer $customer,
        string $unitPrice = '1000.00',
        string $date = '2026-04-01',
        string $dueDate = '2026-04-30',
    ): Invoice {
        $service = app(InvoiceService::class);
        $draft = $service->create([
            'customer_id' => $customer->hash_id,
            'lifecycle_type' => 'prebill',
            'prebill_reason' => 'AR hardening fixture',
            'date' => $date,
            'due_date' => $dueDate,
            'vat_classification' => 'vat_exempt',
            'items' => [[
                'revenue_account_id' => $this->revenueAccountHashId(),
                'description' => 'Wiper bushings',
                'quantity' => '1',
                'unit_price' => $unitPrice,
            ]],
        ], $by);

        return $service->finalize($draft, $by);
    }

    /**
     * A zero credit limit means "no limit is enforced" (SalesOrderService::
     * checkCreditLimit). CustomerResource detected that with `!== '0'`, but the
     * model's decimal:2 cast renders a stored 0.00 as '0.00', so the guard
     * passed and the warning ratio divided by zero.
     */
    public function test_customer_resource_survives_a_zero_credit_limit(): void
    {
        $user = $this->admin();
        $customer = $this->customer(['credit_limit' => '0.00']);
        $this->finalizedInvoice($user, $customer, '500.00');

        $listed = app(CustomerService::class)->list([])->firstOrFail();
        $payload = (new CustomerResource($listed))->toArray(request());

        $this->assertSame('500.00', $payload['credit_used']);
        $this->assertNull($payload['credit_available'], 'a zero limit exposes no availability');
        $this->assertFalse($payload['credit_warning'], 'a zero limit cannot breach a warning ratio');
    }

    public function test_customer_list_and_show_do_not_500_on_a_zero_credit_limit(): void
    {
        $user = $this->admin();
        $customer = $this->customer(['credit_limit' => '0.00']);
        $this->finalizedInvoice($user, $customer, '500.00');

        $this->actingAs($user)->getJson('/api/v1/customers?per_page=5')->assertOk();
        $this->actingAs($user)->getJson('/api/v1/customers/'.$customer->hash_id)->assertOk();
    }

    /**
     * `customers` soft-deletes; `invoices` does not, and invoices.customer_id is
     * NOT NULL behind a RESTRICT FK. Under the default scope an archived
     * customer resolved to null and aging() dereferenced it.
     */
    public function test_ar_aging_survives_an_archived_customer(): void
    {
        $user = $this->admin();
        $customer = $this->customer();
        $this->finalizedInvoice($user, $customer);

        DB::table('customers')->where('id', $customer->id)->update(['deleted_at' => now()]);

        $aging = app(InvoiceService::class)->aging(Carbon::parse('2026-04-30'));

        $this->assertSame(0, Money::cmp('1000.00', $aging['buckets']['total']), 'the receivable is still owed');
        $this->assertCount(1, $aging['by_customer']);
        $this->assertSame($customer->hash_id, $aging['by_customer'][0]['customer_id']);
        $this->assertSame($customer->name, $aging['by_customer'][0]['customer_name']);
    }

    public function test_ar_aging_report_does_not_500_on_an_archived_customer(): void
    {
        $user = $this->admin();
        $customer = $this->customer();
        $this->finalizedInvoice($user, $customer);
        DB::table('customers')->where('id', $customer->id)->update(['deleted_at' => now()]);

        $this->actingAs($user)
            ->getJson('/api/v1/accounting/statements/ar-aging?as_of=2026-04-30')
            ->assertOk();
    }

    /**
     * The centavo contract on the invoice write path. `numeric` alone accepted
     * anything is_numeric() liked and the service then ran it through
     * Money::round2(), so over-precision was silently applied and scientific
     * notation / overflow became a 500 rather than a 422.
     */
    public function test_invoice_money_fields_reject_over_precision_and_scientific_notation(): void
    {
        $user = $this->admin();
        $customer = $this->customer();

        foreach (['1.999', '1e3', '1e17', '99999999999999999.99'] as $unitPrice) {
            $this->actingAs($user)->postJson('/api/v1/invoices', [
                'customer_id' => $customer->hash_id,
                'lifecycle_type' => 'prebill',
                'prebill_reason' => 'centavo contract',
                'date' => '2026-04-01',
                'due_date' => '2026-04-30',
                'vat_classification' => 'vat_exempt',
                'items' => [[
                    'revenue_account_id' => $this->revenueAccountHashId(),
                    'description' => 'Wiper bushings',
                    'quantity' => '1',
                    'unit_price' => $unitPrice,
                ]],
            ])->assertStatus(422)->assertJsonValidationErrors('items.0.unit_price');
        }

        $this->assertSame(0, Invoice::query()->count(), 'no invoice may be created from a refused amount');
    }

    public function test_collection_amount_rejects_the_same_shapes(): void
    {
        $user = $this->admin();
        $invoice = $this->finalizedInvoice($user, $this->customer());
        $cashAccount = Account::query()->where('code', '1010')->firstOrFail()->hash_id;

        foreach (['1.999', '1e3', '1e17'] as $amount) {
            $this->actingAs($user)
                ->postJson('/api/v1/invoices/'.$invoice->hash_id.'/collections', [
                    'cash_account_id' => $cashAccount,
                    'collection_date' => '2026-04-05',
                    'amount' => $amount,
                    'payment_method' => 'cash',
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('amount');
        }

        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(0, Money::cmp('1000.00', (string) $invoice->fresh()->balance));
    }

    public function test_credit_note_line_amount_rejects_the_same_shapes(): void
    {
        $user = $this->admin();
        $customer = $this->customer();

        foreach (['1.999', '1e3', '1e17'] as $amount) {
            $this->actingAs($user)->postJson('/api/v1/accounting/credit-notes', [
                'type' => 'customer',
                'date' => '2026-04-10',
                'is_vatable' => false,
                'customer_id' => $customer->hash_id,
                'lines' => [[
                    'account_id' => $this->revenueAccountHashId(),
                    'description' => 'Returned goods',
                    'amount' => $amount,
                ]],
            ])->assertStatus(422)->assertJsonValidationErrors('lines.0.amount');
        }

        $this->assertSame(0, DB::table('credit_notes')->count());
    }

    /**
     * Pass-either-way lock. These edges held before the re-audit; pinning them
     * means a change to agingBucketAt() cannot move an invoice between buckets
     * or break the buckets-sum-to-total identity without a red test.
     */
    public function test_aging_buckets_are_exact_and_non_overlapping_at_every_boundary(): void
    {
        $user = $this->admin();
        // Due 2026-04-01, so each as-of below is an exact day count past due.
        $this->finalizedInvoice($user, $this->customer(), '100.00', '2026-03-01', '2026-04-01');
        $service = app(InvoiceService::class);

        $expected = [
            '2026-04-01' => 'current',   // due today is not yet overdue
            '2026-04-02' => 'd1_30',
            '2026-05-01' => 'd1_30',     // exactly 30 days
            '2026-05-02' => 'd31_60',    // exactly 31 days
            '2026-05-31' => 'd31_60',    // exactly 60 days
            '2026-06-01' => 'd61_90',    // exactly 61 days
            '2026-06-30' => 'd61_90',    // exactly 90 days
            '2026-07-01' => 'd91_plus',  // exactly 91 days
        ];
        $allBuckets = ['current', 'd1_30', 'd31_60', 'd61_90', 'd91_plus'];

        foreach ($expected as $asOf => $bucket) {
            $buckets = $service->aging(Carbon::parse($asOf))['buckets'];

            $this->assertSame(0, Money::cmp('100.00', $buckets[$bucket]), "as_of {$asOf} belongs in {$bucket}");
            foreach (array_diff($allBuckets, [$bucket]) as $empty) {
                $this->assertTrue(Money::isZero($buckets[$empty]), "as_of {$asOf} must not also land in {$empty}");
            }
            $this->assertSame(
                0,
                Money::cmp(Money::add(...array_map(static fn (string $b): string => $buckets[$b], $allBuckets)), $buckets['total']),
                "as_of {$asOf}: buckets must sum to total",
            );
        }
    }

    /**
     * Pass-either-way lock on the payment-application invariants that held: an
     * overpayment is refused, a settled invoice takes no further cash, and the
     * stored balance equals total - collections - credit applications.
     */
    public function test_payment_application_cannot_drive_a_balance_negative(): void
    {
        $user = $this->admin();
        $invoice = $this->finalizedInvoice($user, $this->customer());
        $cashAccount = Account::query()->where('code', '1010')->firstOrFail()->hash_id;
        $collect = fn (string $amount) => $this->actingAs($user)
            ->postJson('/api/v1/invoices/'.$invoice->fresh()->hash_id.'/collections', [
                'cash_account_id' => $cashAccount,
                'collection_date' => '2026-04-05',
                'amount' => $amount,
                'payment_method' => 'cash',
            ]);

        $collect('1500.00')->assertStatus(422);
        $collect('600.00')->assertStatus(201);
        $collect('600.00')->assertStatus(422);
        $collect('400.00')->assertStatus(201);
        $collect('0.01')->assertStatus(422);

        $fresh = $invoice->fresh();
        $sql = DB::selectOne(
            'select i.total_amount,
                    coalesce((select sum(c.amount) from collections c where c.invoice_id = i.id), 0) as paid,
                    coalesce((select sum(a.amount) from credit_note_applications a where a.invoice_id = i.id), 0) as credited
             from invoices i where i.id = ?',
            [$invoice->id],
        );

        $this->assertSame(InvoiceStatus::Paid, $fresh->status);
        $this->assertSame(0, Money::cmp('0.00', (string) $fresh->balance));
        $this->assertSame(
            0,
            Money::cmp(
                (string) $fresh->balance,
                Money::sub((string) $sql->total_amount, Money::add((string) $sql->paid, (string) $sql->credited)),
            ),
            'stored balance must equal total - collections - credit applications',
        );
    }
}
