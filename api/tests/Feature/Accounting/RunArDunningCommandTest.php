<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Mail\InvoiceDunningMail;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Services\ArDunningService;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RunArDunningCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->set('accounting.ar_dunning.enabled', true, 'accounting');
        app(SettingsService::class)->set('accounting.ar_dunning.tier_days_csv', '7,15,30', 'accounting');
    }

    public function test_sends_tier_email_for_invoice_8_days_overdue(): void
    {
        Mail::fake();

        $customer = Customer::factory()->create(['email' => 'pay@example.test']);
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'finalized',
            'due_date' => Carbon::now()->subDays(8)->toDateString(),
            'balance' => 1000,
            'last_dunning_tier' => 0,
        ]);

        $result = app(ArDunningService::class)->run();

        $this->assertSame(1, $result['sent']);
        $this->assertSame(0, $result['failed']);
        $mail = new InvoiceDunningMail($invoice->fresh('customer'), 7, 8);
        $html = $mail->render();
        $mail->assertSeeInText('/portal/customer/invoices/'.$invoice->hash_id);
        $this->assertStringContainsString('₱1,000.00', $html);
        $this->assertStringContainsString('Balance due when reminder was generated', $html);
        $this->assertStringContainsString('Review invoice', $html);
        $this->assertStringContainsString('Payment reminder: '.$invoice->invoice_number.' · ₱1,000.00 · 8 days overdue', $mail->envelope()->subject);
        $this->assertSame(7, $invoice->fresh()->last_dunning_tier);
    }

    public function test_dunning_copy_does_not_use_fixed_tier_language(): void
    {
        Mail::fake();
        $customer = Customer::factory()->create(['email' => 'pay@example.test']);
        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'finalized',
            'due_date' => Carbon::now()->subDays(30)->toDateString(),
            'balance' => 1000,
            'last_dunning_tier' => 15,
        ]);

        app(ArDunningService::class)->run();

        Mail::assertQueued(InvoiceDunningMail::class, function (InvoiceDunningMail $mail): bool {
            $html = $mail->render();
            $this->assertStringContainsString('30 days overdue', $html);
            $this->assertStringNotContainsString('30+ days overdue', $html);
            $this->assertStringNotContainsString('immediately', $html);
            $this->assertStringNotContainsString('avoid further escalation', $html);

            return true;
        });
    }

    public function test_queued_reminder_keeps_scan_time_recipient_and_invoice_values(): void
    {
        Mail::fake();
        $customer = Customer::factory()->create([
            'email' => 'pay-at-scan@example.test',
            'name' => 'Customer at scan',
            'contact_person' => null,
        ]);
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-DUNNING-SNAPSHOT',
            'status' => 'finalized',
            'date' => '2026-09-01',
            'due_date' => Carbon::now()->subDays(8)->toDateString(),
            'balance' => '1234.56',
            'last_dunning_tier' => 0,
        ]);

        app(ArDunningService::class)->run();

        $customer->update([
            'email' => 'pay-after-scan@example.test',
            'name' => 'Changed customer',
        ]);
        $invoice->update([
            'invoice_number' => 'INV-CHANGED',
            'balance' => '9999.00',
            'date' => '2026-12-31',
        ]);

        Mail::assertQueued(InvoiceDunningMail::class, function (InvoiceDunningMail $mail): bool {
            $mail->invoice->refresh();
            $mail->invoice->load('customer');
            $html = $mail->render();

            $this->assertTrue($mail->hasTo('pay-at-scan@example.test'));
            $this->assertFalse($mail->hasTo('pay-after-scan@example.test'));
            $this->assertStringContainsString('Customer at scan', $html);
            $this->assertStringNotContainsString('Changed customer', $html);
            $this->assertStringContainsString('INV-DUNNING-SNAPSHOT', $html);
            $this->assertStringNotContainsString('INV-CHANGED', $html);
            $this->assertStringContainsString('₱1,234.56', $html);
            $this->assertStringNotContainsString('9,999.00', $html);
            $this->assertStringContainsString('Sep 1, 2026', $html);

            return true;
        });
    }

    public function test_does_not_resend_lower_tier_when_higher_already_sent(): void
    {
        Mail::fake();

        $customer = Customer::factory()->create(['email' => 'pay@example.test']);
        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'finalized',
            'due_date' => Carbon::now()->subDays(8)->toDateString(),
            'balance' => 1000,
            'last_dunning_tier' => 30, // already escalated
        ]);

        app(ArDunningService::class)->run();

        Mail::assertNothingQueued();
    }

    public function test_repeated_scan_does_not_enqueue_the_same_tier_twice(): void
    {
        Mail::fake();

        $customer = Customer::factory()->create(['email' => 'pay@example.test']);
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'finalized',
            'due_date' => Carbon::now()->subDays(8)->toDateString(),
            'balance' => 1000,
            'last_dunning_tier' => 0,
        ]);

        $service = app(ArDunningService::class);
        $service->run();
        $service->run();

        Mail::assertQueued(InvoiceDunningMail::class, 1);
        $this->assertSame(7, $invoice->fresh()->last_dunning_tier);
    }

    public function test_paid_invoice_is_skipped(): void
    {
        Mail::fake();

        $customer = Customer::factory()->create(['email' => 'pay@example.test']);
        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'paid',
            'due_date' => Carbon::now()->subDays(40)->toDateString(),
            'balance' => 0,
            'last_dunning_tier' => 0,
        ]);

        app(ArDunningService::class)->run();

        Mail::assertNothingQueued();
    }

    public function test_feature_flag_off_disables(): void
    {
        Mail::fake();
        app(SettingsService::class)
            ->set('accounting.ar_dunning.enabled', false, 'accounting');
        Cache::forget('settings:accounting.ar_dunning.enabled');

        $customer = Customer::factory()->create(['email' => 'pay@example.test']);
        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'finalized',
            'due_date' => Carbon::now()->subDays(40)->toDateString(),
            'balance' => 1000,
            'last_dunning_tier' => 0,
        ]);

        app(ArDunningService::class)->run();

        Mail::assertNothingQueued();
    }

    public function test_missing_customer_email_creates_an_ar_in_app_fallback(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Mail::fake();

        $officer = User::factory()->withRole('finance_officer')->create();
        $customer = Customer::factory()->create(['email' => null]);
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'finalized',
            'due_date' => Carbon::now()->subDays(8)->toDateString(),
            'balance' => 1000,
            'last_dunning_tier' => 0,
        ]);

        $result = app(ArDunningService::class)->run();

        $this->assertSame(1, $result['blocked']);
        Mail::assertNothingQueued();
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $officer->id,
            'type' => 'email.delivery_failed',
        ]);
        $this->assertSame(0, (int) $invoice->fresh()->last_dunning_tier);
    }

    public function test_invalid_customer_email_is_blocked_without_attempting_delivery(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Mail::fake();

        $officer = User::factory()->withRole('finance_officer')->create();
        $customer = Customer::factory()->create(['email' => 'not-an-email']);
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'finalized',
            'due_date' => Carbon::now()->subDays(8)->toDateString(),
            'balance' => 1000,
            'last_dunning_tier' => 0,
        ]);

        $result = app(ArDunningService::class)->run();

        $this->assertSame(1, $result['blocked']);
        $this->assertSame(0, $result['failed']);
        Mail::assertNothingQueued();
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $officer->id,
            'type' => 'email.delivery_failed',
        ]);
        $this->assertSame(0, (int) $invoice->fresh()->last_dunning_tier);
    }

    public function test_queued_customer_reminder_failure_creates_an_ar_in_app_fallback(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $officer = User::factory()->withRole('finance_officer')->create();
        $customer = Customer::factory()->create(['email' => 'customer@example.test']);
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'finalized',
            'due_date' => Carbon::now()->subDays(8)->toDateString(),
            'balance' => 1000,
        ]);

        (new InvoiceDunningMail($invoice, 7, 8, [$officer->id]))
            ->failed(new \RuntimeException('Mail delivery failed'));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $officer->id,
            'type' => 'email.delivery_failed',
        ]);
    }
}
