<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use App\Common\Support\Money;
use App\Modules\Accounting\Enums\BillStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $bills = $this->bills;
        $statuses = $bills->pluck('status');
        $status = match (true) {
            $this->voided_at !== null => 'voided',
            $statuses->contains(BillStatus::Cancelled) => 'requires_correction',
            $bills->isNotEmpty() && $statuses->every(static fn ($value): bool => $value === BillStatus::Paid) => 'paid',
            $statuses->contains(BillStatus::Partial), $statuses->contains(BillStatus::Paid) => 'partial',
            $statuses->contains(BillStatus::Unpaid) => 'unpaid',
            default => 'submitted',
        };
        $attachment = $this->documents->firstWhere('document_type', 'supplier_invoice');

        return [
            'id' => $this->hash_id,
            'bill_number' => $bills->count() === 1 ? $bills->first()->bill_number : $bills->count().' AP bills',
            'supplier_invoice_number' => $this->number,
            'supplier_invoice_date' => optional($this->date)->toDateString(),
            'date' => optional($this->date)->toDateString(),
            'total_amount' => (string) $this->gross_total,
            'remarks' => $this->remarks,
            'amount_paid' => $bills->reduce(static fn (string $sum, $bill): string => Money::add($sum, (string) $bill->amount_paid), Money::zero()),
            'balance' => $bills->reduce(static fn (string $sum, $bill): string => Money::add($sum, (string) $bill->balance), Money::zero()),
            'due_date' => $bills->min(static fn ($bill) => $bill->due_date?->toDateString()),
            'status' => $status,
            'status_label' => match ($status) {
                'requires_correction' => 'Requires correction',
                'partial' => 'Partially paid',
                default => ucfirst($status),
            },
            'purchase_order' => $this->purchaseOrder ? [
                'id' => $this->purchaseOrder->hash_id,
                'po_number' => $this->purchaseOrder->po_number,
            ] : null,
            'goods_receipt_note' => $bills->count() === 1 && $bills->first()->goodsReceiptNote ? [
                'id' => $bills->first()->goodsReceiptNote->hash_id,
                'grn_number' => $bills->first()->goodsReceiptNote->grn_number,
            ] : null,
            'bills' => $bills->map(static fn ($bill): array => [
                'id' => $bill->hash_id,
                'bill_number' => $bill->bill_number,
                'grn_number' => $bill->goodsReceiptNote?->grn_number,
                'total_amount' => (string) $bill->total_amount,
                'balance' => (string) $bill->balance,
                'status' => $bill->status?->value,
            ])->values()->all(),
            'items' => $bills->flatMap(static fn ($bill) => $bill->items->map(static fn ($item): array => [
                'id' => $item->hash_id,
                'description' => $item->description,
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'total' => (string) $item->total,
            ]))->values()->all(),
            'payments' => $bills->flatMap(static fn ($bill) => $bill->payments->map(static fn ($payment): array => [
                'id' => $payment->hash_id,
                'payment_date' => optional($payment->payment_date)->toDateString(),
                'amount' => (string) $payment->amount,
                'payment_method' => $payment->payment_method?->value,
                'payment_method_label' => $payment->payment_method?->label(),
                'reference_number' => $payment->reference_number,
                'status' => $payment->status?->value,
                'status_label' => $payment->status?->label(),
            ]))->values()->all(),
            'invoice_attachment' => $attachment ? [
                'id' => $attachment->hash_id,
                'original_filename' => $attachment->original_filename,
            ] : null,
        ];
    }
}
