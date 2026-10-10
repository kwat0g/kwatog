<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Listeners;

use App\Modules\ReturnManagement\Events\ReturnRequestUpdated;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\ReturnManagement\Services\ReturnCaseService;
use App\Modules\SupplyChain\Events\DeliveryConfirmed;

class CompleteCustomerReplacement
{
    public function handle(DeliveryConfirmed|ReturnRequestUpdated $event): void
    {
        $cases = $event instanceof DeliveryConfirmed
            ? ReturnCase::query()->where('replacement_sales_order_id', $event->delivery->sales_order_id)
            : ReturnCase::query()->where('return_request_id', $event->returnRequest->id);
        foreach ($cases->where('type', 'customer')->where('resolution', 'redelivery')->where('status', 'in_progress')->pluck('id') as $id) {
            app(ReturnCaseService::class)->synchronizeReplacement((int) $id);
        }
    }
}
