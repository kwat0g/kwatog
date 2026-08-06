<?php

declare(strict_types=1);

namespace App\Modules\B2B\Middleware;

use Closure;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\B2B\Models\PortalShippingDocument;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Quality\Models\PpapSubmission;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class B2BTenancyScopeMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (auth('customer_portal')->check()) {
            $customerId = auth('customer_portal')->user()->customer_id;

            $scope = function (Builder $builder) use ($customerId) {
                $builder->where($builder->getModel()->getTable() . '.customer_id', $customerId);
            };

            SalesOrder::addGlobalScope('b2b_tenancy', $scope);
            Invoice::addGlobalScope('b2b_tenancy', $scope);
            CustomerComplaint::addGlobalScope('b2b_tenancy', $scope);
            DeliverySchedule::addGlobalScope('b2b_tenancy', $scope);

            Delivery::addGlobalScope('b2b_tenancy', function (Builder $builder) use ($customerId) {
                $builder->whereHas('salesOrder', function ($q) use ($customerId) {
                    $q->where('customer_id', $customerId);
                });
            });
        } elseif (auth('supplier_portal')->check()) {
            $vendorId = auth('supplier_portal')->user()->vendor_id;

            $scope = function (Builder $builder) use ($vendorId) {
                $builder->where($builder->getModel()->getTable() . '.vendor_id', $vendorId);
            };

            PurchaseOrder::addGlobalScope('b2b_tenancy', $scope);
            Bill::addGlobalScope('b2b_tenancy', $scope);
            DeliverySchedule::addGlobalScope('b2b_tenancy', $scope);
            GoodsReceiptNote::addGlobalScope('b2b_tenancy', $scope);
            PpapSubmission::addGlobalScope('b2b_tenancy', $scope);

            PortalShippingDocument::addGlobalScope('b2b_tenancy', function (Builder $builder) use ($vendorId) {
                $builder->whereHas('purchaseOrder', function ($q) use ($vendorId) {
                    $q->where('vendor_id', $vendorId);
                });
            });
        }

        return $next($request);
    }
}
