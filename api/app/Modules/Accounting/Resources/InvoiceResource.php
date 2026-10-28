<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use App\Common\Models\AuditLog;
use App\Modules\Accounting\Enums\InvoiceStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->hash_id,
            'invoice_number' => $this->invoice_number,
            'date'           => optional($this->date)->toDateString(),
            'due_date'       => optional($this->due_date)->toDateString(),
            'lifecycle_type' => $this->lifecycle_type,
            'prebill_reason' => $this->prebill_reason,
            'prebill_approved_at' => optional($this->prebill_approved_at)->toIso8601String(),
            'created_by'     => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->hash_id,
                'name' => $this->creator->name,
            ] : null),
            'prebill_approved_by' => $this->whenLoaded('prebillApprover', fn () => $this->prebillApprover ? [
                'id' => $this->prebillApprover->hash_id,
                'name' => $this->prebillApprover->name,
            ] : null),
            'is_vatable'     => (bool) $this->is_vatable,
            'vat_classification' => $this->vat_classification?->value,
            'vat_classification_label' => $this->vat_classification?->label(),
            'subtotal'       => (string) $this->subtotal,
            'vat_amount'     => (string) $this->vat_amount,
            'senior_pwd_discount' => (string) $this->senior_pwd_discount,
            'total_amount'   => (string) $this->total_amount,
            'amount_paid'    => (string) $this->amount_paid,
            'balance'        => (string) $this->balance,
            'buyer_tin'      => $this->buyer_tin,
            'atp_number'     => $this->atp_number,
            'serial_range'   => $this->serial_range,
            'is_original'    => (bool) $this->is_original,
            'status'         => $this->status?->value,
            'status_label'   => $this->status?->label(),
            // Display-friendly status: surface "unpaid" alias when finalized + zero collected
            'display_status' => $this->status === InvoiceStatus::Finalized && (string) $this->amount_paid === '0.00'
                ? 'unpaid'
                : $this->status?->value,
            'is_overdue'     => $this->isOverdue(),
            'aging_bucket'   => $this->agingBucket(),
            'remarks'        => $this->remarks,
            'customer'       => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->hash_id, 'name' => $this->customer->name,
            ] : null),
            // 2026-08-08 — compact O2C stepper: the upstream SO + delivery.
            'sales_order'    => $this->whenLoaded('salesOrder', fn () => $this->salesOrder ? [
                'id'        => $this->salesOrder->hash_id,
                'so_number' => $this->salesOrder->so_number,
                'payment_method' => $this->salesOrder->payment_method,
            ] : null),
            'delivery'       => $this->whenLoaded('delivery', fn () => $this->delivery ? [
                'id'              => $this->delivery->hash_id,
                'delivery_number' => $this->delivery->delivery_number,
                'status'          => $this->delivery->status?->value ?? $this->delivery->status,
            ] : null),
            'status_history' => $this->whenLoaded('auditLogs', static fn ($events) => $events->map(
                static function (AuditLog $event): array {
                    $old = $event->old_values ?? [];
                    $new = $event->new_values ?? [];
                    $fromStatus = $old['status'] ?? null;
                    $toStatus = $new['status'] ?? $fromStatus;
                    $approved = ($new['prebill_approved_by'] ?? null) !== null;

                    return [
                        'event_type' => $event->action === 'created'
                            ? 'created'
                            : ($approved ? 'prebill_approved' : (array_key_exists('status', $new) ? 'status_changed' : 'updated')),
                        'from_status' => $fromStatus,
                        'to_status' => $toStatus,
                        'actor_name' => $event->user?->name,
                        'actor_type' => (string) ($event->actor_type ?? 'unknown'),
                        'occurred_at' => $event->created_at->toIso8601String(),
                    ];
                },
            )->all()),
            'items'          => InvoiceItemResource::collection($this->whenLoaded('items')),
            'revision'        => $this->whenLoaded('items', fn () => $this->revisionToken()),
            'collections'    => CollectionResource::collection($this->whenLoaded('collections')),
            'payment_submissions' => InvoicePaymentSubmissionResource::collection($this->whenLoaded('paymentSubmissions')),
            'can_submit_payment' => $this->whenLoaded('paymentSubmissions', fn () => app(\App\Modules\Accounting\Services\InvoicePaymentSubmissionService::class)->blockedReason($this->resource) === null),
            'payment_submission_blocked_reason' => $this->whenLoaded('paymentSubmissions', fn () => app(\App\Modules\Accounting\Services\InvoicePaymentSubmissionService::class)->blockedReason($this->resource)),
            'journal_entry'  => $this->whenLoaded('journalEntry', fn () => $this->journalEntry ? [
                'id'           => $this->journalEntry->hash_id,
                'entry_number' => $this->journalEntry->entry_number,
                'status'       => $this->journalEntry->status?->value,
                'status_label' => $this->journalEntry->status?->label(),
            ] : null),
            'created_at'     => optional($this->created_at)->toIso8601String(),
            'updated_at'     => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
