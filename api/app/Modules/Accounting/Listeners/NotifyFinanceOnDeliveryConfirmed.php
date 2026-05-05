<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Events\DeliveryConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Task A4 — On DeliveryConfirmed, notify Finance Officer that a draft
 * invoice is ready for review.
 */
class NotifyFinanceOnDeliveryConfirmed implements ShouldQueue
{
    public function handle(DeliveryConfirmed $event): void
    {
        try {
            $invoice = $event->invoiceId ? Invoice::find($event->invoiceId) : null;

            $message = $invoice
                ? "Delivery {$event->delivery->delivery_number} confirmed. Draft invoice {$invoice->invoice_number} ready for review."
                : "Delivery {$event->delivery->delivery_number} confirmed. Manual invoicing required.";

            $financeUsers = User::query()
                ->whereHas('role', fn ($q) => $q->where('slug', 'finance_officer'))
                ->where('is_active', true)
                ->get();

            foreach ($financeUsers as $user) {
                $user->notifications()->create([
                    'id'              => (string) Str::uuid(),
                    'type'            => 'auto_invoice_draft',
                    'notifiable_type' => $user::class,
                    'notifiable_id'   => $user->id,
                    'data'            => [
                        'delivery_id'     => $event->delivery->hash_id,
                        'delivery_number' => $event->delivery->delivery_number,
                        'invoice_id'      => $invoice?->hash_id,
                        'invoice_number'  => $invoice?->invoice_number,
                        'message'         => $message,
                        'link'            => $invoice
                            ? "/accounting/invoices/{$invoice->hash_id}"
                            : "/supply-chain/deliveries/{$event->delivery->hash_id}",
                    ],
                    'read_at'         => null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('NotifyFinanceOnDeliveryConfirmed failed', ['error' => $e->getMessage()]);
        }
    }
}
