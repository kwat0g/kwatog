<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Services\AccountingAccountPolicyService;
use App\Modules\Accounting\Services\CreditNoteService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Validation\ValidationException;

/** Creates or verifies the real documents that fulfill an agreed case. */
class ReturnCaseSettlementService
{
    public function createReturn(ReturnCase $case, User $by): void
    {
        if ($case->return_request_id) {
            $existing = $case->returnRequest()->firstOrFail();
            if (! in_array($existing->status->value, ['cancelled', 'rejected'], true)) {
                return;
            }
            if ($existing->credit_note_id || $existing->received_at) {
                throw new BusinessRuleException('Review the previous return effects before preparing another return.');
            }
            $case->forceFill(['return_request_id' => null])->save();
        }
        if ($case->credit_note_id) {
            throw new BusinessRuleException('A case with a prepared credit cannot also create a physical return. Resolve the existing credit or open a new case.');
        }
        if (! in_array($case->resolution?->value, ['return_goods', 'redelivery', 'credit'], true)) {
            throw new BusinessRuleException('Agree a return, redelivery, or credit resolution before preparing a physical return.');
        }
        $case->load('lines', 'goodsReceiptNote');
        $lines = $case->lines->filter(fn ($line) => bccomp((string) $line->verified_defective_quantity, '0', 3) > 0);
        if ($lines->isEmpty()) {
            throw new BusinessRuleException('There are no verified defective goods to return. Missing goods do not need physical receipt.');
        }
        $service = app(ReturnRequestService::class);
        if ($case->type->value === 'customer') {
            $rma = $service->createCustomerReturnFromPortal((int) $case->customer_id, [
                'reason_description' => mb_substr($case->description, 0, 1000),
                'customer_notes' => 'Reported on '.$case->case_number,
                'items' => $lines->map(fn ($line) => [
                    'source_delivery_item_id' => $line->source_delivery_item_id,
                    'quantity' => (string) $line->verified_defective_quantity,
                    'reason' => mb_substr($line->reason ?: $case->description, 0, 500),
                ])->values()->all(),
            ], $by, $case);
            foreach ($rma->items as $item) {
                $source = $lines->firstWhere('source_delivery_item_id', $item->source_delivery_item_id);
                $item->update(['lot_number' => $source?->lot_number, 'serial_number' => $source?->serial_number]);
            }
        } else {
            if (! $case->goods_receipt_note_id) {
                throw new BusinessRuleException('Record and inspect received goods before opening a supplier return.');
            }
            $rma = ReturnRequest::query()->where('goods_receipt_note_id', $case->goods_receipt_note_id)
                ->where('type', 'supplier_return')->whereNotIn('status', ['cancelled', 'rejected'])->lockForUpdate()->first();
            if (! $rma) {
                $rma = $service->create([
                    'type' => 'supplier_return', 'vendor_id' => $case->vendor_id,
                    'purchase_order_id' => $case->purchase_order_id,
                    'reason_description' => mb_substr($case->description, 0, 1000),
                    'items' => $lines->map(fn ($line) => [
                        'item_id' => $line->item_id, 'source_po_item_id' => $line->source_po_item_id,
                        'source_grn_item_id' => $line->source_grn_item_id, 'lot_number' => $line->lot_number,
                        'quantity' => (string) $line->verified_defective_quantity,
                        'reason' => mb_substr($line->reason ?: $case->description, 0, 500),
                    ])->values()->all(),
                ], $by, $case);
            } else {
                $this->assertReusableReturn($case, $rma);
            }
        }
        $case->forceFill(['return_request_id' => $rma->id])->save();
    }

    public function createCredit(ReturnCase $case, User $by): void
    {
        if ($case->credit_note_id) {
            if ($case->creditNote()->firstOrFail()->status->value !== 'void') {
                return;
            }
            $case->forceFill(['credit_note_id' => null])->save();
        }
        if ($case->resolution?->value !== 'credit') {
            throw new BusinessRuleException('Agree a credit resolution before preparing a credit note.');
        }
        $case->load('lines.product', 'returnRequest.creditNote');
        $customer = $case->type->value === 'customer';
        if (! $customer && $case->goods_receipt_note_id && ! $case->return_request_id
            && ReturnRequest::query()->where('goods_receipt_note_id', $case->goods_receipt_note_id)->whereNotIn('status', ['cancelled', 'rejected'])->exists()) {
            throw new BusinessRuleException('Link the existing Quality return with Prepare physical return before preparing a case credit.');
        }
        $invoice = $customer ? Invoice::query()->where('delivery_id', $case->delivery_id)->whereIn('status', ['finalized', 'partial', 'paid'])->latest('id')->first() : null;
        $bill = ! $customer ? Bill::query()->where('purchase_order_id', $case->purchase_order_id)
            ->when($case->goods_receipt_note_id, fn ($q) => $q->where('goods_receipt_note_id', $case->goods_receipt_note_id))
            ->whereIn('status', ['unpaid', 'partial', 'paid'])->latest('id')->first() : null;
        if (! $customer && ! $bill) {
            throw new BusinessRuleException('These goods have no posted payable to credit. Keep the shortage outstanding or agree a purchase-order adjustment.');
        }
        if ($bill && ! $this->billCoversShortage($case, $bill)) {
            throw new BusinessRuleException('The missing goods were not billed. Keep them owed on the original PO or use its reviewed short-close workflow; do not credit received goods.');
        }
        $policies = app(AccountingAccountPolicyService::class);
        $default = $policies->controlAccountIdForSetting($customer ? 'accounting.default_sales_revenue_account_code' : 'accounting.accounts.purchase_return_expense_code');
        $creditLines = [];
        foreach ($case->lines as $line) {
            // A physical RMA credits its own actual receipt. The case credits
            // only its shortage; never credit the same defective units twice.
            $quantity = $case->return_request_id ? (string) $line->verified_missing_quantity : $this->affected($line);
            if (bccomp($quantity, '0', 3) <= 0) {
                continue;
            }
            $creditLines[] = [
                'account_id' => $customer ? ($line->product?->revenue_account_id ?: $default) : $default,
                'description' => $case->case_number.' — '.$line->description,
                'amount' => Money::mul($quantity, (string) $line->source_unit_price),
            ];
        }
        if ($creditLines === []) {
            if ($case->returnRequest?->credit_note_id) {
                $case->forceFill(['credit_note_id' => $case->returnRequest->credit_note_id])->save();

                return;
            }
            throw new BusinessRuleException('Complete the physical-return disposition to prepare its credit.');
        }
        $credit = app(CreditNoteService::class)->create([
            'type' => $customer ? 'customer' : 'supplier',
            'customer_id' => $case->customer_id, 'vendor_id' => $case->vendor_id,
            'invoice_id' => $invoice?->id, 'bill_id' => $bill?->id,
            'date' => now()->toDateString(), 'reason' => 'Agreed resolution for '.$case->case_number,
            'lines' => $creditLines,
        ], $by);
        $case->forceFill(['credit_note_id' => $credit->id])->save();
    }

    public function link(ReturnCase $case, array $data, User $by): void
    {
        $fields = [
            'credit_note_id' => CreditNote::class,
            'replacement_sales_order_id' => SalesOrder::class,
            'replacement_purchase_order_id' => PurchaseOrder::class,
            'replacement_delivery_id' => Delivery::class,
        ];
        $documents = [];
        $receiptHashes = array_values(array_unique(array_merge($data['resolution_goods_receipt_note_ids'] ?? [],
            empty($data['resolution_goods_receipt_note_id']) ? [] : [$data['resolution_goods_receipt_note_id']])));

        // Validate every requested link before persisting any of them. In
        // particular, a replacement PO in this same request can establish the
        // lineage used to validate its receipt, while a bad receipt must not
        // leave that PO immutably linked on its own.
        foreach ($fields as $field => $class) {
            if (empty($data[$field])) {
                continue;
            }
            $id = HashIdFilter::decode($data[$field], $class);
            $document = $id ? $class::query()->lockForUpdate()->find($id) : null;
            if (! $document) {
                throw ValidationException::withMessages([$field => 'Choose a valid resolution document.']);
            }
            if ($case->$field && (int) $case->$field !== $id) {
                throw new BusinessRuleException('A different resolution document is already linked. Review the existing obligation before replacing it.');
            }
            $this->assertParty($case, $document, $field, $documents);
            if ($document->created_at && $document->created_at->lt($case->created_at) && $field !== 'replacement_purchase_order_id') {
                throw new BusinessRuleException('Use a document created for this resolution, not an earlier transaction.');
            }
            if (ReturnCase::query()->where($field, $id)->whereKeyNot($case->id)->exists()) {
                throw new BusinessRuleException('This document is already settling another case.');
            }
            if ($field === 'credit_note_id' && ! $by->hasPermission('accounting.credit_notes.manage')) {
                abort(403);
            }
            if ($field === 'credit_note_id' && ! str_contains((string) $document->reason, $case->case_number)
                && (int) $case->returnRequest?->credit_note_id !== (int) $document->id) {
                throw new BusinessRuleException('Finance must identify this case reference on the credit before linking it as settlement.');
            }
            if ($field === 'credit_note_id' && $case->resolution?->value !== 'credit') {
                throw new BusinessRuleException('Agree a credit resolution before linking a credit note.');
            }
            $documents[$field] = $document;
        }
        if ($documents === [] && $receiptHashes === []) {
            throw ValidationException::withMessages(['documents' => 'Select at least one resolution document.']);
        }

        if ($receiptHashes !== []) {
            app(ReturnCaseRedeliveryService::class)->add($case, $receiptHashes, $by);
            $case->refresh();
        }

        // Persist only after all selected records and receipt allocations validate.
        foreach ($fields as $field => $_class) {
            if (isset($documents[$field])) {
                $case->forceFill([$field => $documents[$field]->id])->save();
            }
        }
    }

    public function assertComplete(ReturnCase $case, User $by): void
    {
        $case->load('lines', 'returnRequest.creditNote', 'creditNote', 'replacementSalesOrder.items', 'replacementDelivery.items.salesOrderItem', 'resolutionGoodsReceiptNote.items');
        if ($case->returnRequest && $case->returnRequest->status->value !== 'completed') {
            throw new BusinessRuleException('Complete the linked physical return before resolving this case.');
        }
        if ($case->returnRequest) {
            $this->assertReturnCoverage($case);
        }
        $resolution = $case->resolution?->value;
        if ($resolution === 'no_action') {
            abort_unless($by->hasPermission('return_management.approve'), 403);
            if ($case->type->value === 'supplier'
                && $case->lines->contains(fn ($line) => bccomp((string) $line->verified_missing_quantity, '0', 3) > 0)
                && ! in_array($case->purchaseOrder?->status->value, ['closed', 'cancelled'], true)) {
                throw new BusinessRuleException('Close or short-close the original PO through Purchasing before accepting a verified shortage without redelivery.');
            }
            if (mb_strlen(trim((string) $case->resolution_notes)) < 10) {
                throw new BusinessRuleException('Record the reviewed closure decision before resolving without further action.');
            }

            return;
        }
        if ($resolution === 'credit') {
            $credits = collect([$case->creditNote, $case->returnRequest?->creditNote])->filter()->unique('id');
            if ($credits->isEmpty() || $credits->contains(fn ($credit) => ! in_array($credit->status->value, ['finalized', 'applied'], true))) {
                throw new BusinessRuleException('Finance must issue every linked credit before this case can be resolved.');
            }
            $required = '0';
            foreach ($case->lines as $line) {
                $required = Money::add($required, Money::mul($this->affected($line), (string) $line->source_unit_price));
            }
            $issued = '0';
            foreach ($credits as $credit) {
                $issued = Money::add($issued, (string) $credit->subtotal);
            }
            if (Money::lt($issued, $required)) {
                throw new BusinessRuleException('Issued credits do not cover the agreed quantities yet.');
            }

            return;
        }
        if ($resolution === 'return_goods') {
            if (! $case->returnRequest || $case->lines->contains(fn ($line) => bccomp((string) $line->verified_missing_quantity, '0', 3) > 0)) {
                throw new BusinessRuleException('Physical return alone cannot settle missing goods. Agree redelivery or credit for the whole case.');
            }
            $credit = $case->returnRequest->creditNote;
            if ($credit && ! in_array($credit->status->value, ['finalized', 'applied'], true)) {
                throw new BusinessRuleException('The return credit is still awaiting Finance.');
            }

            return;
        }
        if ($resolution !== 'redelivery') {
            throw new BusinessRuleException('Agree a resolution before closing the case.');
        }
        if ($case->type->value === 'customer') {
            $order = $case->replacementSalesOrder;
            if (! $order || (int) $order->return_case_id !== (int) $case->id
                || bccomp((string) $order->subtotal, '0', 2) !== 0
                || bccomp((string) $order->vat_amount, '0', 2) !== 0
                || bccomp((string) $order->total_amount, '0', 2) !== 0) {
                throw new BusinessRuleException('Customer redelivery requires the approved zero-price replacement order created for this case.');
            }
            $deliveries = Delivery::query()->where('sales_order_id', $order->id)->where('status', 'confirmed')
                ->with('items.salesOrderItem')->get();
            if ($deliveries->isEmpty()) {
                throw new BusinessRuleException('Confirm the approved no-charge replacement delivery before resolving.');
            }
            $quantities = $deliveries->flatMap->items->groupBy(fn ($line) => $line->salesOrderItem?->product_id);
            if (! $case->replacement_delivery_id) {
                $case->forceFill(['replacement_delivery_id' => $deliveries->last()->id])->save();
            }
            foreach ($case->lines->groupBy('product_id') as $productId => $lines) {
                $required = $lines->reduce(fn ($sum, $line) => bcadd($sum, $this->affected($line), 3), '0');
                $received = ($quantities->get($productId) ?? collect())->reduce(fn ($sum, $line) => bcadd($sum, (string) $line->quantity, 3), '0');
                if (bccomp($received, $required, 3) < 0) {
                    throw new BusinessRuleException('The no-charge replacement delivery does not cover every agreed product quantity.');
                }
            }
        } else {
            $credit = $case->returnRequest?->creditNote;
            if ($credit && ! in_array($credit->status->value, ['finalized', 'applied'], true)) {
                throw new BusinessRuleException('Finance must issue the supplier return credit before closing its redelivery case.');
            }
            app(ReturnCaseRedeliveryService::class)->assertComplete($case);
        }
    }

    public function options(ReturnCase $case): array
    {
        $customer = $case->type->value === 'customer';
        $map = fn ($rows, string $label) => $rows->map(fn ($row) => ['id' => $row->hash_id, 'label' => $row->$label])->all();

        if ($customer) {
            $orders = SalesOrder::query()->where('return_case_id', $case->id)->where('customer_id', $case->customer_id)
                ->where('status', '!=', 'cancelled')->with('items')->latest('id')->get()
                ->filter(fn (SalesOrder $order): bool => $this->isCaseReplacementOrder($case, $order));
            $orderIds = $orders->modelKeys();
            $deliveries = Delivery::query()->whereIn('sales_order_id', $orderIds)->where('status', 'confirmed')->with('salesOrder.items')
                ->latest('id')->get()->filter(fn (Delivery $delivery): bool => $this->isCaseReplacementDelivery($case, $delivery));

            return [
                'credit_notes' => $map(CreditNote::query()->where('customer_id', $case->customer_id)->where('type', 'customer')->where('status', '!=', 'void')->where('created_at', '>=', $case->created_at)->latest('id')->limit(100)->get(), 'credit_note_number'),
                'deliveries' => $map($deliveries, 'delivery_number'),
                'goods_receipts' => [],
                'sales_orders' => $map($orders, 'so_number'),
                'purchase_orders' => [],
            ];
        }

        $case->loadMissing('returnRequest');
        $replacementPoId = (int) $case->returnRequest?->replacement_purchase_order_id;
        $replacementOrders = $replacementPoId > 0
            ? PurchaseOrder::query()->whereKey($replacementPoId)->where('vendor_id', $case->vendor_id)->where('status', '!=', 'cancelled')->get()
            : collect();

        return [
            'credit_notes' => $map(CreditNote::query()->where('vendor_id', $case->vendor_id)->where('type', 'supplier')->where('status', '!=', 'void')->where('created_at', '>=', $case->created_at)->latest('id')->limit(100)->get(), 'credit_note_number'),
            'deliveries' => [],
            'goods_receipts' => app(ReturnCaseRedeliveryService::class)->options($case),
            'sales_orders' => [],
            'purchase_orders' => $map($replacementOrders, 'po_number'),
        ];
    }

    private function assertParty(ReturnCase $case, mixed $document, string $field, array $documents): void
    {
        $customer = $case->type->value === 'customer';
        if ($document instanceof Delivery) {
            $document->load('salesOrder');
            $valid = $customer && (int) $document->id !== (int) $case->delivery_id
                && $this->isCaseReplacementDelivery($case, $document);
        } elseif ($document instanceof CreditNote) {
            $valid = $document->type->value === $case->type->value
                && ($customer ? (int) $document->customer_id === (int) $case->customer_id : (int) $document->vendor_id === (int) $case->vendor_id);
            if ($valid) {
                $valid = $this->creditMatchesSource($case, $document);
            }
        } elseif ($document instanceof SalesOrder) {
            $document->loadMissing('items');
            $valid = $customer && $field === 'replacement_sales_order_id' && $this->isCaseReplacementOrder($case, $document);
        } elseif ($document instanceof PurchaseOrder) {
            $case->loadMissing('returnRequest');
            $valid = ! $customer && $field === 'replacement_purchase_order_id'
                && (int) $document->vendor_id === (int) $case->vendor_id
                && (int) $document->id === (int) $case->returnRequest?->replacement_purchase_order_id;
        } else {
            $valid = ! $customer && (int) $document->vendor_id === (int) $case->vendor_id;
        }
        if (! $valid) {
            throw new BusinessRuleException('The resolution document does not belong to this party or is the original disputed delivery.');
        }
    }

    private function isCaseReplacementOrder(ReturnCase $case, SalesOrder $order): bool
    {
        $order->loadMissing('items');
        if ((int) $order->return_case_id !== (int) $case->id
            || (int) $order->customer_id !== (int) $case->customer_id
            || $order->status->value === 'cancelled'
            || bccomp((string) $order->subtotal, '0', 2) !== 0
            || bccomp((string) $order->vat_amount, '0', 2) !== 0
            || bccomp((string) $order->total_amount, '0', 2) !== 0
            || $order->items->isEmpty()) {
            return false;
        }

        return $order->items->every(fn ($line): bool => bccomp((string) $line->unit_price, '0', 2) === 0
            && bccomp((string) $line->total, '0', 2) === 0);
    }

    private function isCaseReplacementDelivery(ReturnCase $case, Delivery $delivery): bool
    {
        $delivery->loadMissing('salesOrder.items');
        $status = $delivery->status instanceof \BackedEnum ? $delivery->status->value : $delivery->status;

        return (int) $delivery->id !== (int) $case->delivery_id
            && $status === 'confirmed'
            && $delivery->salesOrder instanceof SalesOrder
            && $this->isCaseReplacementOrder($case, $delivery->salesOrder);
    }

    private function affected(mixed $line): string
    {
        return bcadd((string) ($line->verified_missing_quantity ?? '0'), (string) ($line->verified_defective_quantity ?? '0'), 3);
    }

    private function billCoversShortage(ReturnCase $case, Bill $bill): bool
    {
        $case->loadMissing('lines.sourcePoItem', 'lines.item');
        $bill->loadMissing('items.item');
        foreach ($case->lines->groupBy('item_id') as $itemId => $lines) {
            $missing = $lines->reduce(fn ($sum, $line) => bcadd($sum, (string) ($line->verified_missing_quantity ?? '0'), 3), '0');
            if (bccomp($missing, '0', 3) <= 0) {
                continue;
            }
            $received = $lines->reduce(fn ($sum, $line) => bcadd($sum, $line->source_grn_item_id
                ? (string) $line->received_quantity : (string) ($line->sourcePoItem?->quantity_received ?? '0'), 3), '0');
            $billed = $bill->items->where('item_id', $itemId)->reduce(fn ($sum, $line) => bcadd($sum,
                $line->item ? $line->item->convertToBase((string) $line->quantity, $line->unit) : (string) $line->quantity, 3), '0');
            if (bccomp($billed, bcadd($received, $missing, 3), 3) < 0) {
                return false;
            }
        }

        return true;
    }

    private function creditMatchesSource(ReturnCase $case, CreditNote $credit): bool
    {
        if ($case->type->value === 'customer') {
            return $case->delivery_id !== null
                && $credit->invoice_id !== null
                && Invoice::query()->whereKey($credit->invoice_id)
                    ->where('delivery_id', $case->delivery_id)->exists();
        }

        if ($case->purchase_order_id === null || $credit->bill_id === null) {
            return false;
        }

        $bill = Bill::query()->whereKey($credit->bill_id)
            ->where('purchase_order_id', $case->purchase_order_id)
            ->when($case->goods_receipt_note_id !== null, fn ($q) => $q->where('goods_receipt_note_id', $case->goods_receipt_note_id))
            ->first();

        return $bill !== null && $this->billCoversShortage($case, $bill);
    }

    private function assertReusableReturn(ReturnCase $case, ReturnRequest $rma): void
    {
        if (ReturnCase::query()->where('return_request_id', $rma->id)->whereKeyNot($case->id)->exists()) {
            throw new BusinessRuleException('The source receipt already belongs to another return case. Review that case instead of reusing its return.');
        }

        $rma->load('items');
        $expected = $case->lines->mapWithKeys(function ($line): array {
            $key = $line->source_grn_item_id ?: $line->source_po_item_id;

            return [(string) $key => (string) $line->verified_defective_quantity];
        })->filter(fn ($quantity) => bccomp((string) $quantity, '0', 3) > 0);
        $actual = $rma->items->mapWithKeys(function ($line): array {
            $key = $line->source_grn_item_id ?: $line->source_po_item_id;
            $quantity = (bool) $line->receipt_recorded || bccomp((string) $line->returned_quantity, '0', 3) > 0
                ? (string) $line->returned_quantity : (string) $line->quantity;

            return [(string) $key => $quantity];
        });
        foreach ($expected as $key => $quantity) {
            if (! $actual->has($key) || bccomp((string) $actual->get($key), $quantity, 3) !== 0) {
                throw new BusinessRuleException('An existing return for this receipt does not exactly match this case quantity. Create a separate reviewed return.');
            }
        }
    }

    private function assertReturnCoverage(ReturnCase $case): void
    {
        $rma = $case->returnRequest;
        $rma->loadMissing('items');
        $expected = $case->lines->mapWithKeys(function ($line): array {
            $key = $line->source_delivery_item_id ?: ($line->source_grn_item_id ?: $line->source_po_item_id);

            return [(string) $key => (string) $line->verified_defective_quantity];
        })->filter(fn ($quantity) => bccomp((string) $quantity, '0', 3) > 0);
        $actual = $rma->items->mapWithKeys(function ($line): array {
            $key = $line->source_delivery_item_id ?: ($line->source_grn_item_id ?: $line->source_po_item_id);
            $quantity = $line->reversal_already_applied ? (string) $line->quantity : (string) ($line->returned_quantity ?? '0');

            return [(string) $key => $quantity];
        });
        foreach ($expected as $key => $quantity) {
            if (! $actual->has($key) || bccomp((string) $actual->get($key), (string) $quantity, 3) < 0) {
                throw new BusinessRuleException('The linked physical return does not cover every verified defective quantity.');
            }
        }
    }
}
