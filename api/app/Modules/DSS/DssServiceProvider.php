<?php

declare(strict_types=1);

namespace App\Modules\DSS;

use App\Modules\DSS\Detectors\ApprovalBottleneckDetector;
use App\Modules\DSS\Detectors\AtRiskOrderDetector;
use App\Modules\DSS\Detectors\CollectionPriorityDetector;
use App\Modules\DSS\Detectors\MasterHygieneDetector;
use App\Modules\DSS\Detectors\NcrSlaBreachDetector;
use App\Modules\DSS\Detectors\PrePayrollChecklistDetector;
use App\Modules\DSS\Detectors\StuckDocumentDetector;
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
            $registry->register(app(MasterHygieneDetector::class)->id(), MasterHygieneDetector::class);
            $registry->register((new ApprovalBottleneckDetector)->id(), ApprovalBottleneckDetector::class);
            $registry->register(NcrSlaBreachDetector::ID, NcrSlaBreachDetector::class);
            $registry->register(StuckDocumentDetector::ID, StuckDocumentDetector::class);
            $registry->register(CollectionPriorityDetector::ID, CollectionPriorityDetector::class);
            $registry->register(AtRiskOrderDetector::ID, AtRiskOrderDetector::class);
            $registry->register(PrePayrollChecklistDetector::ID, PrePayrollChecklistDetector::class);

            return $registry;
        });
    }
}
