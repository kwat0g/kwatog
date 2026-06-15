<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Events;

use App\Modules\Purchasing\Models\SupplierPerformanceSnapshot;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * T3.3.C — Fired afterCommit at the end of SupplierPerformanceService::compute().
 * Decoupled hook for downstream automation (deterioration alerts, B2B portal
 * notifications, KPI dashboards). Dispatched per (vendor, year, month).
 */
class SupplierPerformanceComputed
{
    use Dispatchable, SerializesModels;

    public function __construct(public SupplierPerformanceSnapshot $snapshot) {}
}
