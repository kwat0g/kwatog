<?php

declare(strict_types=1);

namespace App\Modules\DSS\Contracts;

/**
 * The ONE contract every decision capability implements. No scattered one-off
 * logic: collectors/queries → feature builders → detectors → scorer →
 * deduplicator → publisher → action handler → feedback tracker.
 *
 * Detectors are independently enable/disable-able, isolated (one failure never
 * stops others), idempotent (dedupe key per emission), queue-friendly, and
 * time/memory-budgeted. Execution modes: shadow (compute + log, don't display)
 * and advisory (display). Business actions remain in existing workflows.
 */
interface DetectorInterface
{
    /** Stable machine id, e.g. `inventory.master_hygiene`. Never renamed. */
    public function id(): string;

    /** Module/chain this detector belongs to, e.g. `inventory`. */
    public function domain(): string;

    /** Permission slugs allowed to SEE this detector's output (scoping). */
    public function ownerPermissions(): array;

    /** Detector version pinned on every emission (explainability + rollback). */
    public function version(): string;

    /** Parameter defaults; overridden at runtime via DssParameterService. */
    public function defaultParams(): array;

    /**
     * Run the detector. MUST be side-effect free on business tables: it returns
     * proposed emissions; the runner persists them (W0-2). MUST chunk queries
     * and respect $context['limit'] / $context['time_budget_ms'].
     *
     * @param  array{limit?: int, time_budget_ms?: int}  $context
     */
    public function detect(array $context = []): DetectorScanResult;
}
