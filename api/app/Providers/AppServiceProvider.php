<?php

declare(strict_types=1);

namespace App\Providers;

use App\Common\Services\SettingsService;
use Illuminate\Database\Eloquent\Model;
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
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::unguard(false);

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
