<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Series C — Task C5. Chain bottleneck thresholds.
|--------------------------------------------------------------------------
|
| Each detector queries entities stuck at the same chain step longer than
| `hours`. ChainBottleneckService loops these definitions and produces
| rows for the dashboard widget; RunChainBottleneckCheck persists each
| stuck record as an Alert (type=ChainBottleneck) so the alert centre
| picks them up too.
|
| `audience` is informational — it tells operators which role is expected
| to act on the bottleneck. The dashboard widget filters by audience per
| logged-in user.
*/

return [

    'bottlenecks' => [

        /*
         * Sales orders that ran MRP but never advanced to in_production.
         * 48h is generous — anything longer suggests a missing
         * production-schedule confirmation.
         */
        'so_at_mrp_planned' => [
            'label'    => 'SO awaiting production',
            'hours'    => 48,
            'audience' => 'ppc_head',
        ],

        /*
         * Work orders confirmed but not started. Usually means warehouse
         * has not issued materials. 24h is the operating-floor norm.
         */
        'wo_confirmed_unstarted' => [
            'label'    => 'WO awaiting material issue',
            'hours'    => 24,
            'audience' => 'warehouse_staff',
        ],

        /*
         * Outgoing inspections sitting in pending. QC team should be on
         * top of these — 4h is the SLA per IATF QC plan.
         */
        'inspection_outgoing_pending' => [
            'label'    => 'Outgoing QC pending',
            'hours'    => 4,
            'audience' => 'qc_inspector',
        ],

        /*
         * Deliveries booked but never dispatched.
         */
        'delivery_scheduled_overdue' => [
            'label'    => 'Delivery scheduled but not dispatched',
            'hours'    => 24,
            'audience' => 'impex_officer',
        ],

        /*
         * Draft invoices that Finance hasn't finalized. 24h SLA.
         */
        'invoice_draft_overdue' => [
            'label'    => 'Invoice draft awaiting finalization',
            'hours'    => 24,
            'audience' => 'finance_officer',
        ],

        /*
         * Purchase requests pending approval beyond escalation window.
         * Task A7 (escalation) handles the route — this surfaces it on
         * the dashboard.
         */
        'pr_pending_overdue' => [
            'label'    => 'PR awaiting approval',
            'hours'    => 48,
            'audience' => 'next_approver',
        ],

        /*
         * Bills past their due date. 30 days = monthly review window.
         */
        'bill_unpaid_overdue' => [
            'label'    => 'Bill unpaid past due',
            'hours'    => 720,
            'audience' => 'finance_officer',
        ],

    ],

    /*
     * Audience → role slugs that should see this bottleneck on their
     * dashboard. Used by ChainBottleneckController to filter the response
     * for the requesting user. system_admin always sees everything.
     */
    'audience_to_roles' => [
        'ppc_head'         => ['ppc_head', 'plant_manager', 'system_admin'],
        'warehouse_staff'  => ['warehouse_staff', 'plant_manager', 'system_admin'],
        'qc_inspector'     => ['qc_inspector', 'plant_manager', 'system_admin'],
        'impex_officer'    => ['impex_officer', 'plant_manager', 'system_admin'],
        'finance_officer'  => ['finance_officer', 'system_admin'],
        'next_approver'    => ['plant_manager', 'finance_officer', 'system_admin'],
    ],

];
