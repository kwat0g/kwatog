<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\CurrencyDisplayService;
use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Mail\InvoiceDunningMail;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
     * @return array{evaluated:int, sent:int, skipped:int, blocked:int, failed:int}
     */
    public function run(?Carbon $asOf = null): array
    {
        if (! $this->settings->requiredBool('accounting.ar_dunning.enabled')) {
            return ['evaluated' => 0, 'sent' => 0, 'skipped' => 0, 'blocked' => 0, 'failed' => 0];
        }

        $today = ($asOf ?? Carbon::now())->startOfDay();
        $tiers = $this->loadTiers();
        if (empty($tiers)) {
            return ['evaluated' => 0, 'sent' => 0, 'skipped' => 0, 'blocked' => 0, 'failed' => 0];
        }

        $evaluated = 0;
        $sent = 0;
        $skipped = 0;
        $blocked = 0;
        $failed = 0;

        $invoices = Invoice::query()
            ->with('customer:id,name,email,contact_person')
            ->whereIn('status', [InvoiceStatus::Finalized, InvoiceStatus::Partial])
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today->toDateString())
            ->orderBy('id')
            ->get();

        foreach ($invoices as $invoice) {
            $evaluated++;
            $emailFailure = null;
            try {
                $outcome = DB::transaction(function () use ($invoice, $today, $tiers, &$emailFailure): string {
                    // Two scheduler instances can select the same invoice
                    // before either has persisted last_dunning_tier. Re-read
                    // and lock the authoritative row so only one tier claim
                    // can enqueue a reminder.
                    $locked = Invoice::query()
                        ->with('customer:id,name,email,contact_person')
                        ->lockForUpdate()
                        ->find($invoice->id);

                    if (! $locked
                        || ! in_array($locked->status, [InvoiceStatus::Finalized, InvoiceStatus::Partial], true)
                        || $locked->due_date === null
                        || Carbon::parse($locked->due_date)->gte($today)) {
                        return 'skipped';
                    }

                    $daysOverdue = (int) Carbon::parse($locked->due_date)->diffInDays($today, false);
                    $tier = $this->selectTier($daysOverdue, (int) $locked->last_dunning_tier, $tiers);
                    if ($tier === null) {
                        return 'skipped';
                    }

                    $email = $locked->customer?->email;
                    if (! $email) {
                        Log::warning('ArDunning blocked: invoice customer has no email.', [
                            'invoice_id' => $locked->id,
                        ]);

                        $this->notifyArEmailFailure(
                            $locked,
                            $daysOverdue,
                            'The customer has no usable email address. Contact the customer through an approved channel.',
                        );

                        return 'blocked';
                    }

                    try {
                        Mail::to($email)->queue(new InvoiceDunningMail(
                            $locked,
                            $tier,
                            $daysOverdue,
                            $this->arOfficerIds(),
                        ));
                    } catch (\Throwable $e) {
                        // The transaction must roll back the tier claim. The
                        // fallback is sent after rollback in the outer catch.
                        $emailFailure = [
                            'invoice_id' => (int) $locked->id,
                            'days_overdue' => $daysOverdue,
                        ];
                        throw $e;
                    }

                    $locked->forceFill([
                        'last_dunning_tier' => $tier,
                        'last_dunning_at' => now(),
                    ])->saveQuietly();

                    if ($tier === max($tiers)) {
                        $this->notifyArOfficers($locked, $daysOverdue);
                    }

                    return 'sent';
                });

                match ($outcome) {
                    'sent' => $sent++,
                    'blocked' => $blocked++,
                    default => $skipped++,
                };
            } catch (\Throwable $e) {
                $failed++;
                if (is_array($emailFailure)) {
                    $failedInvoice = $invoice->fresh('customer') ?? $invoice;
                    $this->notifyArEmailFailure(
                        $failedInvoice,
                        (int) $emailFailure['days_overdue'],
                        'The reminder could not be accepted by the email provider. Contact the customer through an approved channel.',
                    );
                }
                Log::warning('ArDunning failed for invoice', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return compact('evaluated', 'sent', 'skipped', 'blocked', 'failed');
    }

    /**
     * Select the highest tier crossed-but-not-yet-sent. Pure for testability.
     *
     * @param  array<int, int>  $tiersDesc  tier days, descending
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
        $csv = $this->settings->requiredString('accounting.ar_dunning.tier_days_csv');
        $tiers = array_map('intval', array_filter(array_map('trim', explode(',', $csv))));
        $tiers = array_values(array_unique(array_filter($tiers, fn ($t) => $t > 0)));
        if ($tiers === []) {
            throw new BusinessRuleException('Required setting accounting.ar_dunning.tier_days_csv is invalid.');
        }
        rsort($tiers);

        return $tiers;
    }

    private function notifyArOfficers(Invoice $invoice, int $daysOverdue): void
    {
        $officers = User::query()
            ->whereHas('role.permissions', fn ($q) => $q->where('slug', 'accounting.invoices.view'))
            ->where('is_active', true)
            ->get();
        if ($officers->isEmpty()) {
            return;
        }

        $this->notifications->send($officers, 'ar.dunning.escalation', [
            'title' => 'AR Escalation — Highest dunning tier reached',
            'message' => "Invoice {$invoice->invoice_number} for ".
                ($invoice->customer?->name ?? 'unknown customer').
                " is {$daysOverdue} days overdue (".app(CurrencyDisplayService::class)->format($invoice->balance).').',
            'link_to' => '/accounting/invoices',
        ]);
    }

    /** @return list<int> */
    private function arOfficerIds(): array
    {
        return User::query()
            ->whereHas('role.permissions', fn ($q) => $q->where('slug', 'accounting.invoices.view'))
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function notifyArEmailFailure(Invoice $invoice, int $daysOverdue, string $message): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->arOfficerIds(),
            'Customer AR reminder',
            "Invoice {$invoice->invoice_number} for ".($invoice->customer?->name ?? 'unknown customer')." is {$daysOverdue} days overdue. {$message}",
            [
                'link_to' => '/accounting/invoices',
                'entity_type' => 'invoice',
                'entity_id' => $invoice->hash_id,
                'reason' => 'The customer email was missing, unreachable, or rejected by the email provider.',
            ],
        );
    }
}
