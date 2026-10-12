<?php

declare(strict_types=1);

namespace App\Modules\DSS;

use App\Modules\DSS\Services\DetectorRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * DSS module provider. Binds the detector registry as a singleton; core
 * detector registrations land here as detectors ship (W0-5+). Deploying a
 * detector (register) and turning it on (dss_parameters) stay separate —
 * the kill switch in DssParameterService can always silence the engine.
 */
class DssServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DetectorRegistry::class, static function (): DetectorRegistry {
            $registry = new DetectorRegistry;

            // Core detectors register here, one line each, as they ship.
            // $registry->register((new MasterHygieneDetector)->id(), MasterHygieneDetector::class);

            return $registry;
        });
    }
}
