<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\InvoicePaymentSubmission;
use App\Modules\Accounting\Resources\InvoicePaymentSubmissionResource;
use App\Modules\Accounting\Services\InvoicePaymentSubmissionService;
use App\Modules\B2B\Requests\Customer\SubmitInvoicePaymentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CustomerInvoicePaymentController
{
    public function __construct(private readonly InvoicePaymentSubmissionService $service) {}
    public function store(Invoice $invoice, SubmitInvoicePaymentRequest $request)
    {
        $row = $this->service->submit($invoice, $request->safe()->except('proof_file'), $request->file('proof_file'), $request->user());
        return (new InvoicePaymentSubmissionResource($row))->response()->setStatusCode(201);
    }
    public function proof(Invoice $invoice, InvoicePaymentSubmission $paymentSubmission, Request $request)
    {
        abort_unless((int) $invoice->customer_id === (int) $request->user()->customer_id, 404);
        $this->service->assertParent($invoice, $paymentSubmission);
        $this->service->assertProof($paymentSubmission);
        return Storage::disk('local')->download($paymentSubmission->proof_path, $paymentSubmission->proof_file_name, ['Content-Type' => $paymentSubmission->proof_mime, 'X-Content-Type-Options' => 'nosniff']);
    }
}
