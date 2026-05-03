<?php

declare(strict_types=1);

namespace App\Providers;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Observers\JournalEntryObserver;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Listeners\CheckReorderPoint;
use App\Modules\MRP\Events\MachineStatusChanged;
use App\Modules\Production\Listeners\HandleMachineBreakdown;
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
    }
}
