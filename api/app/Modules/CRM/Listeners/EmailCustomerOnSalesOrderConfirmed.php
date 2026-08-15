<?php

declare(strict_types=1);

namespace App\Modules\CRM\Listeners;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use App\Modules\CRM\Mail\CustomerSalesOrderConfirmedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailCustomerOnSalesOrderConfirmed implements ShouldQueue
{
    public int $tries = 3;

    public function handle(SalesOrderConfirmed $event): void
    {
        $salesOrder = $event->salesOrder->loadMissing([
            'customer',
            'items.product',
        ]);

        $fallback = app(EmailDeliveryFailureNotifier::class);
        $context = [
            'link_to' => '/crm/sales-orders/'.$salesOrder->hash_id,
            'entity_type' => 'sales_order',
            'entity_id' => $salesOrder->hash_id,
            'reason' => 'The customer email was missing, invalid, unreachable, or rejected by the email provider.',
        ];
        $feature = 'Customer sales order confirmation';

        if (! filter_var($salesOrder->customer?->email, FILTER_VALIDATE_EMAIL)) {
            $fallback->notifyPermission(
                'crm.sales_orders.view',
                $feature,
                "Sales order {$salesOrder->so_number} was confirmed, but the customer has no usable email address. Review the order and contact the customer through an approved channel.",
                $context,
            );

            return;
        }

        try {
            Mail::to($salesOrder->customer->email)->queue(new CustomerSalesOrderConfirmedMail(
                $salesOrder,
                $this->fallbackUserIds(),
            ));
        } catch (\Throwable $e) {
            $fallback->notifyPermission(
                'crm.sales_orders.view',
                $feature,
                "The confirmation email for sales order {$salesOrder->so_number} could not be accepted by the email provider. Contact the customer through an approved channel.",
                $context,
            );
            Log::warning('Customer sales order email enqueue failed', [
                'sales_order_id' => $salesOrder->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return list<int> */
    private function fallbackUserIds(): array
    {
        return app(EmailDeliveryFailureNotifier::class)
            ->userIdsWithPermission('crm.sales_orders.view');
    }
}
