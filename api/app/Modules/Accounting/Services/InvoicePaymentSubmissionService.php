<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Exceptions\ForbiddenActionException;
use App\Common\Services\NotificationService;
use App\Common\Support\Money;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Enums\InvoicePaymentSubmissionStatus as Status;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\InvoicePaymentSubmission;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InvoicePaymentSubmissionService
{
    public static function submissionRules(): array
    {
        return [
            'amount' => ['required', 'string', 'regex:/\A\d{1,13}(?:\.\d{1,2})?\z/D', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'sending_bank' => ['required', 'string', 'max:100'],
            'reference_number' => ['required', 'string', 'max:50'],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Manila')->toDateString()],
            'proof_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'extensions:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function submit(Invoice $invoice, array $data, UploadedFile $proof, CustomerPortalUser $by): InvoicePaymentSubmission
    {
        abort_unless((int) $invoice->customer_id === (int) $by->customer_id, 404);
        $data['sending_bank'] = trim((string) ($data['sending_bank'] ?? ''));
        $data['reference_number'] = trim((string) ($data['reference_number'] ?? ''));
        Validator::make([...$data, 'proof_file' => $proof], self::submissionRules())->validate();
        $mime = $proof->getMimeType();
        $extensions = ['application/pdf' => ['pdf'], 'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp']];
        if (! in_array(strtolower($proof->getClientOriginalExtension()), $extensions[$mime] ?? [], true)) {
            throw ValidationException::withMessages(['proof_file' => 'Proof must be a PDF, JPEG, PNG, or WebP with a matching file extension.']);
        }
        $digest = hash_file('sha256', $proof->getRealPath());
        $fingerprint = hash('sha256', json_encode([
            $invoice->id, Money::round2($data['amount']), $data['payment_date'],
            $data['sending_bank'], $data['reference_number'], $digest,
        ], JSON_THROW_ON_ERROR));
        $path = null;
        try {
            return DB::transaction(function () use ($invoice, $data, $proof, $by, $mime, $digest, $fingerprint, &$path) {
                $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
                abort_unless((int) $locked->customer_id === (int) $by->customer_id, 404);
                $key = strtolower($data['idempotency_key']);
                $existing = InvoicePaymentSubmission::query()->where('customer_id', $by->customer_id)->where('idempotency_key', $key)->first();
                if ($existing) {
                    if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                        throw ValidationException::withMessages(['idempotency_key' => 'This submission key was used with different payment details.']);
                    }
                    return $existing;
                }
                if ($reason = $this->blockedReason($locked)) {
                    throw ValidationException::withMessages(['amount' => $reason]);
                }
                if (Money::cmp($data['amount'], (string) $locked->balance) !== 0) {
                    throw ValidationException::withMessages(['amount' => 'Invoice balance changed. Review the refreshed invoice before submitting payment.']);
                }
                if ($data['payment_date'] < $locked->date->toDateString()) {
                    throw ValidationException::withMessages(['payment_date' => 'Payment date cannot be earlier than the invoice date.']);
                }
                $bank = mb_strtolower($data['sending_bank']);
                $reference = mb_strtolower($data['reference_number']);
                if (InvoicePaymentSubmission::query()->where('customer_id', $by->customer_id)
                    ->where('sending_bank_normalized', $bank)->where('reference_normalized', $reference)
                    ->whereIn('status', [Status::Pending, Status::Verified])->exists()) {
                    throw ValidationException::withMessages(['reference_number' => 'This bank reference already belongs to an active payment submission.']);
                }
                if ($path) Storage::disk('local')->delete($path);
                $path = $proof->store('invoice-payment-proofs', 'local');
                if (! $path) throw new BusinessRuleException('Payment proof could not be stored. Please retry.');
                $row = InvoicePaymentSubmission::create([
                    'invoice_id' => $locked->id, 'customer_id' => $by->customer_id, 'submitted_by' => $by->id,
                    'amount' => (string) $locked->balance, 'balance_at_submission' => (string) $locked->balance,
                    'sending_bank' => $data['sending_bank'], 'sending_bank_normalized' => $bank,
                    'reference_number' => $data['reference_number'], 'reference_normalized' => $reference,
                    'payment_date' => $data['payment_date'], 'status' => Status::Pending,
                    'proof_path' => $path, 'proof_file_name' => mb_substr(basename(str_replace('\\', '/', $proof->getClientOriginalName())), 0, 255),
                    'proof_mime' => $mime, 'proof_size' => $proof->getSize(), 'proof_sha256' => $digest,
                    'idempotency_key' => $key, 'request_fingerprint' => $fingerprint,
                ]);
                $notifications = app(NotificationService::class);
                $recipients = $notifications->usersWithPermission('accounting.invoices.collect')
                    // System administrators bypass permissions; they are not payment reviewers.
                    ->reject(fn (User $user) => $user->role?->slug === 'system_admin')
                    ->filter(fn (User $user) => $user->hasPermission('accounting.invoices.view'));
                $notifications->sendForSubject($locked, $recipients, 'invoice.payment_submitted', [
                    'title' => 'Customer payment awaits verification', 'message' => 'Review payment for '.$locked->invoice_number.'.',
                    'link_to' => '/accounting/invoices/'.$locked->hash_id,
                ], 'invoice-payment:'.$row->hash_id.':pending', emailEnabled: false);
                return $row;
            }, 3);
        } catch (\Throwable $error) {
            if ($path) Storage::disk('local')->delete($path);
            if ($error instanceof QueryException && $error->getCode() === '23505') {
                throw ValidationException::withMessages(['reference_number' => 'Payment was already submitted. Reload the invoice to review existing evidence.']);
            }
            throw $error;
        }
    }

    public function blockedReason(Invoice $invoice): ?string
    {
        if (! in_array($invoice->status, [InvoiceStatus::Finalized, InvoiceStatus::Partial], true) || Money::lte((string) $invoice->balance, '0')) {
            return 'Payment submission requires a finalized invoice with an outstanding balance.';
        }
        $pending = $invoice->relationLoaded('paymentSubmissions')
            ? $invoice->paymentSubmissions->contains(fn ($row) => $row->status === Status::Pending)
            : $invoice->paymentSubmissions()->where('status', Status::Pending)->exists();
        return $pending ? 'Payment already submitted. Await Finance verification.' : null;
    }

    public function verify(Invoice $invoice, InvoicePaymentSubmission $submission, string $cashAccountId, User $by): InvoicePaymentSubmission
    {
        $this->authorize($by);
        return DB::transaction(function () use ($invoice, $submission, $cashAccountId, $by) {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $row = InvoicePaymentSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            $this->assertParent($lockedInvoice, $row);
            $accountId = $this->bankAccountId($cashAccountId);
            if ($row->status === Status::Verified) {
                $row->load('collection');
                if ((int) $row->collection->cash_account_id !== $accountId) throw new BusinessRuleException('This payment was already verified against a different bank account.');
                return $row;
            }
            if ($row->status !== Status::Pending) throw new BusinessRuleException('Only a pending payment can be verified.');
            $this->assertProof($row);
            $collection = app(InvoiceService::class)->recordCollection($lockedInvoice, [
                'payment_submission_id' => $row->hash_id, 'cash_account_id' => $cashAccountId,
                'collection_date' => $row->payment_date->toDateString(), 'amount' => (string) $row->amount,
                'payment_method' => 'bank_transfer', 'reference_number' => $row->reference_number,
                'idempotency_key' => 'payment-submission:'.$row->hash_id,
            ], $by);
            $row->update(['status' => Status::Verified, 'collection_id' => $collection->id, 'reviewed_by' => $by->id, 'reviewed_at' => now()]);
            $this->notifyCustomer($row, $lockedInvoice, 'Payment verified', 'Finance confirmed receipt of funds.');
            return $row->fresh();
        }, 3);
    }

    public function reject(Invoice $invoice, InvoicePaymentSubmission $submission, string $reason, User $by): InvoicePaymentSubmission
    {
        $this->authorize($by);
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:500']])->validate();
        return DB::transaction(function () use ($invoice, $submission, $reason, $by) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $row = InvoicePaymentSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            $this->assertParent($locked, $row);
            if ($row->status === Status::Rejected && $row->rejection_reason === $reason) return $row;
            if ($row->status !== Status::Pending) throw new BusinessRuleException('Only a pending payment can be rejected.');
            $row->update(['status' => Status::Rejected, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'rejection_reason' => $reason]);
            $this->notifyCustomer($row, $locked, 'Payment requires correction', $reason);
            return $row;
        }, 3);
    }

    public function assertCollectionSource(Invoice $invoice, array $data, User $by): void
    {
        if (isset($data['payment_submission_id'])) {
            $this->authorize($by);
            $row = InvoicePaymentSubmission::query()->whereHash($data['payment_submission_id'])->lockForUpdate()->firstOrFail();
            $this->assertParent($invoice, $row);
            if ($row->status !== Status::Pending || Money::cmp((string) $row->amount, (string) $invoice->balance) !== 0) {
                throw new BusinessRuleException('Payment state or invoice balance changed. Review the submission before recording funds.');
            }
            if (Money::cmp((string) $data['amount'], (string) $row->amount) !== 0
                || $data['collection_date'] !== $row->payment_date->toDateString()
                || ($data['reference_number'] ?? null) !== $row->reference_number
                || $data['payment_method'] !== 'bank_transfer'
                || ($data['idempotency_key'] ?? null) !== 'payment-submission:'.$row->hash_id) {
                throw new BusinessRuleException('Collection must match the submitted payment exactly.');
            }
            $this->assertProof($row);
            $this->bankAccountId($data['cash_account_id']);
        } else {
            if (! $by->hasPermission('accounting.invoices.collect_manual')) throw new ForbiddenActionException('Customer payment submission is required before recording collection.');
            Validator::make(['manual_reason' => trim((string) ($data['manual_reason'] ?? ''))], ['manual_reason' => ['required', 'string', 'min:10', 'max:500']])->validate();
            if ($invoice->paymentSubmissions()->where('status', Status::Pending)->exists()) {
                throw new BusinessRuleException('Review or reject the pending customer payment before an exceptional collection.');
            }
        }
    }

    public function bankAccountId(string $value): int
    {
        $id = app(PostingAccountResolver::class)->idForTypes($value, AccountType::Asset);
        $account = Account::findOrFail($id);
        $visited = [];
        while ($account && ! isset($visited[$account->id])) {
            if ($account->code === '1020') return $id;
            $visited[$account->id] = true;
            $account = $account->parent_id ? Account::find($account->parent_id) : null;
        }
        throw ValidationException::withMessages(['cash_account_id' => 'Select an active receiving account under Cash in Bank (1020).']);
    }

    public function assertParent(Invoice $invoice, InvoicePaymentSubmission $submission): void
    {
        abort_unless((int) $submission->invoice_id === (int) $invoice->id && (int) $submission->customer_id === (int) $invoice->customer_id, 404);
    }

    public function assertProof(InvoicePaymentSubmission $submission): void
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($submission->proof_path) || ! hash_equals($submission->proof_sha256, hash_file('sha256', $disk->path($submission->proof_path)))) {
            throw ValidationException::withMessages(['proof_file' => 'Payment evidence is unavailable or changed. Reject this attempt and request a new proof.']);
        }
    }

    private function authorize(User $by): void
    {
        if (! $by->hasPermission('accounting.invoices.collect')) throw new ForbiddenActionException('Payment review requires collection permission.');
    }

    private function notifyCustomer(InvoicePaymentSubmission $row, Invoice $invoice, string $title, string $message): void
    {
        app(NotificationService::class)->sendPortalInApp(CustomerPortalUser::query()->where('customer_id', $row->customer_id)->where('is_active', true)->get(), 'invoice.payment_reviewed', [
            'title' => $title, 'message' => $message, 'link_to' => '/portal/customer/invoices/'.$invoice->hash_id,
        ], 'invoice-payment:'.$row->hash_id.':'.$row->status->value);
    }
}
