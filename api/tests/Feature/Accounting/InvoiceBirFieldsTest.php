<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Enums\VatClassification;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Services\InvoiceService;
use App\Modules\Accounting\Services\OfficialReceiptService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OGAMI-008 — VAT classification (vatable / zero-rated / exempt), Senior/PWD
 * discount, and Official Receipt issuance.
 */
class InvoiceBirFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    private function user(): User
    {
        $roleId = Role::query()->where('slug', 'system_admin')->value('id');
        return User::create([
            'name' => 'Fin', 'email' => 'f_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'), 'role_id' => $roleId,
        ]);
    }

    private function acct(string $code): string
    {
        return Account::query()->where('code', $code)->firstOrFail()->hash_id;
    }

    private function makeInvoice(InvoiceService $svc, User $u, array $overrides = [])
    {
        $customer = Customer::create(['name' => 'Toyota PH', 'payment_terms_days' => 30]);

        return $svc->create(array_merge([
            'customer_id' => $customer->hash_id,
            'date'        => '2026-04-01',
            'due_date'    => '2026-04-30',
            'items'       => [[
                'revenue_account_id' => $this->acct('4010'),
                'description'        => 'Wiper bushings',
                'quantity'           => '10',
                'unit_price'         => '1000.00',
            ]],
        ], $overrides), $u);
    }

    public function test_vatable_invoice_charges_12_percent(): void
    {
        $svc = app(InvoiceService::class);
        $inv = $this->makeInvoice($svc, $this->user(), [
            'vat_classification' => VatClassification::Vatable->value,
        ]);

        // 10 × 1000 = 10000 subtotal; VAT 12% = 1200; total = 11200
        $this->assertSame('10000.00', (string) $inv->subtotal);
        $this->assertSame('1200.00', (string) $inv->vat_amount);
        $this->assertSame('11200.00', (string) $inv->total_amount);
    }

    public function test_zero_rated_invoice_has_no_vat(): void
    {
        $svc = app(InvoiceService::class);
        $inv = $this->makeInvoice($svc, $this->user(), [
            'vat_classification' => VatClassification::ZeroRated->value,
        ]);

        $this->assertSame('10000.00', (string) $inv->subtotal);
        $this->assertSame('0.00', (string) $inv->vat_amount);
        $this->assertSame('10000.00', (string) $inv->total_amount);
        $this->assertFalse((bool) $inv->is_vatable);
    }

    public function test_vat_exempt_invoice_has_no_vat(): void
    {
        $svc = app(InvoiceService::class);
        $inv = $this->makeInvoice($svc, $this->user(), [
            'vat_classification' => VatClassification::VatExempt->value,
        ]);

        $this->assertSame('0.00', (string) $inv->vat_amount);
        $this->assertSame('10000.00', (string) $inv->total_amount);
    }

    public function test_senior_pwd_discount_reduces_total(): void
    {
        $svc = app(InvoiceService::class);
        $inv = $this->makeInvoice($svc, $this->user(), [
            'vat_classification'  => VatClassification::Vatable->value,
            'senior_pwd_discount' => '500.00',
        ]);

        // net base = 10000 - 500 = 9500; VAT 12% = 1140; total = 10640
        $this->assertSame('500.00', (string) $inv->senior_pwd_discount);
        $this->assertSame('1140.00', (string) $inv->vat_amount);
        $this->assertSame('10640.00', (string) $inv->total_amount);
    }

    public function test_official_receipt_issued_for_invoice(): void
    {
        $svc   = app(InvoiceService::class);
        $orSvc = app(OfficialReceiptService::class);
        $user  = $this->user();

        $inv = $this->makeInvoice($svc, $user, [
            'vat_classification' => VatClassification::Vatable->value,
        ]);

        $or = $orSvc->issueForInvoice($inv, '11200.00', $user);

        $this->assertStringStartsWith('OR-', $or->or_number);
        $this->assertSame('11200.00', (string) $or->amount);
        $this->assertSame($inv->id, $or->invoice_id);
        $this->assertDatabaseHas('official_receipts', ['or_number' => $or->or_number]);
    }
}
