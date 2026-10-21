<?php

declare(strict_types=1);

namespace App\Providers;

use App\Common\Listeners\NotifySystemAdminsOnQueueLongWait;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Events\LongWaitDetected;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        Event::listen(LongWaitDetected::class, [NotifySystemAdminsOnQueueLongWait::class, 'handle']);

        /*
         * Horizon's default authorisation short-circuits to "allowed" whenever
         * APP_ENV=local. This deployment runs the dev compose stack on the VPS,
         * so that default would publish the whole queue dashboard — job
         * payloads included — to the internet. Enforcing the gate explicitly
         * (rather than relying on the environment) keeps the dashboard closed
         * regardless of APP_ENV.
         *
         * Same gate as the viewHorizon definition below: one rule, two paths.
         */
        Horizon::auth(function ($request): bool {
            $user = $request->user();

            return $user instanceof User
                && Gate::forUser($user)->allows('viewHorizon');
        });
    }

    /**
     * Register the Horizon gate.
     *
     * Queue internals are IT territory: system_admin operates the worker
     * fleet. Business roles never appear here — and the SPA has no link to
     * this page, so there is nothing to discover from the app.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user = null): bool {
            return $user?->role?->slug === 'system_admin';
        });
    }
}
