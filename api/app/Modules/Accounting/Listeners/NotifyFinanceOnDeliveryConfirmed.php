<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Events\DeliveryConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyFinanceOnDeliveryConfirmed implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(DeliveryConfirmed $event): void
    {
        try {
            $invoice = $event->invoiceId ? Invoice::find($event->invoiceId) : null;

            $invoiceLabel = $invoice?->invoice_number ?? '(draft)';
            $message = $invoice
                ? "Delivery {$event->delivery->delivery_number} confirmed. Draft invoice {$invoiceLabel} ready."
                : "Delivery {$event->delivery->delivery_number} confirmed. Manual invoicing required.";

            $link = $invoice
                ? "/accounting/invoices/{$invoice->hash_id}"
                : "/supply-chain/deliveries/{$event->delivery->hash_id}";

            $financeUsers = User::query()
                ->whereHas('role', fn ($q) => $q->where('slug', 'finance_officer'))
                ->where('is_active', true)
                ->get();

            $this->notifications->send($financeUsers, 'chain.delivery_confirmed', [
                'title'       => "Delivery {$event->delivery->delivery_number} Confirmed",
                'message'     => $message,
                'link_to'     => $link,
                'entity_type' => 'delivery',
                'entity_id'   => $event->delivery->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyFinanceOnDeliveryConfirmed failed', ['error' => $e->getMessage()]);
        }
    }
}
