<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Enums\DocumentType;
use App\Common\Services\DocumentVaultService;
use App\Common\Services\Pdf\PdfRenderService;
use App\Common\Support\Money;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Supplier-safe PDF contracts. Internal Accounting/Purchasing PDF services
 * intentionally include approval, account, and workflow evidence; those
 * views must never be reused for an external portal principal.
 */
class SupplierPortalPdfService
{
    public function __construct(
        private readonly PdfRenderService $renderer,
        private readonly DocumentVaultService $vault,
    ) {}

    public function purchaseOrder(PurchaseOrder $purchaseOrder): StreamedResponse
    {
        $purchaseOrder->load([
            'vendor:id,name,address,contact_person,email,phone',
            'items.item:id,code,name',
        ]);

        $bytes = $this->render('supplier-purchase-order', [
            'po' => $purchaseOrder,
            'lines' => $purchaseOrder->items->map(fn ($line): array => [
                'code' => $line->item?->code ?? '—',
                'name' => $line->item?->name ?? $line->description,
                'description' => $line->description,
                'quantity' => $this->formatMoney((string) $line->quantity),
                'unit' => (string) $line->unit,
                'unit_price' => $this->formatMoney((string) $line->unit_price),
                'total' => $this->formatMoney((string) $line->total),
            ])->values()->all(),
            'subtotal' => $this->formatMoney((string) $purchaseOrder->subtotal),
            'vat_amount' => $this->formatMoney((string) $purchaseOrder->vat_amount),
            'total_amount' => $this->formatMoney((string) $purchaseOrder->total_amount),
        ], DocumentType::PurchaseOrder);

        return $this->stream($bytes, DocumentType::PurchaseOrder, $purchaseOrder);
    }

    public function bill(Bill $bill): StreamedResponse
    {
        $bill->load([
            'vendor:id,name,contact_person,phone',
            'items',
            'payments:id,bill_id,payment_date,amount,payment_method,reference_number,status',
        ]);

        $bytes = $this->render('supplier-bill', [
            'bill' => $bill,
            'lines' => $bill->items->map(fn ($line): array => [
                'description' => $line->description,
                'quantity' => $this->formatMoney((string) $line->quantity),
                'unit' => (string) $line->unit,
                'unit_price' => $this->formatMoney((string) $line->unit_price),
                'total' => $this->formatMoney((string) $line->total),
            ])->values()->all(),
            'subtotal' => $this->formatMoney((string) $bill->subtotal),
            'vat_amount' => $this->formatMoney((string) $bill->vat_amount),
            'total_amount' => $this->formatMoney((string) $bill->total_amount),
            'amount_paid' => $this->formatMoney((string) $bill->amount_paid),
            'balance' => $this->formatMoney((string) $bill->balance),
            'payments' => $bill->payments->map(fn ($payment): array => [
                'date' => optional($payment->payment_date)->format('M d, Y'),
                'method' => $payment->payment_method?->label() ?? (string) $payment->payment_method,
                'reference' => $payment->reference_number,
                'amount' => $this->formatMoney((string) $payment->amount),
            ])->values()->all(),
        ], DocumentType::Bill);

        return $this->stream($bytes, DocumentType::Bill, $bill);
    }

    /** @param array<string, mixed> $data */
    private function render(string $view, array $data, DocumentType $type): string
    {
        View::addLocation(base_path('app/Modules/B2B/Views'));

        return $this->renderer->render($view, $data, [
            'orientation' => 'portrait',
            'title' => $type->label(),
        ]);
    }

    private function stream(string $bytes, DocumentType $type, PurchaseOrder|Bill $entity): StreamedResponse
    {
        return $this->vault->streamInline($this->vault->store($bytes, $type, $entity));
    }

    private function formatMoney(string $amount): string
    {
        $value = Money::round2($amount);
        $negative = str_starts_with($value, '-');
        $absolute = $negative ? substr($value, 1) : $value;
        [$whole, $decimal] = array_pad(explode('.', $absolute, 2), 2, '00');
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole) ?? $whole;

        return ($negative ? '-' : '').$grouped.'.'.str_pad($decimal, 2, '0');
    }
}
