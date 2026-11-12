<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\OutboxService;
use App\Common\Support\Money;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Services\AccountingAccountPolicyService;
use App\Modules\Accounting\Services\InvoicePaymentSubmissionService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Services\PostingAccountResolver;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Events\SalesOrderAdvanceReleased;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderAdvance;
use App\Modules\CRM\Models\SalesOrderAdvanceApplication;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SalesOrderAdvanceService
{
    private const LIABILITY_SETTING = 'accounting.accounts.customer_advances_code';

    private const PROOF_MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly JournalEntryService $journals,
        private readonly AccountingAccountPolicyService $accounts,
        private readonly PostingAccountResolver $postingAccounts,
        private readonly InvoicePaymentSubmissionService $invoicePayments,
    ) {}

    public function configure(SalesOrder $order, ?string $fixedAmount, ?string $percentage, User $by): ?SalesOrderAdvance
    {
        if ($fixedAmount !== null && $percentage !== null) {
            throw ValidationException::withMessages(['down_payment_amount' => ['Choose a fixed amount or a percentage, not both.']]);
        }

        return DB::transaction(function () use ($order, $fixedAmount, $percentage, $by): ?SalesOrderAdvance {
            $lockedOrder = SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            if (! $lockedOrder->status->isBeforeConfirmation()) {
                throw new BusinessRuleException('The down-payment requirement must be set before Sales confirms the order.');
            }

            $latest = SalesOrderAdvance::query()->where('sales_order_id', $lockedOrder->id)
                ->orderByDesc('attempt_number')->lockForUpdate()->first();

            if ($fixedAmount === null && $percentage === null) {
                if ($latest?->status === 'configured') {
                    $latest->delete();
                }

                return null;
            }

            $fixed = $fixedAmount === null ? null : Money::round2($fixedAmount);
            $percent = $percentage === null ? null : Money::round2($percentage);
            if ($fixed !== null && Money::lte($fixed, '0')) {
                throw ValidationException::withMessages(['down_payment_amount' => ['Down payment must be greater than zero.']]);
            }
            if ($percent !== null && (Money::lte($percent, '0') || Money::gt($percent, '100'))) {
                throw ValidationException::withMessages(['down_payment_percentage' => ['Down payment percentage must be between 0 and 100.']]);
            }
            $required = $fixed ?? Money::round2(bcmul((string) $lockedOrder->total_amount, bcdiv($percent, '100', 8), 8));
            if (Money::lte($required, '0') || Money::gt($required, (string) $lockedOrder->total_amount)) {
                throw ValidationException::withMessages(['down_payment_amount' => ['Down payment cannot exceed the sales-order total.']]);
            }

            if ($latest && ! in_array($latest->status, ['configured', 'rejected'], true)) {
                throw new BusinessRuleException('A submitted or approved down payment cannot be changed. Reject it or resolve the advance first.');
            }
            if ($latest?->status === 'rejected') {
                return SalesOrderAdvance::query()->create([
                    'sales_order_id' => $lockedOrder->id,
                    'customer_id' => $lockedOrder->customer_id,
                    'attempt_number' => $latest->attempt_number + 1,
                    'order_total_snapshot' => $lockedOrder->total_amount,
                    'required_amount' => $required,
                    'percentage' => $percent,
                    'status' => 'configured',
                    'configured_by' => $by->id,
                ]);
            }

            $attributes = [
                'sales_order_id' => $lockedOrder->id,
                'customer_id' => $lockedOrder->customer_id,
                'attempt_number' => $latest?->attempt_number ?? 1,
                'order_total_snapshot' => $lockedOrder->total_amount,
                'required_amount' => $required,
                'percentage' => $percent,
                'status' => 'configured',
                'configured_by' => $by->id,
            ];
            if ($latest) {
                $latest->update($attributes);

                return $latest->fresh();
            }

            return SalesOrderAdvance::query()->create($attributes);
        }, 3);
    }

    /** Keep a percentage deposit tied to the recalculated draft total. */
    public function recalculateConfiguredRequirement(SalesOrder $order): ?SalesOrderAdvance
    {
        $latest = SalesOrderAdvance::query()->where('sales_order_id', $order->id)
            ->orderByDesc('attempt_number')->lockForUpdate()->first();
        if (! $latest || $latest->status !== 'configured') {
            return $latest;
        }

        $required = $latest->percentage === null
            ? (string) $latest->required_amount
            : Money::round2(bcmul((string) $order->total_amount, bcdiv((string) $latest->percentage, '100', 8), 8));
        if (Money::lte($required, '0') || Money::gt($required, (string) $order->total_amount)) {
            throw new BusinessRuleException('The new order total is below the fixed deposit. Update or remove the deposit requirement before saving.');
        }

        $latest->update([
            'customer_id' => $order->customer_id,
            'order_total_snapshot' => $order->total_amount,
            'required_amount' => $required,
        ]);

        return $latest->fresh();
    }

    /** @param array{amount:string,sending_bank:string,reference_number:string,payment_date:string,idempotency_key:string} $payment */
    public function submitEvidence(SalesOrder $order, array $payment, UploadedFile $proof, CustomerPortalUser $portalUser): SalesOrderAdvance
    {
        $mime = strtolower((string) $proof->getMimeType());
        $allowed = ['application/pdf', 'image/jpeg', 'image/png'];
        if (! in_array($mime, $allowed, true) || $proof->getSize() > self::PROOF_MAX_BYTES) {
            throw ValidationException::withMessages(['proof_file' => ['Upload a PDF, JPEG, or PNG no larger than 10 MiB.']]);
        }
        $proofDigest = hash_file('sha256', $proof->getRealPath());
        $key = strtolower(trim((string) $payment['idempotency_key']));
        $fingerprint = hash('sha256', json_encode([
            'order_id' => (int) $order->getKey(), 'amount' => Money::round2((string) $payment['amount']),
            'bank' => trim($payment['sending_bank']), 'reference' => trim($payment['reference_number']),
            'payment_date' => $payment['payment_date'], 'proof_sha256' => $proofDigest,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $storedPath = null;

        try {
            return DB::transaction(function () use ($order, $payment, $proof, $portalUser, $mime, $proofDigest, $key, $fingerprint, &$storedPath): SalesOrderAdvance {
                $lockedOrder = SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());
                abort_unless((int) $lockedOrder->customer_id === (int) $portalUser->customer_id, 404);
                if ($lockedOrder->status->isBeforeConfirmation()) {
                    throw ValidationException::withMessages(['order' => ['Sales must confirm this order before a down payment can be submitted.']]);
                }

                $existing = SalesOrderAdvance::query()->where('customer_id', $portalUser->customer_id)
                    ->where('idempotency_key', $key)->first();
                if ($existing) {
                    if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                        throw ValidationException::withMessages(['idempotency_key' => ['This submission key was used with different payment details.']]);
                    }

                    return $existing;
                }

                $latest = SalesOrderAdvance::query()->where('sales_order_id', $lockedOrder->id)
                    ->orderByDesc('attempt_number')->lockForUpdate()->first();
                if (! $latest) {
                    throw ValidationException::withMessages(['amount' => ['This order does not require a down payment.']]);
                }
                if ($latest->status === 'rejected') {
                    $latest = SalesOrderAdvance::query()->create([
                        'sales_order_id' => $lockedOrder->id, 'customer_id' => $lockedOrder->customer_id,
                        'attempt_number' => $latest->attempt_number + 1,
                        'order_total_snapshot' => $latest->order_total_snapshot,
                        'required_amount' => $latest->required_amount, 'percentage' => $latest->percentage,
                        'status' => 'configured', 'configured_by' => $latest->configured_by,
                    ]);
                }
                if ($latest->status !== 'configured') {
                    throw ValidationException::withMessages(['amount' => ['A down-payment submission is already being reviewed or has been resolved.']]);
                }
                $amount = Money::round2((string) $payment['amount']);
                if (Money::cmp($amount, (string) $latest->required_amount) !== 0) {
                    throw ValidationException::withMessages(['amount' => ['Submit the exact down-payment amount shown for this order.']]);
                }
                if ($payment['payment_date'] < $lockedOrder->date->toDateString() || $payment['payment_date'] > today('Asia/Manila')->toDateString()) {
                    throw ValidationException::withMessages(['payment_date' => ['Payment date must be between the order date and today.']]);
                }
                $normalizedReference = mb_strtolower(trim($payment['reference_number']));
                if (SalesOrderAdvance::query()->where('customer_id', $portalUser->customer_id)
                    ->where('reference_normalized', $normalizedReference)->exists()) {
                    throw ValidationException::withMessages(['reference_number' => ['This bank reference was already submitted for a customer advance.']]);
                }

                $storedPath = $proof->store('sales-order-advance-proofs', 'local');
                if (! $storedPath) {
                    throw new BusinessRuleException('Payment proof could not be stored. Please try again.');
                }
                $latest->update([
                    'status' => 'pending', 'submitted_by' => $portalUser->id, 'submitted_amount' => $amount,
                    'sending_bank' => trim($payment['sending_bank']), 'reference_number' => trim($payment['reference_number']),
                    'reference_normalized' => $normalizedReference, 'payment_date' => $payment['payment_date'],
                    'proof_path' => $storedPath,
                    'proof_file_name' => mb_substr(basename(str_replace('\\', '/', $proof->getClientOriginalName())), 0, 255),
                    'proof_mime' => $mime, 'proof_size' => $proof->getSize(), 'proof_sha256' => $proofDigest,
                    'idempotency_key' => $key, 'request_fingerprint' => $fingerprint,
                ]);

                return $latest->fresh();
            }, 3);
        } catch (\Throwable $error) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }
            if ($error instanceof QueryException && $error->getCode() === '23505') {
                throw ValidationException::withMessages(['reference_number' => ['This bank reference has already been submitted. Reload the order and review the existing proof.']]);
            }
            throw $error;
        }
    }

    public function verify(SalesOrderAdvance $advance, string $bankAccountId, User $financeUser): SalesOrderAdvance
    {
        return DB::transaction(function () use ($advance, $bankAccountId, $financeUser): SalesOrderAdvance {
            $orderId = (int) SalesOrderAdvance::query()->whereKey($advance->getKey())->value('sales_order_id');
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($orderId);
            $locked = SalesOrderAdvance::query()->lockForUpdate()->findOrFail($advance->getKey());
            if ($locked->status === 'verified') {
                return $locked->fresh();
            }
            if ($locked->status !== 'pending') {
                throw new BusinessRuleException('Only a pending customer down payment can be verified.');
            }
            if ($order->status === SalesOrderStatus::Cancelled) {
                throw new BusinessRuleException('Payment proof for a cancelled order cannot be verified. Refund or reverse the transfer outside this workflow.');
            }
            $this->assertProof($locked);
            $bankId = $this->invoicePayments->bankAccountId($bankAccountId);
            $liabilityId = $this->accounts->controlAccountIdForSetting(self::LIABILITY_SETTING);
            $amount = (string) $locked->submitted_amount;
            $entry = $this->journals->create([
                'date' => today('Asia/Manila')->toDateString(),
                'description' => "Customer advance for {$order->so_number}",
                'reference_type' => 'sales_order_advance', 'reference_id' => $locked->id,
                'lines' => [
                    ['account_id' => $bankId, 'debit' => $amount, 'credit' => '0.00', 'description' => 'Customer advance received'],
                    ['account_id' => $liabilityId, 'debit' => '0.00', 'credit' => $amount, 'description' => 'Customer advance liability'],
                ],
            ], $financeUser);
            $entry = $this->journals->postSystem($entry, (int) $financeUser->id);
            $locked->update([
                'status' => 'verified', 'verified_by' => $financeUser->id, 'verified_at' => now(),
                'reviewed_by' => $financeUser->id, 'reviewed_at' => now(), 'journal_entry_id' => $entry->id,
            ]);
            $this->releaseProductionIfReady($order);

            return $locked->fresh();
        }, 3);
    }

    public function reject(SalesOrderAdvance $advance, string $reason, User $financeUser): SalesOrderAdvance
    {
        return DB::transaction(function () use ($advance, $reason, $financeUser): SalesOrderAdvance {
            $orderId = (int) SalesOrderAdvance::query()->whereKey($advance->getKey())->value('sales_order_id');
            SalesOrder::query()->lockForUpdate()->findOrFail($orderId);
            $locked = SalesOrderAdvance::query()->lockForUpdate()->findOrFail($advance->getKey());
            if ($locked->status === 'rejected') {
                return $locked->fresh();
            }
            if ($locked->status !== 'pending') {
                throw new BusinessRuleException('Only pending payment proof can be rejected.');
            }
            $locked->update([
                'status' => 'rejected', 'reviewed_by' => $financeUser->id, 'reviewed_at' => now(),
                'review_reason' => trim($reason),
            ]);

            return $locked->fresh();
        }, 3);
    }

    public function waive(SalesOrderAdvance $advance, string $reason, User $financeUser): SalesOrderAdvance
    {
        return DB::transaction(function () use ($advance, $reason, $financeUser): SalesOrderAdvance {
            $orderId = (int) SalesOrderAdvance::query()->whereKey($advance->getKey())->value('sales_order_id');
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($orderId);
            $locked = SalesOrderAdvance::query()->lockForUpdate()->findOrFail($advance->getKey());
            if ($locked->status === 'waived') {
                return $locked->fresh();
            }
            if (! in_array($locked->status, ['configured', 'rejected'], true)) {
                throw new BusinessRuleException('Only an unpaid down-payment requirement can be waived.');
            }
            $locked->update([
                'status' => 'waived', 'reviewed_by' => $financeUser->id, 'reviewed_at' => now(),
                'review_reason' => trim($reason),
            ]);
            $this->releaseProductionIfReady($order);

            return $locked->fresh();
        }, 3);
    }

    /** Apply verified funds to one finalized standard invoice without rebooking revenue. @return list<SalesOrderAdvanceApplication> */
    public function applyToInvoice(SalesOrder $order, Invoice $invoice, User $systemActor): array
    {
        return DB::transaction(function () use ($order, $invoice, $systemActor): array {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
            $lockedOrder = SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            if ((int) $lockedInvoice->sales_order_id !== (int) $lockedOrder->id
                || ($lockedInvoice->lifecycle_type ?? 'standard') !== 'standard'
                || ! in_array($lockedInvoice->status, [InvoiceStatus::Finalized, InvoiceStatus::Partial], true)) {
                return [];
            }

            $advances = SalesOrderAdvance::query()->where('sales_order_id', $lockedOrder->id)->where('status', 'verified')
                ->orderBy('attempt_number')->orderBy('id')->lockForUpdate()->get();
            if ($advances->isEmpty()) {
                return [];
            }

            $applications = [];
            $remainingInvoice = (string) $lockedInvoice->balance;
            $liabilityId = $this->accounts->controlAccountIdForSetting(self::LIABILITY_SETTING);
            $arId = $this->accounts->controlAccountIdForSetting('accounting.accounts.ar_code');

            foreach ($advances as $advance) {
                if (Money::isZero($remainingInvoice)) {
                    break;
                }
                if (SalesOrderAdvanceApplication::query()->where('sales_order_advance_id', $advance->id)->where('invoice_id', $lockedInvoice->id)->exists()) {
                    continue;
                }
                $applied = (string) SalesOrderAdvanceApplication::query()->where('sales_order_advance_id', $advance->id)->sum('amount');
                $available = Money::sub(Money::sub((string) $advance->submitted_amount, $applied), (string) $advance->refunded_amount);
                if (Money::lte($available, '0')) {
                    continue;
                }
                $amount = Money::lte($available, $remainingInvoice) ? $available : $remainingInvoice;
                $application = SalesOrderAdvanceApplication::query()->create([
                    'sales_order_advance_id' => $advance->id, 'invoice_id' => $lockedInvoice->id,
                    'amount' => $amount, 'journal_entry_id' => null,
                    'applied_by' => $systemActor->id, 'applied_at' => now(),
                ]);
                $entry = $this->journals->create([
                    'date' => today('Asia/Manila')->toDateString(),
                    'description' => "Apply customer advance to invoice {$lockedInvoice->invoice_number}",
                    'reference_type' => 'sales_order_advance_application', 'reference_id' => $application->id,
                    'lines' => [
                        ['account_id' => $liabilityId, 'debit' => $amount, 'credit' => '0.00', 'description' => 'Customer advance applied'],
                        ['account_id' => $arId, 'debit' => '0.00', 'credit' => $amount, 'description' => 'Accounts receivable settled by advance'],
                    ],
                ], $systemActor);
                $entry = $this->journals->postSystem($entry, (int) $systemActor->id);
                $application->update(['journal_entry_id' => $entry->id]);
                $applications[] = $application->fresh();
                $newPaid = Money::add((string) $lockedInvoice->amount_paid, $amount);
                $remainingInvoice = Money::sub((string) $lockedInvoice->balance, $amount);
                $lockedInvoice->update([
                    'amount_paid' => $newPaid, 'balance' => $remainingInvoice,
                    'status' => Money::isZero($remainingInvoice) ? InvoiceStatus::Paid : InvoiceStatus::Partial,
                ]);
            }

            if ($lockedInvoice->sales_order_id && $lockedInvoice->status === InvoiceStatus::Paid) {
                app(SalesOrderService::class)->synchronizeCompletionState((int) $lockedInvoice->sales_order_id);
            }

            return $applications;
        }, 3);
    }

    public function refundUnapplied(SalesOrderAdvance $advance, string $bankAccountId, string $reference, string $reason, User $financeUser): SalesOrderAdvance
    {
        return DB::transaction(function () use ($advance, $bankAccountId, $reference, $reason, $financeUser): SalesOrderAdvance {
            $orderId = (int) SalesOrderAdvance::query()->whereKey($advance->getKey())->value('sales_order_id');
            SalesOrder::query()->lockForUpdate()->findOrFail($orderId);
            $locked = SalesOrderAdvance::query()->lockForUpdate()->findOrFail($advance->getKey());
            if (! in_array($locked->status, ['verified', 'partially_refunded'], true)) {
                throw new BusinessRuleException('Only a verified customer advance can be refunded.');
            }
            $reference = trim($reference);
            if ($reference === '') {
                throw ValidationException::withMessages(['reference_number' => ['Enter the refund transfer reference.']]);
            }
            if (SalesOrderAdvance::query()->where('customer_id', $locked->customer_id)
                ->where('refund_reference', $reference)->exists()) {
                throw ValidationException::withMessages(['reference_number' => ['This refund reference has already been recorded.']]);
            }
            $applied = (string) SalesOrderAdvanceApplication::query()->where('sales_order_advance_id', $locked->id)->sum('amount');
            $unapplied = Money::sub(Money::sub((string) $locked->submitted_amount, $applied), (string) $locked->refunded_amount);
            if (Money::lte($unapplied, '0')) {
                throw new BusinessRuleException('There is no unapplied customer advance to refund.');
            }
            $bankId = $this->invoicePayments->bankAccountId($bankAccountId);
            $liabilityId = $this->accounts->controlAccountIdForSetting(self::LIABILITY_SETTING);
            $entry = $this->journals->create([
                'date' => today('Asia/Manila')->toDateString(),
                'description' => "Refund unused customer advance for {$locked->salesOrder->so_number}",
                'reference_type' => 'sales_order_advance', 'reference_id' => $locked->id,
                'lines' => [
                    ['account_id' => $liabilityId, 'debit' => $unapplied, 'credit' => '0.00', 'description' => 'Customer advance liability refunded'],
                    ['account_id' => $bankId, 'debit' => '0.00', 'credit' => $unapplied, 'description' => 'Customer advance refund paid'],
                ],
            ], $financeUser);
            $entry = $this->journals->postSystem($entry, (int) $financeUser->id);
            $locked->update([
                'status' => 'refunded', 'refunded_amount' => Money::add((string) $locked->refunded_amount, $unapplied),
                'refund_journal_entry_id' => $entry->id, 'refund_reference' => trim($reference),
                'refund_reason' => trim($reason), 'reviewed_by' => $financeUser->id, 'reviewed_at' => now(),
            ]);

            return $locked->fresh();
        }, 3);
    }

    public function assertCancellable(SalesOrder $order): void
    {
        $advances = SalesOrderAdvance::query()->where('sales_order_id', $order->id)
            ->whereIn('status', ['pending', 'verified', 'partially_refunded'])->get();
        foreach ($advances as $advance) {
            if ($advance->status === 'pending') {
                throw new BusinessRuleException('Resolve pending payment proof with Finance before cancelling this order.');
            }
            $applied = (string) SalesOrderAdvanceApplication::query()->where('sales_order_advance_id', $advance->id)->sum('amount');
            $unapplied = Money::sub(Money::sub((string) $advance->submitted_amount, $applied), (string) $advance->refunded_amount);
            if (Money::gt($unapplied, '0')) {
                throw new BusinessRuleException('Resolve the verified customer advance before cancelling or closing this order.');
            }
        }
    }

    public function assertProof(SalesOrderAdvance $advance): void
    {
        if (! $advance->proof_path || ! $advance->proof_sha256) {
            throw ValidationException::withMessages(['proof_file' => ['Payment proof is missing. Reject this attempt and request a new upload.']]);
        }
        $disk = Storage::disk('local');
        if (! $disk->exists($advance->proof_path) || ! hash_equals($advance->proof_sha256, hash_file('sha256', $disk->path($advance->proof_path)))) {
            throw ValidationException::withMessages(['proof_file' => ['Payment proof is unavailable or changed. Reject this attempt and request a new upload.']]);
        }
    }

    public function releaseProductionIfReady(SalesOrder $order): bool
    {
        $lockedOrder = SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());
        if (! in_array($lockedOrder->status, [
            SalesOrderStatus::Confirmed,
            SalesOrderStatus::InProduction,
            SalesOrderStatus::PartiallyDelivered,
            SalesOrderStatus::Delivered,
            SalesOrderStatus::Invoiced,
            SalesOrderStatus::Paid,
        ], true) || $lockedOrder->mrp_released_at !== null) {
            return false;
        }
        $latest = SalesOrderAdvance::query()->where('sales_order_id', $lockedOrder->id)->orderByDesc('attempt_number')->first();
        if ($latest && ! in_array($latest->status, ['verified', 'waived'], true)) {
            return false;
        }

        $lockedOrder->update(['mrp_released_at' => now()]);
        if (! $latest) {
            // Preserve the established confirmation listener's direct MRP
            // dispatch for orders with no deposit requirement. Deposit-held
            // orders are dispatched by the durable advance-release event below.
            return true;
        }

        $fresh = $lockedOrder->fresh();
        app(OutboxService::class)->recordForChain(
            new SalesOrderAdvanceReleased($fresh), $fresh, 'o2c', 'sales_order', 'production_released',
            'sales-order-production-release:'.$fresh->id,
        );

        return true;
    }
}
