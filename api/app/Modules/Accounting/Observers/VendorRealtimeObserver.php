<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Observers;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\VendorRealtimeService;

/** Captures vendor master-data writes from Eloquent services and imports. */
final class VendorRealtimeObserver
{
    public function created(Vendor $vendor): void
    {
        app(VendorRealtimeService::class)->stage($vendor, 'created');
    }

    public function updated(Vendor $vendor): void
    {
        $action = $vendor->wasChanged('is_active') || $vendor->wasChanged('deleted_at')
            ? 'status_changed'
            : 'updated';
        app(VendorRealtimeService::class)->stage($vendor, $action);
    }

    public function deleted(Vendor $vendor): void
    {
        app(VendorRealtimeService::class)->stage($vendor, 'deleted');
    }
}
