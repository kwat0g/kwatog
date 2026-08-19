<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Common\Enums\AlertSeverity;
use App\Common\Enums\AlertType;
use App\Common\Models\Alert;
use App\Common\Services\AlertEngineService;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The AR overdue bands are chosen by comparing days-past-due against the
 * configured thresholds (warning 30, critical 60 — migration 0290).
 *
 * Carbon 3 made diffIn* signed, and the comparison read
 * `$today->diffInDays($dueDate)` — receiver later, argument earlier — so an
 * overdue invoice produced a NEGATIVE day count. `-75 >= 60` is false, so the
 * critical band was unreachable: every overdue invoice, however old, fell to
 * the warning branch. The rendered message and the `days_overdue` metadata
 * also carried the negative number.
 *
 * The query that feeds this comparison already filters
 * `due_date < today - warningDays`, so every row reaching it is overdue. That
 * is what made the critical alert structurally incapable of firing.
 */
class ArOverdueAlertBandTest extends TestCase
{
    use RefreshDatabase;

    private function overdueInvoice(int $daysPastDue): Invoice
    {
        $invoice = Invoice::factory()->create([
            'due_date' => Carbon::today()->subDays($daysPastDue)->toDateString(),
        ]);
        $invoice->forceFill(['status' => InvoiceStatus::Finalized->value])->save();

        return $invoice;
    }

    public function test_an_invoice_past_the_critical_threshold_raises_a_critical_alert(): void
    {
        $invoice = $this->overdueInvoice(75);

        app(AlertEngineService::class)->runAllChecks();

        $alert = Alert::query()
            ->where('entity_type', $invoice->getMorphClass())
            ->where('entity_id', $invoice->getKey())
            ->first();

        $this->assertNotNull($alert, 'an overdue invoice must raise an AR alert at all');
        $this->assertSame(AlertType::ArOverdue60, $alert->type, '75 days past due is beyond the 60-day critical threshold');
        $this->assertSame(AlertSeverity::Critical, $alert->severity);
        $this->assertSame(75, $alert->metadata['days_overdue'], 'days_overdue must be a positive magnitude');
    }

    public function test_an_invoice_between_the_thresholds_stays_a_warning(): void
    {
        // Guards against over-correcting: 45 days is past warning (30) but short
        // of critical (60), so it must remain a warning.
        $invoice = $this->overdueInvoice(45);

        app(AlertEngineService::class)->runAllChecks();

        $alert = Alert::query()
            ->where('entity_type', $invoice->getMorphClass())
            ->where('entity_id', $invoice->getKey())
            ->first();

        $this->assertNotNull($alert);
        $this->assertSame(AlertType::ArOverdue30, $alert->type);
        $this->assertSame(AlertSeverity::Warning, $alert->severity);
        $this->assertSame(45, $alert->metadata['days_overdue']);
    }
}
