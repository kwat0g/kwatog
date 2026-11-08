<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Observers;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Services\CustomerRealtimeService;

final class CustomerRealtimeObserver
{
    public function created(Customer $customer): void
    {
        app(CustomerRealtimeService::class)->stage($customer, 'created');
    }

    public function updated(Customer $customer): void
    {
        if ($customer->wasChanged()) {
            app(CustomerRealtimeService::class)->stage($customer, 'updated');
        }
    }

    public function deleted(Customer $customer): void
    {
        app(CustomerRealtimeService::class)->stage($customer, 'deleted');
    }

    public function restored(Customer $customer): void
    {
        app(CustomerRealtimeService::class)->stage($customer, 'restored');
    }
}
