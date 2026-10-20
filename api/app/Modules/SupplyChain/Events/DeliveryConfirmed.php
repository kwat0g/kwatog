<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Events;

use App\Common\Events\ToleratesNewerModelState;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Task A4 — Fired by DeliveryService::confirm() once the customer has
 * confirmed receipt. The optional invoice ID is populated when the fast
 * invoice handoff succeeds; a separate DeliveryInvoiceRequested event is
 * emitted when Accounting recovery is required.
 */
class DeliveryConfirmed implements ToleratesNewerModelState
{
    use Dispatchable, SerializesModels;

    /** @var array<string, mixed> */
    public array $emailSnapshot = [];

    public function __construct(
        public readonly Delivery $delivery,
        public readonly ?int $invoiceId = null,
        array $emailSnapshot = [],
    ) {
        if ($emailSnapshot !== []) {
            $this->emailSnapshot = $emailSnapshot;

            return;
        }

        $delivery->loadMissing('salesOrder:id,so_number,customer_id');
        $customer = $delivery->salesOrder?->customer_id
            ? Customer::query()->find($delivery->salesOrder->customer_id)
            : null;

        $this->emailSnapshot = [
            'delivery_id' => (int) $delivery->getKey(),
            'delivery_hash_id' => (string) $delivery->hash_id,
            'delivery_number' => (string) $delivery->delivery_number,
            'sales_order_number' => (string) ($delivery->salesOrder?->so_number ?? ''),
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'receiver_name' => $delivery->receiver_name,
            'receiver_position' => $delivery->receiver_position,
            'delivery_remarks' => $delivery->delivery_remarks,
            'customer_email' => $customer?->email,
            'customer_name' => $customer?->name,
            'customer_contact_name' => $customer?->contact_person,
            'invoice_number' => $this->invoiceId
                ? Invoice::query()->whereKey($this->invoiceId)->value('invoice_number')
                : null,
        ];
    }
}
