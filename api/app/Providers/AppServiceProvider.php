<?php

declare(strict_types=1);

namespace App\Providers;

use App\Common\Services\SettingsService;
use App\Modules\HR\Exports\EmployeeMasterExport;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Observers\JournalEntryObserver;
use App\Modules\Accounting\Listeners\NotifyFinanceOnDeliveryConfirmed;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use App\Modules\CRM\Listeners\NotifyOnSalesOrderConfirmed;
use App\Modules\HR\Events\ClearanceFullySigned;
use App\Modules\HR\Events\EmployeeCreated;
use App\Modules\HR\Events\SeparationInitiated;
use App\Modules\HR\Listeners\DeactivateAccountOnClearanceComplete;
use App\Modules\HR\Listeners\InitializeLeaveBalances;
use App\Modules\HR\Listeners\NotifyOnSeparationInitiated;
use App\Modules\Inventory\Events\GoodsReceiptNoteCreated;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Listeners\CheckReorderPoint;
use App\Modules\MRP\Events\MachineStatusChanged;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Payroll\Listeners\NotifyEmployeesOnPayrollFinalized;
use App\Modules\Production\Events\WorkOrderCompleted;
use App\Modules\Production\Listeners\HandleMachineBreakdown;
use App\Modules\Purchasing\Events\PurchaseOrderApproved;
use App\Modules\Purchasing\Events\PurchaseRequestApproved;
use App\Modules\Purchasing\Listeners\NotifyOnPurchaseOrderApproved;
use App\Modules\Purchasing\Listeners\NotifyOnPurchaseRequestApproved;
use App\Modules\Quality\Events\InspectionFailed;
use App\Modules\Quality\Events\InspectionPassed;
use App\Modules\Quality\Listeners\CreateDeliveryDraftOnQcPass;
use App\Modules\Quality\Listeners\RejectGRNOnQcFail;
use App\Modules\Quality\Listeners\TriggerIncomingQC;
use App\Modules\Quality\Listeners\TriggerOutgoingQC;
use App\Modules\SupplyChain\Events\DeliveryConfirmed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class, fn ($app) => new SettingsService());
    }

    public function boot(): void
    {
        // Series E (Task E2) — register exportable columns once per process.
        // ColumnSelectorModal in the SPA reads these from
        // GET /api/v1/exports/{module}/columns.
        EmployeeMasterExport::registerColumns();

        // Keep N+1 detection + lazy-loading prevention in non-prod, but allow
        // accessing attributes that weren't selected in column-restricted
        // eager loads. The latter caused dozens of MissingAttributeException
        // 500s where a Resource read e.g. vendor.contact_person while the
        // service projected only `vendor:id,name`. Tightening every projection
        // by hand is a never-ending audit; the runtime cost of returning the
        // missing column as null is negligible.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        // NOTE: deliberately NOT calling preventAccessingMissingAttributes()
        // — see comment above.

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Sprint 4: invalidate financial-statement caches on JE mutation.
        JournalEntry::observe(JournalEntryObserver::class);

        // Sprint 5: low-stock auto-replenishment listener.
        Event::listen(StockMovementCompleted::class, [CheckReorderPoint::class, 'handle']);

        // Sprint 6 Task 56: machine breakdown / restoration handling.
        Event::listen(MachineStatusChanged::class, [HandleMachineBreakdown::class, 'handle']);

        // Task A4: Notify Finance when a delivery is confirmed (draft invoice ready).
        Event::listen(DeliveryConfirmed::class, [NotifyFinanceOnDeliveryConfirmed::class, 'handle']);

        // ─── Series C — Chain orchestrator listeners (C1, C2, C3) ─────
        // C1 Order-to-Cash
        Event::listen(SalesOrderConfirmed::class, [NotifyOnSalesOrderConfirmed::class, 'handle']);
        Event::listen(WorkOrderCompleted::class,  [TriggerOutgoingQC::class,           'handle']);
        Event::listen(InspectionPassed::class,    [CreateDeliveryDraftOnQcPass::class, 'handle']);

        // C2 Procure-to-Pay
        Event::listen(GoodsReceiptNoteCreated::class, [TriggerIncomingQC::class,                'handle']);
        Event::listen(PurchaseRequestApproved::class, [NotifyOnPurchaseRequestApproved::class,  'handle']);
        Event::listen(PurchaseOrderApproved::class,   [NotifyOnPurchaseOrderApproved::class,    'handle']);
        Event::listen(InspectionFailed::class,        [RejectGRNOnQcFail::class,                'handle']);

        // C3 Hire-to-Retire
        Event::listen(EmployeeCreated::class,         [InitializeLeaveBalances::class,             'handle']);
        Event::listen(SeparationInitiated::class,     [NotifyOnSeparationInitiated::class,         'handle']);
        Event::listen(ClearanceFullySigned::class,    [DeactivateAccountOnClearanceComplete::class,'handle']);
        Event::listen(PayrollPeriodFinalized::class,  [NotifyEmployeesOnPayrollFinalized::class,   'handle']);
    }
}
