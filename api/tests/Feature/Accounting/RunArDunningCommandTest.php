<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Mail\InvoiceDunningMail;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Services\ArDunningService;
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
        Cache::forget('settings:accounting.ar_dunning.enabled');
        Cache::forget('settings:accounting.ar_dunning.tier_days_csv');
    }

    public function test_sends_tier_email_for_invoice_8_days_overdue(): void
    {
        Mail::fake();

        $customer = Customer::factory()->create(['email' => 'pay@example.test']);
        $invoice  = Invoice::factory()->create([
            'customer_id'       => $customer->id,
            'status'            => 'finalized',
            'due_date'          => Carbon::now()->subDays(8)->toDateString(),
            'balance'           => 1000,
            'last_dunning_tier' => 0,
        ]);

        app(ArDunningService::class)->run();

        Mail::assertQueued(InvoiceDunningMail::class, 1);
        Mail::assertQueued(
            InvoiceDunningMail::class,
            fn (InvoiceDunningMail $m) => $m->hasTo('pay@example.test') && $m->tier === 7,
        );
        $this->assertSame(7, $invoice->fresh()->last_dunning_tier);
    }

    public function test_does_not_resend_lower_tier_when_higher_already_sent(): void
    {
        Mail::fake();

        $customer = Customer::factory()->create(['email' => 'pay@example.test']);
        Invoice::factory()->create([
            'customer_id'       => $customer->id,
            'status'            => 'finalized',
            'due_date'          => Carbon::now()->subDays(8)->toDateString(),
            'balance'           => 1000,
            'last_dunning_tier' => 30, // already escalated
        ]);

        app(ArDunningService::class)->run();

        Mail::assertNothingQueued();
    }

    public function test_paid_invoice_is_skipped(): void
    {
        Mail::fake();

        $customer = Customer::factory()->create(['email' => 'pay@example.test']);
        Invoice::factory()->create([
            'customer_id'       => $customer->id,
            'status'            => 'paid',
            'due_date'          => Carbon::now()->subDays(40)->toDateString(),
            'balance'           => 0,
            'last_dunning_tier' => 0,
        ]);

        app(ArDunningService::class)->run();

        Mail::assertNothingQueued();
    }

    public function test_feature_flag_off_disables(): void
    {
        Mail::fake();
        app(\App\Common\Services\SettingsService::class)
            ->set('accounting.ar_dunning.enabled', false, 'accounting');
        Cache::forget('settings:accounting.ar_dunning.enabled');

        $customer = Customer::factory()->create(['email' => 'pay@example.test']);
        Invoice::factory()->create([
            'customer_id'       => $customer->id,
            'status'            => 'finalized',
            'due_date'          => Carbon::now()->subDays(40)->toDateString(),
            'balance'           => 1000,
            'last_dunning_tier' => 0,
        ]);

        app(ArDunningService::class)->run();

        Mail::assertNothingQueued();
    }
}
