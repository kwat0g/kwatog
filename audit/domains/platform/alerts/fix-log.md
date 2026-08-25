# M008 — alerts fix log

Audit baseline: 2026-08-24  
Implementation session: 2026-08-25  
Final disposition: Needs Re-audit

## Claim and scope

- Refreshed the registry and atomically claimed platform / alerts (M008)
  while it was Plan Ready, the first unlocked plan in the priority list.
- Resumed the existing plan as instructed; discovery and plan-writing were not
  repeated.
- Preserved unrelated worktree changes. No files in the separately audited
  chain-monitoring or dashboards-kpis modules were modified.

## Implemented fixes

1. **F-001 — condition identity and atomic engine raises (partial handoff)**

   - Before: AlertEngineService::raise() used a recent-row check-then-insert
     (audit-report.md, F-001 evidence).
   - After: api/app/Common/Models/Alert.php:26-80 derives a stable condition
     key; api/database/migrations/2026_08_25_230000_harden_alert_lifecycle.php:21-96
     backfills it and adds the partial unique index
     alerts_one_open_condition_unique; and
     api/app/Common/Services/AlertEngineService.php:64-150 uses a
     transaction/row lock with post-rollback unique-conflict recovery.
   - Remaining: api/app/Console/Commands/RunChainBottleneckCheck.php:51-81
     still has a direct producer-side dedupe/insert and belongs to the
     chain-monitoring integration boundary. It needs a follow-up to route all
     producers through the same conflict-safe path.

2. **F-002 — durable critical-email delivery state and retries**

   - Before: only notified_email_at existed and a failed handoff was never
     retried.
   - After: delivery columns are added/backfilled by
     api/database/migrations/2026_08_25_230000_harden_alert_lifecycle.php:26-31;
     claim/backoff/terminal-failure handling is in
     api/app/Common/Services/AlertEngineService.php:179-222,751-874;
     fallback failure is contained and logged at :805-822; and the scheduled
     sweep is exposed by api/app/Console/Commands/RetryCriticalAlertEmails.php:10-34
     and api/routes/console.php:59-64.
   - api/app/Common/Notifications/CriticalAlertEmail.php:11-38 remains a
     synchronous transport handoff whose outcome is recorded by alert state.

3. **F-003 — explicit inclusive AP due-soon window**

   - Before: only the exact date today + N was selected.
   - After: api/app/Common/Services/AlertEngineService.php:548-569 selects
     today through today + N, excludes overdue bills, and records the computed
     offset; the setting label/description is aligned by
     api/database/migrations/2026_08_25_230100_align_alert_window_setting.php:8-26.

4. **F-004 — current-condition lifecycle, recovery, and rearm**

   - Before: is_dismissed = false was treated as active forever.
   - After: api/app/Common/Models/Alert.php:93-103 separates unresolved
     current state from dismissal; successful checks resolve unobserved
     conditions in api/app/Common/Services/AlertEngineService.php:275-289,701-739;
     stable-key raises rearm after resolution at :91-120; and the focused
     test covers reuse and rearm at
     api/tests/Feature/Alerts/AlertsHardeningTest.php:25-57.
   - Remaining: dashboard SQL readers in
     api/app/Modules/Dashboard/Services/DashboardWidgetDataService.php:152
     and api/app/Modules/Dashboard/Services/AdminDashboardService.php:366-376
     still need the dashboards-kpis follow-up to filter resolved_at.

5. **F-005 — bounded resolved-alert retention**

   - Before: no alert archive/prune path existed.
   - After: AlertEngineService::pruneResolved() only deletes resolved rows
     older than the requested window at
     api/app/Common/Services/AlertEngineService.php:219-227; the command is
     api/app/Console/Commands/PruneResolvedAlerts.php:10-29; and the monthly
     schedule is registered in api/routes/console.php:239-244. The focused
     test protects open rows at
     api/tests/Feature/Alerts/AlertsHardeningTest.php:230-251.

6. **F-006 — bounded threshold scans and run-scoped observability**

   - Before: checks loaded whole tables and performed repeated per-row model
     lookups.
   - After: inventory, production, finance, and quality checks use chunked or
     cursor-based queries, joins, and batched lookups in
     api/app/Common/Services/AlertEngineService.php:288-610.
     runAllChecks() tracks created alerts by severity and type and isolates
     successful-check recovery at :232-284; RunAlertEngine reports the
     run-scoped severity data at api/app/Console/Commands/RunAlertEngine.php:19-36.

7. **F-007/F-008 — SPA contract and pagination**

   - Before: chain_bottleneck and scheduler_stale were absent from the client,
     and the page was fixed to its first 50 rows.
   - After: types and critical-delivery fields are represented at
     spa/src/types/alerts.ts:7-60, API pagination/options are typed at
     spa/src/api/alerts.ts:9-40, and the page maps both icons plus accessible
     pagination at spa/src/pages/alerts/index.tsx:22-41,244-251.

8. **F-009/F-010 — coherent shared read/count behavior and strict filters**

   - Before: the read route was unused, unread-count ignored is_read, and
     is_dismissed values were loosely coerced.
   - After: read/dismiss writes use transactions at
     api/app/Common/Services/AlertEngineService.php:152-180; the API list
     and unread count require unresolved active state at
     api/app/Common/Controllers/AlertController.php:20-64,90-98;
     request normalization/validation is explicit at
     api/app/Common/Requests/ListAlertsRequest.php:14-45; and the SPA wires
     the read action, preserves dismissed history, and shows the correct
     active/dismissed total at spa/src/pages/alerts/index.tsx:69-86,104-131,192-223.
   - The existing global is_read model is retained as a shared
     acknowledgement queue; a per-user inbox would require a separate
     product decision and schema.

9. **Focused acceptance coverage**

   - Added api/tests/Feature/Alerts/AlertsHardeningTest.php covering condition
     reuse/rearm, successful recovery, run-scoped type counts, critical
     delivery state, AP boundaries, unread/filter behavior, and retention
     safety.
   - Isolated PostgreSQL runtime assertions passed for critical failure
     persistence, retry backoff, and rearm. PHP syntax checks and Pint passed
     for all changed PHP files; targeted alert SPA ESLint passed with zero
     warnings. The three alert commands appear in artisan list.

## Deferred / re-audit required

- **F-001 cross-module producer coverage:** chain-monitoring’s direct insert
  remains outside this module’s safe edit scope.
- **F-004 dashboard consumers:** dashboards-kpis owns the raw dashboard count
  queries and should align them with the new unresolved lifecycle.
- **F-006/F-010 acceptance depth:** volume/query-budget measurements,
  PostgreSQL two-connection interleaving, provider rejection/recovery, and
  full route-permission coverage still need a dedicated verification pass.
- **F-012 policy question:** no default audience was seeded for
  scheduler_stale or mrp_run_failed. The existing role map remains at
  api/database/migrations/0417_seed_alert_critical_notification_roles.php:12-33;
  an accountable audience must be confirmed by the product owner before a
  migration changes it.
- Full SPA typecheck remains blocked by unrelated pre-existing errors in
  spa/src/components/layout/Sidebar.tsx:667,
  spa/src/pages/assets/detail.tsx:6,67, and
  spa/src/pages/return-management/detail.tsx:902.
