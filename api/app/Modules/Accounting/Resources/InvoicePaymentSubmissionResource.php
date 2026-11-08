<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use App\Modules\Accounting\Enums\InvoicePaymentSubmissionStatus;
use App\Modules\Auth\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoicePaymentSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canReview = $user instanceof User
            && $this->status === InvoicePaymentSubmissionStatus::Pending
            && $user->hasPermission('accounting.invoices.collect');

        return [
            'id' => $this->hash_id, 'status' => $this->status->value,
            'available_actions' => ['verify' => $canReview, 'reject' => $canReview],
            'amount' => (string) $this->amount, 'sending_bank' => $this->sending_bank,
            'reference_number' => $this->reference_number, 'payment_date' => $this->payment_date->toDateString(),
            'proof_file_name' => $this->proof_file_name, 'proof_mime' => $this->proof_mime, 'proof_size' => $this->proof_size,
            'submitted_at' => $this->created_at->toIso8601String(), 'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'collection_id' => $this->collection_id ? app('hashids')->encode($this->collection_id) : null,
        ];
    }
}
