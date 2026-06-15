<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Mail\InvoiceDunningMail;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ArDunningService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Scan overdue invoices and send tiered reminder emails.
     *
     * @return array{evaluated:int, sent:int, skipped:int}
     */
    public function run(?Carbon $asOf = null): array
    {
        if (! (bool) $this->settings->get('accounting.ar_dunning.enabled', true)) {
            return ['evaluated' => 0, 'sent' => 0, 'skipped' => 0];
        }

        $today = ($asOf ?? Carbon::now())->startOfDay();
        $tiers = $this->loadTiers();
        if (empty($tiers)) return ['evaluated' => 0, 'sent' => 0, 'skipped' => 0];

        $evaluated = 0;
        $sent = 0;
        $skipped = 0;

        $invoices = Invoice::query()
            ->with('customer:id,name,email,contact_person')
            ->whereIn('status', [InvoiceStatus::Finalized, InvoiceStatus::Partial])
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today->toDateString())
            ->orderBy('id')
            ->get();

        foreach ($invoices as $invoice) {
            $evaluated++;
            try {
                $daysOverdue = (int) Carbon::parse($invoice->due_date)->diffInDays($today, false);
                $tier = $this->selectTier($daysOverdue, (int) $invoice->last_dunning_tier, $tiers);
                if ($tier === null) {
                    $skipped++;
                    continue;
                }

                $email = $invoice->customer?->email;
                if (! $email) {
                    $skipped++;
                    continue;
                }

                Mail::to($email)->queue(new InvoiceDunningMail($invoice, $tier, $daysOverdue));

                $invoice->forceFill([
                    'last_dunning_tier' => $tier,
                    'last_dunning_at'   => now(),
                ])->saveQuietly();

                if ($tier === max($tiers)) {
                    $this->notifyArOfficers($invoice, $daysOverdue);
                }

                $sent++;
            } catch (\Throwable $e) {
                $skipped++;
                Log::warning('ArDunning failed for invoice', [
                    'invoice_id' => $invoice->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return compact('evaluated', 'sent', 'skipped');
    }

    /**
     * Select the highest tier crossed-but-not-yet-sent. Pure for testability.
     *
     * @param array<int, int> $tiersDesc tier days, descending
     */
    public function selectTier(int $daysOverdue, int $lastTier, array $tiersDesc): ?int
    {
        foreach ($tiersDesc as $tier) {
            if ($daysOverdue >= $tier && $lastTier < $tier) {
                return $tier;
            }
        }
        return null;
    }

    /** @return array<int, int> tier days, descending */
    private function loadTiers(): array
    {
        $csv = (string) $this->settings->get('accounting.ar_dunning.tier_days_csv', '7,15,30');
        $tiers = array_map('intval', array_filter(array_map('trim', explode(',', $csv))));
        $tiers = array_values(array_unique(array_filter($tiers, fn ($t) => $t > 0)));
        rsort($tiers);
        return $tiers;
    }

    private function notifyArOfficers(Invoice $invoice, int $daysOverdue): void
    {
        $officers = User::query()
            ->whereHas('role.permissions', fn ($q) => $q->where('slug', 'accounting.invoices.view'))
            ->where('is_active', true)
            ->get();
        if ($officers->isEmpty()) return;

        $this->notifications->send($officers, 'ar.dunning.escalation', [
            'title'   => 'AR Escalation — Invoice 30+ days overdue',
            'message' => "Invoice {$invoice->invoice_number} for ".
                ($invoice->customer?->name ?? 'unknown customer').
                " is {$daysOverdue} days overdue (₱".number_format((float) $invoice->balance, 2).").",
            'link_to' => '/accounting/invoices',
        ]);
    }
}
