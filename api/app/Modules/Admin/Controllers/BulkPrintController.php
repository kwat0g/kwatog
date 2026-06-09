<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Common\Services\BulkPdfService;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/** Sprint 8 — Task 76. */
class BulkPrintController extends Controller
{
    public function __construct(private readonly BulkPdfService $bulk) {}

    public function print(Request $request)
    {
        $data = $request->validate([
            'type'  => ['required', Rule::in([
                'purchase_order', 'bill', 'invoice',
            ])],
            'ids'   => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['required', 'string', 'max:64'],
        ]);

        $payloads = $this->buildPayloads((string) $data['type'], (array) $data['ids']);
        return $this->bulk->render((string) $data['type'], $payloads);
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function buildPayloads(string $type, array $ids): array
    {
        return match ($type) {
            'invoice'        => $this->invoicePayloads($ids),
            'bill'           => $this->billPayloads($ids),
            'purchase_order' => $this->purchaseOrderPayloads($ids),
            default          => [],
        };
    }

    private function invoicePayloads(array $ids): array
    {
        $intIds = array_filter(array_map(
            fn ($id) => Invoice::tryDecodeHash((string) $id),
            $ids,
        ));
        return Invoice::with(['customer', 'items.revenueAccount', 'collections'])
            ->whereIn('id', $intIds)
            ->get()
            ->map(fn (Invoice $inv) => ['invoice' => $inv])
            ->values()
            ->all();
    }

    private function billPayloads(array $ids): array
    {
        $intIds = array_filter(array_map(
            fn ($id) => Bill::tryDecodeHash((string) $id),
            $ids,
        ));
        return Bill::with(['vendor', 'items.expenseAccount', 'payments.cashAccount'])
            ->whereIn('id', $intIds)
            ->get()
            ->map(fn (Bill $bill) => ['bill' => $bill])
            ->values()
            ->all();
    }

    private function purchaseOrderPayloads(array $ids): array
    {
        $intIds = array_filter(array_map(
            fn ($id) => PurchaseOrder::tryDecodeHash((string) $id),
            $ids,
        ));
        return PurchaseOrder::with(['vendor', 'items.item', 'requestedBy', 'approvals.approver'])
            ->whereIn('id', $intIds)
            ->get()
            ->map(fn (PurchaseOrder $po) => ['po' => $po])
            ->values()
            ->all();
    }
}

