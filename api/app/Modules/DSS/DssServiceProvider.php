<?php

declare(strict_types=1);

namespace App\Modules\DSS;

use App\Modules\DSS\Detectors\ApprovalBottleneckDetector;
use App\Modules\DSS\Detectors\ChainBottleneckDetector;
use App\Modules\DSS\Detectors\MasterHygieneDetector;
use App\Modules\DSS\Detectors\NcrSlaDetector;
use App\Modules\DSS\Detectors\PayrollAnomalySummaryDetector;
use App\Modules\DSS\Detectors\PreventiveMaintenanceDetector;
use App\Modules\DSS\Detectors\ReceivablesPriorityDetector;
use App\Modules\DSS\Detectors\SuspectMaterialLotTraceDetector;
use App\Modules\DSS\Detectors\ThreeWayMatchReviewDetector;
use App\Modules\DSS\Detectors\TrainingExpirySummaryDetector;
use App\Modules\DSS\Detectors\WorkOrderInterventionDetector;
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
            $registry->register((new MasterHygieneDetector)->id(), MasterHygieneDetector::class);
            $registry->register('dss.chain_bottlenecks', ChainBottleneckDetector::class);
            $registry->register('dss.approval_bottlenecks', ApprovalBottleneckDetector::class);
            $registry->register('dss.quality_ncr_sla', NcrSlaDetector::class);
            $registry->register('dss.receivables_priority', ReceivablesPriorityDetector::class);
            $registry->register('dss.payroll_anomaly_summary', PayrollAnomalySummaryDetector::class);
            $registry->register('dss.preventive_maintenance_overdue', PreventiveMaintenanceDetector::class);
            $registry->register('dss.work_order_intervention', WorkOrderInterventionDetector::class);
            $registry->register('dss.training_expiry_summary', TrainingExpirySummaryDetector::class);
            $registry->register('dss.three_way_match_review', ThreeWayMatchReviewDetector::class);
            $registry->register('dss.quality_suspect_material_lot', SuspectMaterialLotTraceDetector::class);

            return $registry;
        });
    }
}
