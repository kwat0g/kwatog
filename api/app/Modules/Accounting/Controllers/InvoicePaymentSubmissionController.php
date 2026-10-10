<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\InvoicePaymentSubmission;
use App\Modules\Accounting\Requests\VerifyInvoicePaymentSubmissionRequest;
use App\Modules\Accounting\Requests\RejectInvoicePaymentSubmissionRequest;
use App\Modules\Accounting\Resources\InvoicePaymentSubmissionResource;
use App\Modules\Accounting\Services\InvoicePaymentSubmissionService;
use Illuminate\Support\Facades\Storage;

class InvoicePaymentSubmissionController
{
    public function __construct(private readonly InvoicePaymentSubmissionService $service) {}
    public function verify(Invoice $invoice, InvoicePaymentSubmission $paymentSubmission, VerifyInvoicePaymentSubmissionRequest $request): InvoicePaymentSubmissionResource
    {
        return new InvoicePaymentSubmissionResource($this->service->verify($invoice, $paymentSubmission, $request->validated('cash_account_id'), $request->user()));
    }
    public function reject(Invoice $invoice, InvoicePaymentSubmission $paymentSubmission, RejectInvoicePaymentSubmissionRequest $request): InvoicePaymentSubmissionResource
    {
        return new InvoicePaymentSubmissionResource($this->service->reject($invoice, $paymentSubmission, $request->validated('reason'), $request->user()));
    }
    public function proof(Invoice $invoice, InvoicePaymentSubmission $paymentSubmission)
    {
        $this->service->assertParent($invoice, $paymentSubmission);
        $this->service->assertProof($paymentSubmission);
        return Storage::disk('local')->download($paymentSubmission->proof_path, $paymentSubmission->proof_file_name, ['Content-Type' => $paymentSubmission->proof_mime, 'X-Content-Type-Options' => 'nosniff']);
    }
}
