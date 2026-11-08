<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use App\Common\Support\Money;
use App\Modules\Accounting\Enums\CreditNoteStatus;
use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Auth\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CreditNote
 */
class CreditNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $canManage = $viewer instanceof User && $viewer->hasPermission('accounting.credit_notes.manage');
        $status = $this->status;

        return [
            'id' => $this->hash_id,
            'credit_note_number' => $this->credit_note_number,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'permissions' => ['manage' => $canManage],
            'available_actions' => [
                'can_finalize' => $canManage && $status === CreditNoteStatus::Draft,
                'can_apply' => $canManage
                    && $status === CreditNoteStatus::Finalized
                    && Money::gt((string) $this->balance, '0'),
            ],
            'date' => optional($this->date)->toDateString(),
            'is_vatable' => (bool) $this->is_vatable,
            'subtotal' => (string) $this->subtotal,
            'vat_amount' => (string) $this->vat_amount,
            'total_amount' => (string) $this->total_amount,
            'applied_amount' => (string) $this->applied_amount,
            'balance' => (string) $this->balance,
            'reason' => $this->reason,
            'void_reason' => $this->void_reason,
            'voided_at' => optional($this->voided_at)->toIso8601String(),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->hash_id,
                'name' => $this->customer->name,
            ] : null),
            'vendor' => $this->whenLoaded('vendor', fn () => $this->vendor ? [
                'id' => $this->vendor->hash_id,
                'name' => $this->vendor->name,
            ] : null),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id' => $this->invoice->hash_id,
                'invoice_number' => $this->invoice->invoice_number,
            ] : null),
            'bill' => $this->whenLoaded('bill', fn () => $this->bill ? [
                'id' => $this->bill->hash_id,
                'bill_number' => $this->bill->bill_number,
            ] : null),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'id' => $l->hash_id,
                'description' => $l->description,
                'amount' => (string) $l->amount,
            ])->all()),
            'applications' => $this->whenLoaded('applications', fn () => $this->applications->map(fn ($a) => [
                'id' => $a->hash_id,
                'invoice_id' => $a->invoice?->hash_id,
                'bill_id' => $a->bill?->hash_id,
                'amount' => (string) $a->amount,
                'created_at' => optional($a->created_at)->toIso8601String(),
            ])->all()),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
