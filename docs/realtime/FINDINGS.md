# Separate findings

Functional and test-harness defects found during the cache/real-time work are recorded here with their scope and disposition; independently deferred issues remain separate review units.

## Deploy cache generation ran after workers started and omitted the mail worker (source-confirmed; fixed in Delta 867)

- **Evidence:** The GitHub workflow and `make deploy` started consumers before building runtime caches; `.github/workflows/deploy.yml` and `scripts/deploy-update.sh` omitted `event:cache`; the manual and GitHub deploys omitted `queue-mail` from rebuild/restart/health checks. `docker/php/Dockerfile.prod` sets `opcache.validate_timestamps=0`, so API must restart after config cache creation. This source inspection confirms ordering and worker-lifecycle gaps but does not prove they caused a reported stale screen.
- **Fix:** All three deploy paths stop API/consumers before migration, build config/route/event/view caches and run `realtime:config-check`, restart API after cache generation, then start fresh Nginx, Reverb, Horizon, `queue-mail`, and scheduler containers. GitHub smoke checks `queue-mail`; rollback restores previous code without rerunning migrations.
- **Verification:** `bash -n scripts/deploy-update.sh`, GitHub workflow YAML parsing, ordered-command checks across all three entry points, and `git diff --check` pass. No production deploy, worker restart, database/Redis mutation, or real-time browser scenario was run.
- **Classification:** Confirmed deployment lifecycle defect in source; not a reproduced symptom-level root cause. Keep production rollout separate and require a reviewed release.

## Scheduled export runner repeatedly returns failure in dev (runtime-confirmed; separate functional track)

- **Evidence:** On 2026-11-04 UTC, `scheduler:health --stale-minutes=15` exited 1 although scheduler ticks continued. The durable task ledger records repeated `exports:run-due` failures at five-minute intervals, most recently at approximately 21:50 UTC, with exit code 1. The scheduled command catches per-export exceptions, logs the underlying message as `scheduled-export-failed`, and returns failure if any due export failed; the durable scheduler ledger retains only Laravel's generic nonzero-exit message. Cache audit/prune tasks and outbox dispatch are recorded as successful in the same dev stack.
- **Impact:** Scheduler health remains degraded and scheduled exports may not advance. This is not evidence of cache staleness or broadcast delivery failure.
- **Classification:** Runtime-confirmed separate functional issue; exact failing export/configuration and underlying exception were not identified in this cache/realtime pass.
- **Disposition:** Track in its own export/scheduler review unit. Inspect the application log and affected schedule through authorized admin tooling before changing export data or disabling a schedule. No export record or business behavior was changed here.

## Employee DTR did not refetch after HR attendance correction (fixed and two-context verified)

- **Evidence:** The original two-context HR03-MISSING-PUNCH run showed HR's correction committed and the Employee's already-open DTR retained blank time-out/hours. The DTR query key used the self-service DTR prefix, which the attendance_changed invalidation map omitted.
- **Fix:** Added the DTR query prefix to the central attendance_changed invalidation map so active month-range DTR queries refetch through the Employee-authorized API.
- **Verification:** The red-first invalidation-map regression failed before the fix and passes after it. HR03-MISSING-PUNCH attempt 08 passed with Employee and HR browser contexts: the open Employee DTR showed 14:00 and 7.5 hours without reload, then retained the values after reload. The outbox contained one published created and one published updated AttendanceChanged event and the broadcast worker completed both. The step-bound interval was 1.654 seconds in this one run; it is not p95 or exact event-to-render latency. No raw WebSocket frame was archived. Attempt 07 had already passed the DTR update but stopped at an unrelated global Mailpit assertion; attempt 08 fixed that test assertion scope and completed.
- **Scope:** Realtime propagation only. HR attendance writes and DTR business rules remain unchanged. Separate detail/action/dashboard surfaces remain unverified.

## Bill action controls were inferred from client state (fixed in the Accounting slice; two-user symptom remains unverified)

- **Evidence:** `BillResource` returns workflow status and related payment/three-way-match fields but no `available_actions` or `permissions` projection (`api/app/Modules/Accounting/Resources/BillResource.php`). The Bill detail page decides Post bill, Record payment, and Cancel visibility from the fetched status/balance/related data plus the SPA permission snapshot (`spa/src/pages/accounting/bills/detail.tsx`). The API service still revalidates mutations; this source finding does not indicate an authorization bypass.
- **Impact:** The Bill response does not meet the architecture's server-computed action contract. If an event or refetch is delayed, the client can continue rendering an action from an older response. This page subscribes to the Bill refresh path, but no two-user scenario reproduced a stale Bill control or verified payment/three-way-match fan-out.
- **Classification:** Confirmed response/UI contract gap; not a confirmed reported-symptom root cause. A rough source scan found 54 status-bearing resources without an obvious action/permission key, but many are nested or read-only resources and were not classified as defects.
- **Disposition:** `BillResource` now returns viewer-scoped `available_actions` for list and detail responses. `BillService::list()` selects pending payment reservations with one aggregate subquery rather than an N+1 lookup. The projection accounts for current state, minimum/final payment amount, three-way override permission plus maker separation, linked supplier invoices, and viewer permissions; the SPA uses it for Post, Record payment, and Cancel. Existing mutation guards remain authoritative. One focused Bill lifecycle test passes (1 test / 23 assertions), including equal list/detail action maps; targeted ESLint, PHP syntax checks, PHP formatting for the resource/test, and the live Vite module request pass. `BillService.php` remains non-Pint-clean outside this small query change, so it was not autoformatted wholesale. No two-user Bill UI run was performed; matrix action/detail cells remain `NOT_VERIFIED`.

## Work Order primary lifecycle controls used client permissions and status (fixed in the Production slice; stale peer behavior remains unverified)

- **Evidence:** `WorkOrderResource` returned status and blockers but no viewer-scoped lifecycle actions. The detail page selected Confirm, Start, Pause, Resume, Complete, Close, Cancel, and Record Output from record fields plus the client permission store. A focused red-first route regression reproduced the missing projection: the PPC actor had `production.wo.cancel_stock` and could cancel a stock work order, but the response had no `available_actions.cancel` value.
- **Disposition:** `WorkOrderResource` now returns viewer-scoped action flags for the primary lifecycle controls, including stock-cancellation status/parent/output checks and separate permission scopes. The list query provides output existence with one `withExists` subquery. The detail page gates those primary buttons on the API flags while retaining API-provided readiness/blocker checks and existing server mutation guards. The focused regression passes 1 test / 14 assertions: the PPC role sees Cancel in list/detail but not Start, then the actor response changes to `cancel=false`, `status=cancelled`; targeted ESLint and PHP syntax checks pass. Operation-row controls still use their existing permission/state checks. No two-user Work Order event/UI scenario was run, so the route matrix remains `NOT_VERIFIED`.

## PostgreSQL rejected Admin session termination before it could revoke a session (fixed in delta 709)

- **Evidence:** The focused `DELETE /api/v1/admin/sessions/{session}` route test first reached the controller after its stateful Sanctum test setup was made explicit, then reproduced PostgreSQL SQLSTATE `0A000`: `FOR UPDATE` cannot lock the nullable side of the controller's `sessions LEFT JOIN users LEFT JOIN roles`. The request failed before the row deletion and realtime events.
- **Impact:** Admins could not terminate sessions on PostgreSQL, so a compromised or shared-device session remained valid. The route also refreshed only the admin session list; the target user's already-open tab could keep identity-scoped UI data until a later API request or focus refresh.
- **Classification:** Confirmed admin session revocation defect with a realtime invalidation gap.
- **Disposition:** Delta 709 changes the lock to target only `sessions` (`FOR UPDATE OF sessions`). After deletion, the same transaction stages the existing `AdminSessionsChanged` admin-list event and exact-user `UserAccessChanged` signal through the durable outbox. The SPA refetches `/auth/user`; its 401 path clears local authentication and scoped caches. The focused route test passes **1 test / 6 assertions**, including deletion, both outbox rows, exact recipient hash, and no session identifier in either payload. Delta 710 extends exact-user refresh to self-service password changes, reset-link credential changes, and `UserProvisioningService` deactivation/reset (employee archive and clearance completion) through `SessionRevocationService`; `UserAdminService::resetPassword()` uses this path, while explicit AdminUserService deactivation retains its existing batched refresh. Three focused existing cases pass (**3 tests / 14 assertions**) for self-service revocation and provisioning deactivation/reset, with PHPUnit warnings from the container's missing env file. No browser, socket delivery, or two-user run was performed, so affected UI matrix cells remain `NOT_VERIFIED`. This functional correction is logged separately for its own review unit.

## Overtime status writes accepted stale resource versions (fixed in delta 680)

- **Evidence:** A red-first API test loaded a Pending owner request, sent `If-Match: "0"` to the cancellation endpoint, and observed HTTP 200 plus a state change. Existing locked state checks prevented actions after a terminal transition but there was no record version to reject an old snapshot while the state still allowed the action.
- **Impact:** A stale client could make a write based on an outdated Overtime resource, and peers could see a competing change only after the next refresh. This is a concurrency-contract defect; no sensitive payload or cache isolation issue was involved.
- **Classification:** Confirmed module-level stale-write defect in a status-driven workflow.
- **Disposition:** Delta 680 adds a monotonic version, returns it in API resources and the refresh-only event, and checks optional `If-Match` after locking current state across approve/reject/cancel/restore and per-row bulk approval. Stale writes return typed 409; omission remains backward-compatible. The regression fails before and passes after the change. Focused API tests cover stale/current cancel, bulk stale rejection and event payload (4 tests / 13 assertions total). No peer-browser or live event delivery run was performed; matrix outcomes remain `NOT_VERIFIED`. Keep the module change independently reviewable.

## In-flight mutation could restore a cleared session's cached record (fixed in delta 676)

- **Evidence:** A red-first regression built a real TanStack mutation against the app's shared QueryClient, held its API response, cleared the QueryClient to model logout or an access-scope switch, then resolved the response. Before the fix, the mutation's `onSuccess` callback repopulated an unscoped private record key with `previous-session` data. A second red assertion confirmed the mutation promise also resolved that old response to its caller.
- **Impact:** Cache clearing alone did not prevent a late mutation callback from restoring a previous account's data after the auth boundary. This is a cross-session cache-isolation risk; it does not bypass the API authorization check.
- **Classification:** Confirmed SPA cache isolation defect in the shared mutation lifecycle.
- **Disposition:** Delta 676 adds a session revision to the shared QueryClient and captures it at mutation creation. Mutations queued across a clear are rejected before the API call; in-flight results are rejected after completion or at the success-callback boundary, and stale hook-level lifecycle callbacks are skipped. The focused regression, targeted ESLint, and focused TypeScript compilation pass. A project-wide SPA typecheck passed before the final callback-boundary assertion; the final adjustment was checked with the focused compiler. No two-user browser flow was run, so only the QueryClient lifecycle is proven here.

## Stalled broadcast worker could remain marked Live for ten seconds (detection tightened in delta 677)

- **Evidence:** The shared provider used a ten-second timer between aggregate delivery-health checks while the socket remained connected. A red-first provider regression returned `healthy` for the initial health request and `degraded` for the next response, advanced the active tab by two seconds, and observed no second request; the status still showed Live. No record payloads were fetched by this health probe.
- **Impact:** A connected-but-stalled broadcast lane could delay degraded-mode detection and authorized catch-up by up to one health interval, extending stale status/action views beyond the live freshness target.
- **Classification:** Confirmed shared realtime health-detection latency; source and provider-test reproduced, but not a live stopped-worker/browser reproduction.
- **Disposition:** Delta 677 sets the visible-tab aggregate health probe to two seconds. The provider test file passes 9/9 and targeted ESLint passes. This raises the expected rate from 6 to 30 inexpensive health requests per visible tab per minute; API/Redis load impact and live recovery timing are unmeasured. The test uses mocked endpoint responses, so no matrix runtime cell was promoted.

## Project-wide SPA typecheck restored (delta 675)

- **Evidence:** A fresh `npm run typecheck` in `spa/` exits 0 with no diagnostics. The remaining errors were a duplicate Position branch, incomplete pagination/action fields in Admin Role, Scheduled Export, Overtime, Training and Leave test fixtures, unsupported role-query options in Inspection tests, and an optional Return Management item dereference.
- **Disposition:** Removed the unreachable Position branch, completed the fixtures, changed the three role selectors to supported queries, and guarded the optional item list. Targeted ESLint also passes for the touched files. This supersedes the earlier non-green typecheck findings below; it does not provide runtime freshness or browser evidence.

## Duplicate Position event branch caused a TypeScript build error (fixed in delta 674; verified in delta 675)

- **Evidence:** `realtimeEventNameForEntity()` handled `position` near the top of the function, then repeated the same condition later. `npm run typecheck` reported TS2367 because the later comparison was unreachable after control-flow narrowing. The focused existing Position invalidation regression passes after removing the duplicate; targeted ESLint passes.
- **Impact:** The duplicate did not change runtime event naming, but the TypeScript error can fail frontend typecheck/build and block deployment of realtime-map changes.
- **Classification:** Confirmed frontend build/type correctness defect, separate from the stale-data behavior.
- **Disposition:** Removed the redundant second branch in delta 674. Delta 675's fresh project-wide typecheck exits 0; remaining diagnostics from that run were corrected as described above.

## Stock Adjustment stale decisions returned validation errors and kept stale controls open (fixed in delta 574)

- **Evidence:** A red-first API regression submitted a second approval after another checker had committed. `StockAdjustmentService::approve()` locked and rejected the current state, but `StockAdjustmentController` mapped the generic `BusinessRuleException` to HTTP 422. The React Query error handlers only reported the error and left the old confirmation open without refetching. After the fix, the focused API test passes with HTTP 409 and a stable `stock_adjustment_state_conflict` code; a focused page regression confirms the action dialog closes and the authorized list refetch removes the obsolete Approve control.
- **Impact:** A user with a stale open list could receive an ordinary validation failure and continue seeing an action that the server had already rejected as stale. The locked server check still prevented a second stock movement.
- **Classification:** Confirmed stale-state response/reconciliation gap, fixed for Stock Adjustment approve/reject/withdraw endpoints. Approve, reject and withdraw stale routes are covered by focused API tests; live two-user conflict behavior was not exercised.
- **Disposition:** Delta 574 returns a typed 409 for all three locked stale-state branches and has the page refetch on any 409. API verification: 2 tests / 12 assertions, including stale approve/reject/withdraw routes. SPA page verification: 4/4. No live two-user stale conflict, detail-page behavior, cache hit-rate or latency measurement was performed.

## Stock Count stale actions were reported as validation failures (fixed in delta 575)

- **Evidence:** A red-first HTTP test created and started a count session, then submitted Start again; the locked service rejected the current InProgress state, but the controller returned 422. Source tracing found the same generic exception mapping for count entry, variance approval, completion and terminal cancellation guards. The page's shared `refreshAfterRejectedAction` already refetches on 4xx and closes its confirm dialogs; count form input remains mounted across refetch.
- **Impact:** Stale client actions were surfaced as validation failures rather than clear state conflicts. Server row locks already prevented invalid transition side effects.
- **Classification:** Confirmed stale-state response contract gap across Stock Count state mutations.
- **Disposition:** Delta 575 maps only stale state/line preconditions to typed HTTP 409; unrelated validation, segregation-of-duties, tolerance and business-rule responses keep 422. The new API regression verifies five relevant endpoint paths (1 test / 20 assertions); the full `StockCountEmptyScopeTest` file passes 2 tests / 36 assertions, including the existing 422 business-rule case. No separate SPA component or live multi-user test ran.

## Transfer Order stale actions returned 422 and left the action open (fixed in delta 576)

- **Evidence:** A red-first API test executed a pending Transfer Order, then submitted Execute again and received 422. The row-lock guards prevented a duplicate movement, but the controller classified the stale status as generic business validation. Source review found the same behavior on stale Cancel; the page's mutation callbacks only showed a toast and neither closed the dialog nor refetched.
- **Impact:** A viewer who missed the event could keep seeing a pending action and receive an ordinary validation error when the server correctly refused it. No duplicate movement or incorrect cancellation was observed.
- **Classification:** Confirmed stale-state API/UI reconciliation gap for Transfer Order execute/cancel.
- **Disposition:** Delta 576 returns a stable typed 409 from both state guards; the page closes the stale confirmation and refetches current list/detail data. API regression passes 1 test / 8 assertions. No SPA component or live two-user test ran.

## Supplier RFQ reads can close records before vendor scope is enforced

- **Evidence:** The supplier list called `closeAllDue()` before the vendor-scoped list query, and detail called `closeDue($rfq)` before the invitation lookup. Two focused regressions reproduced both effects against a disposable PostgreSQL test database: reading the list as an uninvolved supplier cancelled another vendor's overdue RFQ, and requesting an uninvited RFQ detail returned 404 only after cancelling it. The closure can hand conversion state back to the linked Purchase Request. The regressions pass after the fix in PR [#518](https://github.com/kwat0g/kwatog/pull/518) (2 tests / 9 assertions).
- **Impact:** A supplier read could trigger deadline-driven transitions for an RFQ outside that supplier's invitation scope. The tests prove this state change; they do not show data disclosure or a user-visible incident. The scheduled deadline closer remains an expected system transition path.
- **Classification:** Confirmed authorization-order side effect, dynamically reproduced and fixed in B2B/Purchasing PR [#518](https://github.com/kwat0g/kwatog/pull/518), merged as `49bdb56f`.
- **Disposition:** Supplier list closure is now invitation-scoped to issued RFQs; detail checks the vendor invitation before lazy closure. The focused regressions pass (2 tests / 9 assertions). GitHub's Composer audit check failed on the unchanged dependency lockfile before PHPUnit ran. No broad API suite or E2E test ran.

## Focused cache verification exposed a Statement test defect and an unrelated outbox row

- **Evidence:** A serial 13-file API verification run completed 29 tests / 118 assertions and reported non-cache failures. `StatementCacheAfterCommitTest::test_statement_cache_tags_are_flushed_after_commit` originally errored because it invoked `JournalEntryObserver::saved()`, which is not an observer hook; the actual hooks are `created`, `updated`, and `deleted`. The Government Table test's original total-row assertion was also reproduced failing. Tracing the two rows showed one `GovernmentContributionTableChanged` status ping from `deactivate()` and one coalesced `AdminDashboardChanged` ping produced by audited `AuditLog` rows; the audit log is an observed admin-dashboard cache source. There was no duplicate Government Table event.
- **Impact:** Both test defects made the earlier focused verification inconclusive; neither established a duplicate production event or failed cache invalidation.
- **Classification:** Test-harness/assertion defects, fixed in delta 472; no production behavior change.
- **Disposition:** Delta 472 calls the real `created` hook in the Statement cache-after-commit test, which passes (1 test / 2 assertions). It scopes both Government Table assertions to `GovernmentContributionTableChanged`; the focused file passes (2 tests / 10 assertions). The cache-generation regression separately passes two failure-path cases (2 tests / 11 assertions). No full API suite or production mutation was run.

## Filtered test discovery was blocked by a duplicate PHP import (fixed in delta 601)

- **Evidence:** A historical API-container run of `php artisan test --filter=test_critical_shortage_creates_a_canonical_pending_approval_po_once` stopped while loading `AccountingPeriodRealtimeTest.php` because `AccountingPeriodStatus` was imported twice. Delta 601 removed the duplicate import; the corrected file passes PHP syntax lint. The API container continues to emit the existing missing `/var/www/.env` test-configuration warning.
- **Impact:** The duplicate import blocked filtered test discovery before delta 601; it was a test-harness issue and did not affect application behavior.
- **Disposition:** Fixed in delta 601. No broad suite was rerun to validate filtered discovery after the correction.


## Restoring a PR-linked PO originally left its source Purchase Request stale (fixed in delta 448)

- **Evidence:** `PurchaseOrderService::delete()` recalculated and broadcast the parent PR, while restore originally broadcast only the PO. The new focused service regression first failed because the PR stayed Approved after its only covering PO was restored; it also expected a second parent PR refresh row. Restore now calls the existing conversion-status recomputation helper after restoring the PO, inside the same locked transaction. The focused restore/delete regression passes (2 tests, 8 assertions), verifying the PR returns to Converted and both parent refresh paths are present.
- **Classification:** Source-confirmed linked-record state/propagation gap, fixed and producer-tested. No browser/API endpoint/two-user delivery or latency scenario ran; runtime UI cells remain `NOT_VERIFIED`.
- **Disposition:** Fixed in delta 448 by reusing existing conversion rules and the authorized Purchase Request refresh path. No policy, permission, or conversion rule changed.


## Supplier dispatch child status could leave the Purchase Order panel stale

- **Evidence:** `PurchaseOrderResource` serializes `supplier_dispatch` and the PO detail renders status-specific chips and guidance (`api/app/Modules/Purchasing/Resources/PurchaseOrderResource.php:63-88`; `spa/src/pages/purchasing/purchase-orders/detail.tsx:697-736`). Before delta 436, the queued `PrepareSupplierDispatch` listener could commit `Pending`, `ManualRequired`, or `Failed` after the initial approval ping, without a follow-up parent PO event. The direct failure writer was a query-builder update. Standalone reconciliation could also update `Confirmed` or `Cancelled` without refreshing the parent. Focused tests added for those paths failed before the fix because no matching PO refresh existed in `event_outbox`.
- **Fix:** `ChainBroadcaster::refreshPurchaseOrder()` stages the existing content-free `ChainStepAdvanced` signal through the durable outbox. `claim()` stages it atomically for a new/reclaimed Pending row; `complete()` stages it with ManualRequired; `recordFailure()` stages it in the same transaction as the guarded Failed write; standalone/recovery confirmation and cancellation stage it only when the child status changes. Synchronous `markAsSent`, cancel, and reject paths suppress the child ping because their enclosing PO transition already stages the PO event. The private PO chain/entity and supplier-vendor channels carry only the normal refresh signal; consumers refetch from their permission-scoped API.
- **Verification:** Five focused regressions first failed (Pending before gateway, ManualRequired, Failed, sent-PO recovery, cancelled-PO recovery). After the fix, 11 selected `SupplierDispatchTest` scenarios passed (66 assertions), including stale-Pending reclaim; the dedicated `ChainBroadcasterTest` passed (1 test, 8 assertions). Targeted Pint checks passed for the changed PHP files. Notification tests logged the existing “no eligible recipients” warning. No full API suite, actual queue-worker/Reverb delivery, browser/two-user scenario, list/badge outcome, or latency measurement was run; visible freshness and the 1–2 second SLO remain unverified.
- **Classification:** Source-confirmed producer gap, fixed at source and targeted-test level in the current worktree (delta 436). No two-user UI failure is claimed as dynamically reproduced.
- **Disposition:** Implemented as a scoped Purchasing realtime change. This worktree is not committed or published; runtime delivery and UI coverage remain open.


## Overtime list refresh excluded an API-authorized approver

- **Evidence:** `OvertimeListRoutePermissionTest` confirms a user with only direct `attendance.ot.approve` can fetch the overtime list and options. Before delta 467, `entity.overtime_request.visible` checked only `attendance.view`, and the page subscribed only for that permission. The red-first channel regression received 403 for the approver; the red-first SPA assertion observed `useRealtimeList('overtime_request', undefined)` for the same API-capable role.
- **Impact:** Direct overtime approvers without `attendance.view` could miss create/status refresh pings while their API list remained authorized. The authorization failure was reproduced by the focused channel test; the stale live browser frame before the fix was not reproduced.
- **Classification:** Source-confirmed list-channel audience mismatch, fixed and focused-test verified. Other users remain denied; the channel carries only a refresh signal and each viewer refetches through their scoped API.
- **Disposition:** Delta 467 aligns the private list-channel permission and SPA subscription gate with the list route's direct permission alternatives. Delegated-only users are not granted this channel because the list route does not accept delegated-only access. The API channel test passes 1 test / 4 assertions and the SPA list test passes 1 test. Delta 468 adds one two-context real-browser employee-to-department-head submit/approve scenario: both channel subscriptions and event receipt are verified, create peer rendering measured 1,023 ms from the actor response, and requester status rendering measured 905 ms from approval response. The browser approver also held `attendance.view`; direct-approver-only real-browser delivery and p95 remain `NOT_VERIFIED`.


## Inventory form options can show stale thresholds after settings change

- **Evidence:** `StockAdjustmentController::options()` includes `inventory.adjustment_approval_threshold`; both stock-adjustment pages cache `['inventory','stock-adjustments','options']` for 300,000 ms. `StockCountController::options()` also includes `inventory.stock_count.variance_tolerance_pct` and `inventory.stock_count.default_scope`; the Stock Count page caches `['warehouse','stock-count-options']` for 300,000 ms. Admin setting writes stage `SettingsChanged` after commit and `AppLayout` receives that signal, but `realtimeSignalInvalidationMap.settings_changed` includes neither options query key. A mounted form can keep old options after their five-minute stale boundary until a later refetch trigger (mount, focus/reconnect catch-up, or manual refresh). The server stays authoritative: StockAdjustmentService re-reads the approval threshold on submit; StockCountService re-reads variance tolerance during variance approval and session completion.
- **Classification:** Source-confirmed frontend cache-invalidation gap with stale form-preview/action-visibility risk. No settings change or browser reproduction was run in this source audit; visible stale behavior is not claimed as dynamically reproduced, and business rules remain server-enforced.
- **Disposition:** Fixed in delta 432 by adding both option keys to the central `settings_changed` invalidation map while retaining their caches. The current map and its focused assertion still contain both exact keys. Delta 602 cross-checked this finding against the current source and corrected the stale statement in root cause #172. The actual setting update and browser/two-user refresh remain unverified; this propagation-only change does not alter server validation.


## Stock movement GL retry did not refresh peer movement or WorkOrder views

- **Evidence:** `MovementGlPostingService::retry()` changes `journal_entry_id` and `gl_handoff_status`, while the normal create path publishes `InventoryStockChanged`. A focused retry API regression observed no retry outbox event before the change, then passed after the retry path staged the existing content-free `InventoryStockChanged` event through `OutboxService` (1 test, 9 assertions). The existing central `inventory_stock` invalidation map covers movement queries and linked WorkOrder details. The test confirms that the outbox codec carries only refresh metadata; it does not run a queue worker or peer browser.
- **Impact:** The producer signals authorized movement and WorkOrder viewers after meaningful GL handoff changes; the central stock map also refreshes active chain-bottleneck widgets (delta 441). Delta 449 adds server-computed per-viewer Retry availability, locked 409 revalidation, and a monotonically increasing stock movement version. The actor immediately installs the API result in active filtered list caches; the UI uses the action projection and refetches the list after 409.
- **Classification:** Producer and action contract are backend/source verified; the actor cache/action utility is focused-test verified. Queue/Reverb delivery, two-user rendering and latency remain `NOT_VERIFIED`.
- **Disposition:** Producer fixed in delta 440; dashboard invalidation fixed in delta 441; versioned server action and actor reconciliation fixed in delta 449. Preserve the private refresh signal and authorized API refetch model.


## Stock changes did not invalidate chain-bottleneck dashboard queries

- **Evidence:** `ChainBottleneckService` recomputes the current viewer's rows from live stock-movement GL handoff state, while active widgets use a one-minute query interval. The `inventory_stock` invalidation map covered stock, inventory, WorkOrder, and other related families but omitted `['chain-bottlenecks']`. A focused assertion was added first and failed because the query family was absent; after adding it to the shared map the focused test passed (1 selected test, 72 skipped).
- **Impact:** A stock movement GL retry could leave an active bottleneck widget showing old handoff state until its timer fired, despite receiving the content-free stock event. The source map now invalidates this user/role/audience-scoped query after `InventoryStockChanged`; each viewer still refetches through the authorized API. Other stock changes can also cause an active bottleneck widget refetch; only active queries are invalidated and adjacent changes coalesce. Query cost and visible latency were not measured.
- **Classification:** Source-confirmed central invalidation-map omission, fixed and regression-tested. Browser delivery/UI behavior remains `NOT_VERIFIED`.
- **Disposition:** Fixed in delta 441. Keep the periodic refresh for degraded-mode catch-up and measure query cost before widening similar shared signal dependencies.


## Payroll GL retry accepted a stale action while the handoff was pending (fixed in delta 447)

- **Evidence:** `PayrollPeriodService::actionContext()` sets `available_actions.retry_gl` only when the period is Finalized/Disbursed, has no journal link, and `gl_handoff_status` is ManualRequired or NotRequired (`api/app/Modules/Payroll/Services/PayrollPeriodService.php:218-228`). The new API regression first confirmed the bug: after one retry committed Pending, a second authenticated POST returned 202. Delta 447 adds a locked handoff-state check using the same ManualRequired/NotRequired set. The focused regression now verifies the first response disables `retry_gl`, the stale second request returns 409 with `payroll_period_state_conflict`, and only one retry outbox row is recorded (2 targeted retry-route tests pass, 10 assertions).
- **Impact:** Stale Retry actions no longer create another replay while the handoff is Pending or otherwise ineligible. The page already applies the successful API resource immediately and invalidates the Payroll period query family on 409; other viewers still depend on the existing private after-commit signal.
- **Classification:** Confirmed and API regression verified. No two-browser UI, live queue/Reverb delivery, or latency measurement was run; visible peer refresh remains `NOT_VERIFIED`.
- **Disposition:** Fixed in delta 447. Preserve the row lock, journal-link guard, existing typed 409 envelope, and current business rules.


## Queued PO-send expected GRN drafts had no follow-up refresh signal

- **Evidence:** The PO-sent path dispatches `PurchaseOrderSent` after commit; queued `CreateDraftGrnOnPoSent` later creates the expected draft through `GrnService::createDraftForPo()`. The initial PO event can be delivered before that listener finishes. Delta 437 added a `GoodsReceiptNoteRealtimeObserver::created()` signal, and `GoodsReceiptNoteRealtimeService::stage()` also stages a content-free private PO refresh for a linked GRN. A focused regression failed before the change (no GRN outbox signal), then the draft creation and repeated-dispatch regressions passed (4 selected tests, 16 assertions). The test inspects durable outbox rows inside the feature-test transaction; it does not run a queue worker or Reverb.
- **Impact:** A committed draft now creates a follow-up signal for authorized GRN and linked PO viewers, closing the source-level ordering gap for Eloquent-created drafts. Actual queue/broadcast timing and visible rendering were not measured.
- **Classification:** Producer path fixed and regression-tested; queue delivery and UI outcomes remain `NOT_VERIFIED`.
- **Disposition:** Fixed in delta 437. Query-builder/insert-based GRN creation paths, if any, and other GRN status/quantity writes remain outside this narrow regression and are tracked separately.


## GRN-only changes do not consistently refresh linked PO, supplier, and finance views

- **Evidence:** The GRN map still omits `purchaseOrderQueryKeys.details`, so PO detail depends on an explicit parent PO refresh. Delta 437 added GRN-create plus linked PO signals for queued expected drafts; delta 438 added journal-link observation; delta 439 added PO refreshes when receipt quantities change without a PO status change. Delta 442 adds the authorized `dashboard.finance` channel to `GoodsReceiptNoteChanged`; the active finance dashboard already maps that event name to its chain-bottleneck query. Delta 443 adds `shipment_id` and `supplier_shipment_id` to observer fields; a red-first Shipment Received test observed no GRN event for reuse/link of an expected draft, then passed after the change (1 test, 4 assertions). The shared GRN staging service refreshes the linked PO, whose event includes the supplier vendor channel.
- **Impact:** Traced Eloquent header and child-line create/update/delete paths now have GRN plus their linked PO refresh signals. Delta 451 added a `GrnItemRealtimeObserver` after source review found line quantity writes in supplier-return settlement and CoA verification that did not touch the parent GRN; no mutating query-builder write to `grn_items` or `goods_receipt_notes` was found under `api/app`. The observer coalesces GRN and linked PO pings within the PostgreSQL transaction. Query-map/channel tests establish source wiring only; queue delivery, actual supplier/finance rendering and latency remain unverified.
- **Classification:** Source producer/audience coverage is fixed for traced Eloquent writers; runtime UI outcomes remain `NOT_VERIFIED`.
- **Disposition:** Fixed in delta 451. Preserve the explicit linked-PO event because the central GRN invalidation map does not include PO detail keys. Do not claim UI freshness until a two-role browser scenario runs.


## GRN GL retry updated the journal link without notifying peer GRN viewers

- **Evidence:** `GrnGlPostingService::postLocked()` saves `journal_entry_id`. Before delta 438, the GRN observer did not watch that attribute and retry staged no GRN signal. A focused API regression first failed after prior GRN outbox rows were removed (no peer refresh), then passed after adding `journal_entry_id` to the observer's watched fields (1 test, 7 assertions). The shared service stages the content-free GRN event and linked PO refresh in the posting transaction.
- **Impact:** The producer now signals peer GRN and linked PO viewers after journal-link changes. The test did not run queue delivery, Reverb or another browser, so visible peer freshness remains `NOT_VERIFIED`.
- **Classification:** Producer path fixed and regression-tested; runtime delivery and UI outcomes remain `NOT_VERIFIED`.
- **Disposition:** Fixed in delta 438; delta 442 adds the finance bottleneck audience through `GoodsReceiptNoteChanged`. Non-Eloquent journal-link writes and runtime delivery remain unverified.


## GRN link-only shipment updates skipped the GRN and linked PO refresh

- **Evidence:** `GrnService::stageForShipment()` and `createDraftForSupplierShipment()` can attach an existing expected draft by updating only `shipment_id` or `supplier_shipment_id`. The observer did not watch either field. A focused Shipment Received regression cleared earlier GRN/PO signals, then observed no `GoodsReceiptNoteChanged` after reusing the expected draft (1 failure before the fix). A separate supplier-dispatch test failed with zero events when `supplier_shipment_id` was temporarily removed from the observer. The observer now watches both link fields; both focused tests pass (2 tests, 8 assertions) and confirm GRN and linked PO outbox refreshes.
- **Impact:** Viewers of the GRN and embedded PO receipt panel can refetch after a shipment is attached to an existing expectation. The linked PO event also reaches the authorized supplier channel. Worker/Reverb/browser freshness was not measured.
- **Classification:** Source-confirmed observer gap fixed and focused-test verified; UI outcomes remain `NOT_VERIFIED`.
- **Disposition:** Fixed in delta 443. Query-builder shipment-link writes outside these Eloquent paths remain unverified.


## Handoff-only GRN refreshes missed finance bottleneck viewers

- **Evidence:** GRN status transitions use `ChainStepAdvanced`, which targets `dashboard.finance`, but QC/GL handoff-only updates use `GoodsReceiptNoteChanged`, whose private channels previously excluded Finance. The Finance dashboard subscribes to `dashboard.finance` for `.chain.refresh`; a focused actual retry-gl outbox test initially failed because the decoded event lacked `private-dashboard.finance`, then passed after the event added that channel (1 test, 8 assertions). The existing channel authorization test passed grant/deny/inactive cases (1 test, 3 assertions); `routes/channels.php` requires Accounting enabled and `dashboard.accounting.view`.
- **Impact:** Finance bottleneck widgets can now invalidate after GRN GL/QC handoff refreshes and refetch through their existing viewer-authorized API. The event remains content-free. Delivery/rendering and query cost remain unmeasured.
- **Classification:** Source-confirmed least-privilege audience omission fixed at the event producer; runtime delivery remains `NOT_VERIFIED`.
- **Disposition:** Fixed in delta 442. Preserve the private channel grant and its permission test coverage.


## GRN action availability and actor reconciliation were client-derived; stale conflicts used 422 (fixed in focused API/UI path)

- **Evidence:** Before delta 446, `GoodsReceiptNoteResource` returned `status`, QC/GL fields, and a narrow `can_reject_remainder`, but no general `available_actions`, permissions projection, or version. `spa/src/pages/inventory/grn/detail.tsx` derived workflow buttons from local status/QC values plus the client permission store. A red-first actor regression held the follow-up GET open and showed the page remained Pending QC after the reject API returned Rejected. A separate stale-action API regression expected 409 but received 422. Delta 446 adds a per-viewer API action/permission projection and version, renders workflow controls from it, installs successful mutation resources immediately, and maps stale state preconditions to a typed 409. Focused API checks pass (3 tests, 17 assertions) for viewer projection, stale reject conflict, and create contract; the frontend detail and invalidation-map files pass 80 tests. GRN list/detail keys are split so list signals do not refetch all open details.
- **Impact:** A peer screen can still render a stale action until it receives a refresh, but the server now returns a typed 409 if that action is no longer valid, and the client refreshes its authorized record. The actor API response is applied without waiting for a socket or follow-up GET. Cross-user delivery and latency were not measured.
- **Classification:** API/UI action projection, actor reconciliation, and GRN stale-state conflict behavior are focused-test verified. Peer/browser delivery and latency remain `NOT_VERIFIED`.
- **Disposition:** Fixed in delta 446. Keep ordinary validation failures at 422 and stale state conflicts at 409; preserve lock checks and business rules. No browser/two-user scenario ran.


## Material issue events do not invalidate the open reservation list

- **Evidence:** `MaterialIssueService::issue()` changes a linked `MaterialReservation` from Reserved to Issued, or reduces its reserved quantity while retaining Reserved; cancellation can change a linked reservation to Released. The issue-slip observer stages `MaterialIssueSlipChanged`, and stock movements stage `InventoryStockChanged`. Before deltas 433–434, the central `material_issue_slip` and `inventory_stock` invalidation maps omitted the ReservationsTab key `['inventory','reservations']`. `StockLevelsPage` subscribes through `useInventoryStockRealtime()` even when the Reservations tab is selected, so the common stock signal is the path that reaches those active viewers. The reservation API is authenticated and no backend result cache was found; the `material.reservations` dashboard generation is a separate cache owner. Work Order lifecycle events already invalidate the Inventory prefix for reserve/start/cancel paths.
- **Impact:** A peer viewing the reservation list can keep seeing a reservation as Reserved, or see its old quantity, after another user issues/cancels the material issue slip. No browser action or stale screen was reproduced.
- **Classification:** Source-confirmed missing client-query invalidation for linked reservation changes; all UI result cells remain `NOT_VERIFIED`.
- **Disposition:** Fixed in deltas 433–434 by mapping the reservation query family from both the material-issue entity event and the shared `inventory_stock` event consumed by `StockLevelsPage`. Focused map assertions failed before the changes and the invalidation-map file passed afterward (72 tests). Viewers refetch through the existing authorized Reservations API; event payloads remain content-free. The shared stock signal can now refetch an active reservation list after any stock movement, not just a reservation mutation; only active queries refetch and adjacent signals coalesce. No live issue/cancel or browser/two-user refresh or performance measurement was run, so runtime delivery remains `NOT_VERIFIED`.

## Production receipt missing-actor fallback is unreachable under current schema

- **Evidence:** `CreateProductionReceiptOnOutputRequested::handle()` has a branch for an output creator and automation actor both being unavailable. However, the `work_order_outputs.recorded_by` column is non-null and has a restrictive foreign key to `users` (`api/database/migrations/0082_create_work_order_outputs_table.php:17-20`); all current `WorkOrderOutput::create()` paths supply an authenticated/recorded user. An isolated attempt to construct that branch by nulling `recorded_by` was rejected by PostgreSQL with SQLSTATE `23502` before the listener ran. The listener's ordinary retry-failure path calls `retryProductionReceipt()`, whose catch path marks manual and refreshes the WorkOrder.
- **Impact:** No normal API/DB path was found that reaches the missing-actor branch, so the previously proposed stale-screen scenario is not established. Out-of-band writes that bypass constraints would be a different integrity defect and were not inspected.
- **Classification:** Earlier source-only suspicion ruled out for schema-valid application data; no product fix is justified by the available evidence. Runtime queue/UI outcomes remain `NOT_VERIFIED`.
- **Disposition:** Closed as a false positive for ordinary writes. Reopen only if a valid schema-constrained producer that stores a missing/invalid creator is identified.

## PPAP actions and evidence edits could race past their status guards (fixed, Delta 826)

- **Evidence:** The regression deterministically held a route-bound PPAP model at `Submitted`, advanced the database row to `Approved`, then invoked the review controller with the stale model. Before the fix, it returned a resource and replaced the committed `Approved` status with `UnderReview`. A second case held an element with its parent snapshot at `Submitted`, advanced the parent to `Approved`, then attempted an evidence edit; before the fix, the document/status changed after approval. These reproduce the stale-read/write interleaving without claiming a simultaneous multi-process race.
- **Impact:** A stale reviewer could overwrite a newer PPAP state, and stale element edits could change evidence after parent approval.
- **Classification:** Confirmed stale-write defect; no backend cache is involved. Two focused regressions now pass, but multi-user/browser delivery remains `NOT_VERIFIED`.
- **Disposition:** Delta 826 wraps PPAP update/submit/review/approve/reject in transactions, locks and rechecks the current submission against the route snapshot, and locks the parent before the PPAP element on evidence updates. A changed snapshot returns HTTP 409 with `ppap_state_conflict`; existing business-rule checks and 422 behavior remain for current-state rule violations. The two new stale-snapshot regressions pass (2 tests / 10 assertions); existing approved-evidence-freeze and pre-approval-edit tests also pass (2 / 13), for 4 selected tests / 23 assertions total. PHPUnit warns because the API test container has no `/var/www/.env`; the configured test database completed all four tests. PHP syntax passes. Targeted Pint passes for the new exception and regression test; it flags legacy formatting in the touched controller/service, so no whole-file reformat was applied. No simultaneous-user, WebSocket, or browser scenario ran, so matrix runtime cells remain `NOT_VERIFIED`.

## Calibration due-status job can overwrite a newer register edit

- **Evidence:** `CalibrationService::recomputeStatuses()` reads rows with `chunk(200)` and computes the new status from each loaded `next_calibration_date`, then saves the model without a row lock or a transaction spanning read/derive/save. Interactive `update()` and `recordCalibration()` do use row locks and may commit a newer date/status while the scheduled reader still holds an older date snapshot. The later job save only writes the stale derived status.
- **Impact:** A concurrent date edit/calibration can leave a record's Active/Due/Overdue status inconsistent with its current schedule until a later recompute or edit. The job is scheduled daily at 06:50. No concurrent run was reproduced.
- **Classification:** Source-confirmed stale-write race; runtime status corruption remains NOT_VERIFIED. No response-cache entry is involved.
- **Disposition:** Separate Quality correctness fix: lock/reload each row before deriving or use a conditional version/date check so the daily job cannot overwrite a newer calibration schedule. Preserve the existing date/window and Retired rules. No product code changed in this audit slice.

## General Machine update can bypass transition rules and breakdown side effects

- **Evidence:** `StoreMachineRequest` accepts `status`. `MachineService::update()` persists that field directly. `MachineService::transitionStatus()` separately locks/reloads the row, validates `allowedTransitions()`, and records `MachineStatusChanged`. The registered `MachineRealtimeObserver` still stages the canonical content-free `MachineChanged` after commit for the general update, so the direct path is covered by the machine detail/list refresh signal. `Machine::updated` retires pending/confirmed schedules when entering Breakdown, while the `MachineStatusChanged` listener also pauses an in-progress WorkOrder and opens the downtime/breakdown chain; those dedicated lifecycle side effects are skipped by the general update.
- **Impact:** A machine manager may set a status through the general edit route that would fail the transition graph; setting Breakdown there can notify clients of a changed Machine while skipping the work-order pause/downtime side effects. The status refresh is source-wired, but related production state can remain incorrect. No route action or user-visible failure was reproduced.
- **Classification:** Source-confirmed state-guard/side-effect bypass; runtime result remains NOT_VERIFIED. Machine/API result caches are not involved.
- **Disposition:** Separate MRP/Production correctness fix: route status changes through the locked status transition path or reject `status` in general update, and keep MachineChanged plus MachineStatusChanged side effects exactly once after commit. Preserve current transition and ownership rules. No product code changed in this audit slice.


## Equipment changes skipped work-order readiness refreshes before crew assignment

- **Evidence:** `MachineRealtimeService` and `MoldRealtimeService` call `AssignedWorkOrderRefreshService::stageForMachine()` / `stageForMold()` after relevant equipment writes. The prior lookup began from active `WorkOrderCrew` rows, so a work order with an assigned `machine_id` or `mold_id` and no active crew produced no `AssignedWorkOrderChanged` outbox row. A red-first focused machine test reproduced zero rows. The producer now directly queries linked work orders and unions the existing active rework NCR/crew path. Focused Machine and Mold tests pass (6 tests, 22 assertions); PHPUnit marks them warnings because `/var/www/.env` is absent in the test container.
- **Impact:** Open readiness views can now receive the existing authorized chain refresh when assigned equipment changes even before a crew is assigned. The actor/peer UI result, channel delivery and latency were not tested. Matrix UI cells remain `NOT_VERIFIED`.
- **Classification:** Source-level producer gap reproduced by focused outbox test and fixed; runtime delivery remains `NOT_VERIFIED`.
- **Disposition:** Fixed in delta 452. Keep the content-free after-commit signal and current private work-order channel. Retain the dialog timer pending complete coverage of clock boundaries and all readiness inputs.


## Product routing and inspection-spec updates skipped active work-order readiness refreshes

- **Evidence:** `WorkOrderService::executionReadiness()` checks the active product routing and inspection specification. Existing `ProductionRoutingChanged` and `QualityRecordChanged` events refreshed their own readers but did not target related active work orders. Red-first focused tests each observed zero `AssignedWorkOrderChanged` rows after a routing or spec edit for a confirmed WO's product. Delta 453 adds a product-scoped fan-out for confirmed, in-progress and paused work orders; the existing outbox key coalesces parent spec and child-item changes within a transaction. The focused routing and inspection-spec cases pass (2 tests, 7 assertions); PHPUnit reports the container `/var/www/.env` warning.
- **Impact:** Active Start/Resume/Extend readiness queries now receive a private, content-free WO refresh when product routing/spec data changes. The queue, channel delivery, visible screen and latency were not tested; all corresponding UI matrix cells remain `NOT_VERIFIED`.
- **Classification:** Source-confirmed linked-refresh gap reproduced by producer tests and fixed; runtime delivery remains `NOT_VERIFIED`.
- **Disposition:** Fixed in delta 453. Use the shared product fan-out for product-level readiness inputs and retain the dialog timer until time-derived and remaining resource/warehouse dependencies are covered.

## Equipment reservations skipped readiness refreshes for work orders sharing the equipment

- **Evidence:** `WorkOrderService::executionReadiness()` reads confirmed maintenance windows and overlapping production schedules. Before delta 454, creating/releasing a maintenance window refreshed its owning maintenance work order only; release used a query-builder bulk update. A new production schedule refreshed its own schedule/work-order audiences only. Red-first tests each found no `AssignedWorkOrderChanged` outbox row for a different confirmed WO assigned to the same equipment. A fourth test found no refresh for a WO on the prior equipment when a schedule was moved.
- **Impact:** Active Start/Resume readiness screens could retain blockers after another reservation was added, released or moved. The server continued revalidating reservations on every action. No user-visible stale frame or missed action was dynamically reproduced.
- **Classification:** Source-confirmed cross-record refresh gap, reproduced at the outbox boundary and fixed at the producer. Focused API coverage passes 4 tests / 6 assertions. WebSocket delivery, browser behavior and latency remain `NOT_VERIFIED`.
- **Disposition:** Fixed in delta 454 using the existing content-free assigned-work-order signal. Fan-out includes current and previous resource IDs and coalesces by WO/transaction. Keep the Start Production polling fallback until clock and remaining stock/warehouse readiness inputs have event coverage.

## Shift-assignment changes can leave stored Attendance status derived from old working days

- **Evidence:** `DTRComputationService::computeForRecord()` resolves an employee’s current shift assignment and `isWorkingDay()` before deriving Attendance status. `ShiftAssignmentService::replaceForEmployee()` and `configureWorkingDays()` write assignment inputs, but neither recomputes affected Attendance rows. Their guards reject rows with recorded punches; they do not reject an affected date that already has a non-punch Attendance row or recompute that row. The services publish `ShiftAssignmentChanged`, and the SPA invalidation map refetches Attendance queries, but that refetch reads the unchanged persisted status. A date with an existing no-punch row can therefore retain `rest_day`/`absent` classification from the previous assignment after an allowed backdated assignment or working-day edit.
- **Impact:** A fresh API read can continue returning a status and associated DTR fields based on obsolete working-day inputs. This is a source-confirmed recomputation gap; no API action or browser state was executed.
- **Classification:** Functional data-correctness finding, separate from realtime/cache propagation. The refresh path is present; the authoritative stored DTR is not recomputed for this input change.
- **Disposition:** Separate Attendance correctness PR. Preserve current assignment and payroll/business guards; define whether past dates may be changed, then either reject affected closed/historical dates or recompute eligible rows through `AttendanceService::recomputeForEmployeeOnDate()` within the assignment transaction. Add focused coverage for no-punch rows, punch-history guards, and after-commit refresh. No code was changed for this finding.

## Intermediate salary approval refresh and action projection

- **Evidence:** `ApprovalService::approve()` updates the current `ApprovalRecord` and can hand the next approval step to another role while `SalaryAdjustment.status` stays `pending`. Before delta 444, the only registered approval-record realtime observer handled EmployeeLoan; the salary parent observer therefore saw no write and staged no `SalaryAdjustmentChanged`. A focused regression first observed zero matching outbox rows; after delta 444 it verifies the content-free, private, after-commit event, actor/hash metadata, omitted salary amounts, and a strictly advancing whole-second version. It also caught and fixed creation coalescing that had relabeled `created` as `updated`. The salary list originally rendered both actions from Pending status and the API omitted per-viewer eligibility. Before/after API regression evidence showed the checker gets approve/reject at their current step, loses both after advancing the chain, and the next role gets both; the new API projection also reports `permissions.act`. The action endpoint continues to revalidate state and authorization.
- **Impact:** The outbox producer now refreshes intermediate approval changes, and API/UI rendering now follows server-computed current-step eligibility. Backend coverage passes (two focused tests, 27 assertions, including the existing apply-once test); the SPA component test passes 3/3, including hiding actions and closing a confirmation after refreshed eligibility changes. No two-user browser/WebSocket delivery scenario was run.
- **Classification:** The missing event and action-projection gaps were source-confirmed and reproduced at the outbox/API boundary, with focused tests passing after fixes. Live socket delivery, cross-role rendered UI timing, and commit-to-UI latency remain NOT_VERIFIED. No SalaryAdjustment result cache was found.
- **Disposition:** Deltas 444–445 add the after-commit refresh for approval-chain writes, maintain a strictly increasing parent version at the legacy timestamp precision, expose per-viewer `available_actions` and `permissions`, and render the returned actions in the SPA. Existing submitter, role/delegation, permission, and pending-step guards remain authoritative. No business rules changed.



## KPI snapshot refreshes previously left active dashboard widget data stale

- **Evidence:** `KpiSnapshotService::computeAll()` writes monthly snapshots through `computeKpi()` and stages `KpiSnapshotChanged` when at least one row is persisted. `KpiStrip` listens to that private signal; before the fix, the central signal map invalidated only `['kpi']`, and the generic `kpi_snapshot` entity map had no related query prefixes (`spa/src/lib/realtime/invalidationMap.ts:153,287`). The registered `DashboardCacheInvalidationObserver` maps `KpiSnapshot` to the summary/rich `forecast.capacity` backend generations (`api/app/Modules/Dashboard/Observers/DashboardCacheInvalidationObserver.php:356-359`); the capacity forecast reads recent OEE snapshots (`api/app/Modules/Dashboard/Services/CapacityForecastService.php:54-60`). The default and role dashboard shells cache `['dashboard','widget-data',widgetKeys]` and refetch at `DASHBOARD_REFRESH_MS = 60_000` (`spa/src/pages/dashboard/default.tsx:51-56`, `spa/src/components/dashboard/RoleDashboardShell.tsx:63-70`, `spa/src/api/dashboards.ts:16`). Before the fix, the KPI signal did not invalidate those active client queries.
- **Impact:** Before the source fix, an already-open dashboard displaying the capacity forecast could retain its previous browser-cached widget payload after a KPI recompute, even though the backend cache generation had been retired. The configured refresh interval was 60 seconds; no stale duration was measured.
- **Classification:** Source-confirmed related-query invalidation gap. The endpoint and scheduled paths, event delivery, active widget visibility, and stale render were not exercised in this source-only slice.
- **Disposition:** The central invalidation map includes `['dashboard','widget-data']` for both the `kpi_snapshot_changed` signal and generic `kpi_snapshot` entity changes. The focused test now asserts both paths and passes 1 selected case (94 unrelated cases skipped). This is source/test evidence only; no browser, queue, or two-user scenario was run, so runtime freshness remains `NOT_VERIFIED`.


## Settings changes left other settings-backed form options cached

- **Evidence:** `SettingsChanged` intentionally emits only refresh metadata and does not expose a setting name or value. Before delta 435, the central settings invalidation map omitted several exact API-backed option keys whose controllers read `SettingsService`: Item/GRN/quality-plan options, Purchase Request/Purchase Order/Loan options, budget ratios, Maintenance work-order and condition-reading defaults, Return dispositions/reasons/conditions, NCR and Inspection quality defaults, Forecasting methods/accuracy thresholds, mold warning ratio, MRP scheduler horizon, and Payroll bank-file defaults. Their keys are used by active forms/lists and their caches have hook-specific stale times (default or five minutes). Authenticated APIs are private/no-store and each option endpoint rechecks its caller's scope. The previously identified StockAdjustment/StockCount option keys were fixed in delta 432; this follow-up covered the remaining audited families.
- **Impact:** A settings edit could leave a mounted form showing an old default, threshold or permitted choice until another query refetch trigger. Server-side validators/services still read authoritative current settings; no business rule was changed. No setting mutation or stale browser view was dynamically reproduced.
- **Classification:** Source-confirmed shared invalidation-map gap across multiple modules; runtime UI behavior remains `NOT_VERIFIED`.
- **Disposition:** Fixed in delta 435 by adding exact settings-backed option query prefixes to the central `settings_changed` map. A focused regression assertion failed before the map expansion and the invalidation-map file passed afterward (73 tests). Because the content-free event carries no key, any active mapped option query may refetch after any settings edit; inactive entries become stale and authorized APIs remain the source of truth. Request volume and browser delivery were not measured.

## Finished-goods setup changes skipped active production readiness refreshes

- **Evidence:** `FinishedGoodInventoryService::assertReady()` reads Product part number/unit, the matching finished-goods Item's active/type/unit fields, and the existence of an active unblocked location under an active Finished Goods zone/warehouse. Red-first tests reproduced missing work-order outbox signals for product-unit and matching Item availability changes. Another API test found the warehouse-layout signal lacked the existing `production.readiness` channel for an FG location change; a red-first SPA hook test found no consumer subscription.
- **Impact:** An open Start/Resume readiness dialog could retain a prior mapping/location blocker after a warehouse, item or product change. The action API continued to revalidate current data. No actual stale visible frame or accepted invalid action was reproduced.
- **Classification:** Cross-module producer and consumer gap, reproduced by focused API outbox/channel and SPA hook tests. Focused API checks pass 3 tests / 6 assertions; the invalidation hook file passes 40 tests. Queue delivery and user-visible timing remain `NOT_VERIFIED`.
- **Disposition:** Fixed in delta 455. Product changes refresh active WOs by product; relevant Item changes resolve current/previous part-number mappings; only FG-impacting warehouse layout changes add the permission-gated production readiness channel. The event broadcast remains content-free, and the Start Production fallback remains while time/crew and remaining dependencies are mapped.

## PHPUnit filtered Artisan command was blocked by a duplicate import (fixed in delta 601)

- **Evidence:** `docker compose exec -T api php artisan test --filter=WorkOrderReadinessStaffingRealtimeTest` aborts during test-file loading with `Cannot use App\Modules\Accounting\Enums\AccountingPeriodStatus as AccountingPeriodStatus because the name is already in use` at `api/tests/Feature/Accounting/AccountingPeriodRealtimeTest.php:10`. That file contains the same `use` statement twice. Running the exact focused test path with `./vendor/bin/phpunit tests/Feature/Production/WorkOrderReadinessStaffingRealtimeTest.php` bypasses discovery of that unrelated file and executes successfully.
- **Impact:** The convenient filtered Artisan command cannot be used until the duplicate alias is removed, although exact-file PHPUnit runs still work. No application behavior is affected.
- **Classification:** Test-discovery defect, separate from the realtime/cache work.
- **Disposition:** The duplicate `use` line was removed in delta 601. This test-only correction unblocks normal PHPUnit discovery and changes no application behavior. The API container still emits a warning when tests load because `/var/www/.env` is absent; targeted tests execute and assertions pass. No broader suite was run.

## Recruitment stale-action conflicts now refetch and project current server actions (fixed in deltas 463–465)

- **Evidence:** Before delta 463, the stage mutation request did not identify the stage shown by the client. A stale `new` client reaching current `screening` state received 422 interview-field validation before the service could distinguish stale state. The detail's mutation handlers did not refetch. A red-first API regression reproduced that exact response; a red-first component regression showed that the old action and confirmation remained visible after a mocked state conflict.
- **Impact:** A client that missed the refresh ping could retain a stale stage/action after the API refused the operation. No live two-user stale frame was dynamically reproduced.
- **Classification:** The server action/version projection and typed stale-state recovery paths are now wired for Recruitment applications and postings. Live event delivery and cross-role UI timing remain `NOT_VERIFIED`.
- **Disposition:** Delta 463 adds optional `expected_stage`, compares it under the locked application row, returns typed HTTP 409 for stale advance/reject requests, and refetches relevant queries. Delta 465 adds per-viewer actions, permissions and versions to both Recruitment resources; posting detail controls now use the server action list, and locked posting writes return typed 409 when an updated client submits an old version. Application arrival can change archive eligibility without changing posting `updated_at`; the row-locked archive check returns 409 for version-aware clients and preserves the legacy 422 behavior for requests without a version. The posting API test file passes 9 tests / 42 assertions, and five focused SPA Recruitment page files pass 16 tests, including a stale edit 409 that fetches the latest posting while preserving the typed title. A component regression also reproduced the actor's API response being overwritten by invalidating its exact detail query immediately after `setQueryData`; that refetch was removed while related list queries continue to invalidate. Existing clients that omit versions remain compatible. No business rules changed. No two-context Recruitment browser test or live Reverb measurement ran.

## SPA project typecheck reported unrelated worktree diagnostics (resolved in delta 675)

- **Evidence:** A project-wide `npm run typecheck` (`tsc --noEmit`) completed with TypeScript diagnostics after roughly two minutes. Diagnostics included `spa/src/pages/budgeting/detail.tsx:428` returning `Promise<Budget>` where `Promise<void>` is expected; `spa/src/pages/hr/recruitment/postings/detail.tsx:70` referencing missing `JobPosting` and an implicit-any callback (the Recruitment posting page now imports the type and declares the API action type); unused realtime-hook imports in two HR training tests; two `recentItemsStore.test.ts` helper calls with missing arguments; and an incompatible `server.proxy` type in `spa/vite.config.ts`. The full typecheck has not been rerun after delta 465.
- **Impact:** The full SPA typecheck exits non-zero, so a production TypeScript build is not currently verified. These findings are separate from the Recruitment application stale-conflict change.
- **Classification:** Independent compile/typecheck defects in the existing worktree; no runtime behavior was exercised.
- **Disposition:** Delta 675's project-wide typecheck now exits 0 after the tracked diagnostics were corrected across the invalidation map, API fixtures, Inspection tests, and Return Management. No broad test suite was run. This historical typecheck finding is closed; the separate business/runtime findings remain scoped independently.

## Stale Inspection result writes returned HTTP 422 instead of a state conflict

- **Evidence:** `InspectionService::assertResultVersion()` raised a validation exception when a checklist, counted result, review, cancellation, or replacement request submitted a missing/outdated result version. A red-first API test sent a stale checklist version and received 422. The SPA could leave an obsolete review confirmation actionable after a versioned review was refused.
- **Impact:** Clients received a validation-shaped response for state that had changed on the server, so generic form handling could leave the displayed status/action stale.
- **Classification:** Freshness/conflict-contract defect. The server remains authoritative and rechecks the locked version; no business rule changes.
- **Disposition:** Delta 470 maps this to typed HTTP 409 `inspection_state_conflict`; matching Inspection clients invalidate authorized queries while preserving typed entries or closing stale confirmation. Focused API coverage passes 3 tests / 14 assertions and the focused SPA files pass 48 tests. No two-user browser or live socket run was performed. Keep packaging isolated from unrelated functional changes when preparing PRs.



## Successful form redirects were blocked during mutation success (cross-cutting guard defect)

- **Evidence:** In the four-context Purchase Request scenario, create returned HTTP 201 and submit returned HTTP 200; the submitted row existed, but the creator remained on the form with the unsaved-changes confirmation open and no detail GET followed. The source calls `navigate()` in mutation `onSuccess`, before React Query flips `isSuccess`, while `useFormSafety` still marks the dirty form as guarded.
- **Impact:** A successful save could leave the acting user on a stale form instead of the committed record. The same sequencing pattern appeared in 50 successful mutation redirects across 47 `useFormSafety` forms.
- **Classification:** Functional UI correctness defect that interferes with the acting-user freshness contract; no API business rule or status behavior changed.
- **Disposition:** Added a one-shot navigation allowance to the shared unsaved-changes guard, exposed it as `allowNavigationAfterSave`, and wired each discovered save-success redirect through it. Ordinary cancel/navigation paths remain guarded. The guard regression failed before the helper was added and passed afterward (3 tests); the Purchase Request four-context E2E then completed. This finding remains its own SPA implementation/PR unit when packaging work. Other forms were covered by the shared API and static callsite audit, not by individual browser scenarios. A separate AST scan inventoried 12 direct `useUnsavedChangesGuard` files; five UI files enable in-app protection, none navigates from a mutation `onSuccess`, and the one direct-guard success redirect does not enable in-app blocking. No additional instance of the reproduced failure was found.

## Attendance changes could miss production work-order list viewers

- **Evidence:** `AttendanceRealtimeService::stage()` resolves active work orders whose crew contains the affected employee. `AttendanceChanged` broadcast to each `chain.work_order.{hashId}` channel, which the detail hook handles, but it omitted `entity.work_order.visible`, which production list hooks use. A production reader may lack `attendance.view` and therefore cannot rely on `entity.attendance.visible`. Before the fix, the production list subscriber had no route for this event. The focused list-hook and broadcast-contract regressions both failed before their corresponding source fixes.
- **Impact:** An active production work-order list or crew/readiness query could retain stale attendance-dependent information until another refetch. This source gap explains a subset of cross-role stale status/readiness reports; it does not explain all event, cache or action-button failures.
- **Classification:** Realtime audience and invalidation coverage gap. The shared API remains the data and authorization source; the broadcast carries no record values.
- **Disposition:** Delta 493 sends a content-free ping to the existing authorized work-order entity channel only when an active related work order exists. `useRealtimeList('work_order')` routes `.attendance.changed` through the central map, which includes work-order list, crew, crew options, readiness and assignment/reschedule suggestion families. `BroadcastEventContractTest.php` passes 37 tests / 1,349 assertions; the existing work-order channel authorization test passes 1 test / 2 assertions; the focused SPA hook and invalidation files pass 120 tests. ESLint and Pint pass. No two-user browser, live Reverb delivery or latency sample was collected; all corresponding matrix runtime outcomes remain `NOT_VERIFIED`.

## Leave realtime observer referenced a missing badge-event namespace

- **Evidence:** The focused Leave API test failed during a `LeaveRequest` model write with `Class "App\\Common\\Events\\BadgesChanged" not found`. The actual event class is `App\\Modules\\Dashboard\\Events\\BadgesChanged`; the Leave observer imported the nonexistent namespace.
- **Impact:** A leave create/update/delete/restore that reached this observer could throw while trying to stage its badge refresh, interrupting the request's normal completion and real-time side effects.
- **Classification:** Independent functional defect found while verifying Leave freshness; it is separate from the realtime contract change.
- **Disposition:** Corrected the import in `LeaveRequestRealtimeObserver`. Keep this correction isolated as its own functional-fix PR when the accumulated worktree is packaged; do not count it as proof of live event delivery.


## Closed Contact Inquiry still renders “Mark in progress”

- **Evidence:** `spa/src/pages/crm/inquiries/detail.tsx` renders the `Mark in progress` control whenever `data.status !== 'in_progress'`, which includes the terminal `closed` state. The backend transition guard allows `new -> in_progress`, `new -> closed`, and `in_progress -> closed`; it rejects `closed -> in_progress` with a business-rule response. While writing the actor response freshness regression, the test reached the API-returned Closed state and observed that Mark in progress remained visible.
- **Impact:** An operator can attempt a status transition that the server will always reject. The server remains authoritative and prevents an invalid state change; this does not cause record-state corruption.
- **Classification:** Source-confirmed UI action eligibility defect, observed in a component/API-mock scenario. No live route was exercised.
- **Disposition:** Implemented in the Landing/CRM worktree slice recorded as delta 606: the API now returns permission- and transition-aware `available_actions` plus a version; the page renders those actions, applies the API response immediately, and supplies an optional `expected_status` precondition that returns a typed 409 when stale. Red-first focused checks reproduced the missing action/version projection and stale request returning 422; after the change the API regressions pass (2 tests / 16 assertions) and the detail component file passes (5 tests). Keep this functional correction isolated as its own Landing/CRM PR when packaging the accumulated worktree. A real dev-stack two-user, three-browser-context Playwright run passes: the System Administrator actor socket is disabled; the Customer Service Officer receives a private status event whose `entity_id` exactly matches the inquiry hash in both detail/list contexts; both authorized APIs return Closed and stale actions disappear without reload. After the stale Horizon worker was restarted, event-to-detail/list DOM measured 547/529 ms and actor-response-to-peer detail/list measured 561/395 ms. This is one scenario/sample, not p95 or proof for other transitions. Related records, badges, dashboards and inbox were not asserted.

## Existing SPA type error in Quality inspection test (resolved in delta 675)

- **Evidence:** The focused Landing/CRM changes pass targeted ESLint and the detail component tests. A whole-SPA `tsc --noEmit` run stops at `spa/src/pages/quality/inspections/detail.test.tsx:159`: `getByRole()` receives an unsupported `{ exact: true }` option, and TypeScript reports TS2769. The error is outside the files changed for delta 606.
- **Impact:** The SPA-wide typecheck cannot currently serve as a clean repository gate until this test typing issue is corrected.
- **Classification:** Independent existing test/type-check defect found during verification; unrelated to real-time/cache behavior.
- **Disposition:** Delta 675 replaced the unsupported `{ exact: true }` role-query option in the three affected selectors. The fresh project-wide `npm run typecheck` exits 0. This is a test typing fix only; the Inspection runtime matrix is unchanged.

## Item cost-review decision response has a broader payload than its list read gate

- **Evidence:** `GET /api/v1/inventory/cost-reviews` requires `inventory.costs.review`, while `POST /api/v1/inventory/cost-reviews/{itemCostReview}/decide` requires only `inventory.costs.approve` (route middleware and `DecideItemCostReviewRequest` both use `ApprovalDelegation::canActForPermission`). `ItemCostReviewController::decide()` returns the full `ItemCostReviewResource`, including item identity, receipt number, prior/proposed/current cost, history and notes. `BroadcastAuthorizationTest::test_item_cost_review_list_channel_matches_the_api_read_permission` intentionally denies the private list channel to a user with only `inventory.costs.approve`. Source review therefore identifies a difference between list/channel read scope and the successful decision response.
- **Impact:** An approval-only account may receive the full cost-review representation as the response to a permitted decision even though it cannot read the list endpoint or subscribe to list refreshes. No data is sent through the broadcast payload, and no cross-user cache leak is implied by this finding.
- **Classification:** Source-confirmed permission-scope mismatch; no approval-only HTTP mutation was dynamically reproduced in this slice.
- **Disposition:** Separate Inventory authorization review. Decide whether approvers are intended to have read access to cost-review details; then align the decision response or read permission without changing the approval workflow. No production authorization change was made as part of the realtime action-projection slice.

## Transfer Order actions were inferred from client permission and status (fixed in delta 577)

- **Evidence:** `TransferOrderResource` omitted viewer-specific `available_actions`; a red-first API test observed `null` for the missing Execute projection. A red-first page test confirmed the Pending detail still rendered Execute and Cancel without that server decision. After the resource and UI change, the focused Transfer Order API file passes 8 tests / 31 assertions and the page component file passes 3/3. A cache-scope regression first observed an unscoped detail key and now verifies user- and role-scoped list/detail keys.
- **Impact:** A stale or incomplete detail response could leave action buttons derived from an old browser permission snapshot instead of the current record and viewer. This was a UI contract gap; API permission middleware and service state/maker-checker validation remained authoritative.
- **Classification:** Confirmed action-projection and viewer-scoped frontend query-key gap in Transfer Orders. It does not prove a Reverb delivery failure.
- **Disposition:** The API resource now returns `execute` and `cancel` booleans from current viewer permission and record state; the page fails closed when an action is absent/false and disables open confirmations after a refetch removes eligibility. Query keys include user ID and role. No live two-user event, cache hit/latency or matrix route PASS was established.


## Transfer Order detail event missed its viewer-scoped query key (fixed in delta 578)

- **Evidence:** In the first two-context live execute attempt, the Warehouse peer received the private record and list events, but its detail made no second GET within five seconds. The central invalidation prefix is `['inventory','transfer-order',hashId]`; the viewer-scoped cache key put user and role before `hashId`, so prefix matching skipped the active detail. A focused red-first QueryClient test independently reproduced one read instead of two.
- **Impact:** A peer could receive the real-time signal yet keep an old status and stale Execute/Cancel controls on its active detail. User/role cache isolation also meant simply dropping viewer scope would have introduced cross-user action/data reuse.
- **Classification:** Confirmed real-time invalidation/query-key ordering defect in Transfer Orders.
- **Disposition:** The detail key is now `[family, recordId, userId, role]`, satisfying both central invalidation and per-viewer scope. The regression passes. A repeated two-context admin-to-warehouse browser flow observed one record event, one list event, authorized detail/list refetches, Transferred status, removed action buttons and actor-response-to-peer-UI 1,493 ms with actor WebSocket disabled. One sample only; no commit-to-UI p95. Two successful fixture transfers across the failed first attempt and passing rerun moved a total of two whole units within the dev database; retained by authorization. Delta 579 partially verifies the linked stock-level query: the private stock event arrived, the active authorized query refetched, and an authorized read saw per-location quantities change; the visible stock table cell was not separately asserted. Dashboard views and other transitions remain unverified.


## Cloudflare overrode the service-worker browser TTL (fixed at origin)

- **Evidence:** Before the change, local TLS Nginx/Vite returned `/sw.js` as `no-cache`, while `https://ogamiph.dev/sw.js` returned `Cache-Control: max-age=14400` and `cf-cache-status: REVALIDATED`. The active dev Nginx override had no exact worker path. After the fix and graceful reload, public responses returned `no-store, no-cache, must-revalidate, max-age=0` and `cf-cache-status: BYPASS` twice. The app shell, Vite client and API auth response retained their expected headers.
- **Impact:** A browser could keep an old service worker for four hours despite newer source, delaying worker-policy fixes or deploy-version behavior. The current worker explicitly excludes API paths, so this does not establish that the reported stale record UI was caused by this cache.
- **Classification:** Reproduced CDN/browser-cache policy defect; stale-record symptom linkage remains unverified.
- **Disposition:** Added exact `/sw.js` no-store browser/CDN routes to active local dev Nginx, tracked dev Nginx and production Nginx. Nginx syntax validation passed; public Cloudflare status changed from `REVALIDATED` to `BYPASS`. The 3,533-byte request measured 6.8–7.5 ms locally and 56.9–283.3 ms through the public edge in five serial samples. No direct Cloudflare purge was possible because API credentials were not present; the prior edge object is bypassed and will expire under its original TTL.


## Horizon continued publishing an old Contact Inquiry event envelope until worker restart

- **Evidence:** The status-change outbox row contained `entityHashId` for the committed inquiry, and decoding it in the current API process returned an `entity_id` with that same hash. The first live two-user, three-browser-context Playwright trace captured the actual private Reverb frame without `entity_id`; the viewer had received the event, but exact record correlation failed. Horizon had been running for roughly five hours before the event class changed. After Horizon restarted, the same test captured the matching `entity_id` in both viewer contexts and passed.
- **Impact:** Long-lived queue workers can keep the prior event class loaded after source files have changed. The database/outbox can therefore be correct while the WebSocket payload remains stale, weakening correlation/order handling and making live behavior differ from the current source. This reproduced worker-staleness cause is specific to the dev stack change; it does not explain all stale screens.
- **Classification:** Confirmed runtime code/config freshness defect at the queued broadcast boundary.
- **Disposition:** Restarted the local Horizon service and documented worker restart as required after code/config deployment. A normal API-container `horizon:terminate` did not find the master; the active Horizon container/service restart reloaded it. No automatic source-watch restart is established.

## Legacy Inspection measurement writes could overwrite a newer result

- **Evidence:** A red-first route regression sent two requests with the same `expected_result_version: 0`. The first saved a passing inspection measurement; the second stale request returned 200 and overwrote it with a failing result. The endpoint previously ignored the version and never advanced `result_version` for legacy measurement writes.
- **Impact:** Two inspectors editing one legacy Inspection could silently replace each other's committed evidence if the later request was based on an older screen.
- **Classification:** Confirmed Quality data-concurrency bug found while implementing realtime conflict handling; this is a functional finding tracked separately from event transport.
- **Disposition:** The measurement endpoint now checks an optional version under its existing row lock, increments `result_version`, and returns the typed 409 conflict. The SPA sends the version and keeps typed input while authorized data refetches. Focused API and SPA checks pass; no live two-user browser result was collected. Package this functional correction as its own PR unit when separating the accumulated work, and keep runtime UI matrix cells `NOT_VERIFIED`.


## Legacy Inspection item-result writes could overwrite a newer result

- **Evidence:** A red-first two-request API regression sent `expected_result_version: 0` to `POST /api/v1/quality/inspections/{inspection}/item-results`. Both requests returned 200, allowing the later stale FAIL decision to replace the previously saved PASS. The controller discarded the optional version for legacy inspections, and that service branch did not advance `result_version`.
- **Impact:** Two inspectors could silently replace saved checklist decisions or submit a batch based on an older Inspection state. This can leave the acting screen, another viewer, and derived status/actions inconsistent with the latest evidence.
- **Classification:** Confirmed Quality data-concurrency bug discovered while implementing realtime version/conflict handling; separately track as a functional PR unit.
- **Disposition:** The API now checks the optional version under the row lock and advances it on accepted item and batch writes. The SPA carries the returned version through its sequential lot workflow and retains user choices while authorized data refreshes after a 409. Focused API, SPA, syntax, formatting and lint checks pass. No live two-user Inspection UI run was performed; matrix runtime cells remain `NOT_VERIFIED`.


## Legacy Inspection cancellation could act on a stale screen

- **Evidence:** A red-first route test saved evidence with `expected_result_version: 0`, receiving version 1, then sent Cancel from version 0. Before the fix the stale request returned 200 and cancelled the Inspection. The legacy detail screen also omitted the version for Cancel and Review.
- **Impact:** A user could cancel after another session had updated the still-open Inspection, leaving the server status and other viewers' action buttons inconsistent with the operator's observed record.
- **Classification:** Confirmed Quality stale-action/data-concurrency defect discovered during realtime conflict work; track separately from the transport/event PR.
- **Disposition:** Cancel now compares a supplied version under the row lock for all modes and increments it after commit; detail sends the current version for both Cancel and Review. The stale route regression passes, the focused concurrency file passes 6 / 27, and the detail component file passes 17/17. No live two-user browser scenario ran; runtime matrix cells stay `NOT_VERIFIED`.


## Legacy Inspection lot-result submissions could overwrite newer evidence

- **Evidence:** A red-first API regression submitted `expected_result_version: 0` twice to the lot-result endpoint. Both requests returned 200 and the second changed the saved PASS measurement. The request validator and decoder dropped the version before the service reached its row lock.
- **Impact:** A legacy lot editor could replace another inspector's saved checklist result without seeing the newer evidence.
- **Classification:** Confirmed Quality stale-write/data-concurrency defect discovered during realtime version handling; track separately from transport work.
- **Disposition:** The optional version is now validated, forwarded and compared under the row lock; accepted writes advance it if no nested measurement save already did. The focused API regression passes 1 / 6 assertions. The legacy endpoint has no current SPA caller, and no two-user browser outcome was tested.


## Inspection event refetch could leave peer detail stale after a canceled read (fixed in delta 614)

- **Evidence:** A live three-context `inspection-cancel-realtime.spec.ts` run showed both authorized private event frames arriving and the API returning `status=cancelled`, while a peer's active detail query could remain Draft. Query-cache tracing plus a red-first central invalidator regression reproduced the race: a concurrent reconnect/focus catch-up canceled the event-triggered read; the previous value remained and the query stayed invalidated without a retry.
- **Impact:** A viewer could keep seeing the old Inspection status and Cancel action even after receiving the event, until another refetch or manual refresh. The reproduced failure was in client query reconciliation, not an API/Redis response cache.
- **Classification:** Confirmed frontend realtime freshness defect in the shared invalidation path.
- **Disposition:** The central invalidator retries once when a matching active query remains invalidated and idle after the event-driven fetch. Inspection list/detail keys were separated, the settings-options key was aligned with invalidation, and API read functions now forward AbortSignals. The red-first invalidation regression and focused map suite pass (87/87); Inspection detail passes 17/17 and API signal tests 4/4. A final live role-separated run passed with actor socket blocked: 795 ms action→actor UI, 901 ms API response→peer detail, 1,242 ms API response→list. One sample only; not p95. Related records, badges, dashboards, inbox, other Inspection routes and full-suite coverage remain unverified.

## Degraded catch-up could repeatedly abort initial record reads (fixed in delta 615)

- **Evidence:** The next live Inspection attempt timed out before mutation with an empty peer `main` region, while trace showed an aborted initial detail request followed by an authorized Draft response. A red-first shared QueryClient regression directly observed `AbortSignal.aborted === true` when `catchUpRealtimeQueries()` ran during an ordinary active detail fetch.
- **Impact:** A socket outage or slow API could keep active detail pages on their loading state because visibility-aware catch-up restarted work already in flight. This affects any active query family, not only Inspections.
- **Classification:** Confirmed frontend realtime/fallback fetch starvation; separate from the event-owned detail-refetch race in delta 614.
- **Disposition:** Catch-up now preserves active requests and queues one trailing authorized read for non-event queries that were already fetching; event-owned reads are allowed to finish. The regression passes, the map/provider suites pass 89/89 and 7/7, and the same one-scenario role-separated Inspection cancel E2E passes with actor socket blocked. Timings were 595 ms actor action→UI, 1,014 ms API response→peer detail, and 1,315 ms API response→peer list. No p95, broad module coverage, cache behavior, or full suite is claimed.


## Purchase Request create confirmation can remain open if lookups are fetching

- **Evidence:** A focused Purchase Request realtime run timed out before mutation while the submit confirmation dialog remained open; its browser trace showed no POST create request. Source inspection shows the confirmation handler silently returns when the retained draft is missing or any options query has `isError`/`isFetching`, but the confirmation button's disabled state reflects only the create mutation. The browser run did not capture `lookupPending` at the instant of the click, so that guard is a plausible trigger, not a proven cause of that timeout.
- **Impact:** If an options query begins or remains in flight while the confirmation dialog is open, Submit may do nothing and provide no explanation. This is a form-flow issue separate from real-time delivery/cache invalidation.
- **Classification:** Source-confirmed UI guard with an unisolated runtime trigger; not counted as a confirmed stale-data root cause.
- **Disposition:** Track in its own functional PR. No form business behavior was changed in the real-time/cache work; full API suites were not run.

## Transient Echo initialization failure stranded active private subscriptions (fixed in realtime foundation delta 650)

- **Evidence:** A red-first provider regression made the initial Echo load reject and the next attempt succeed. Before the fix, the provider called `getEcho()` only once and stayed Offline. Source tracing found active channel registrations also cleared their failed in-flight promise without any retry path; after a future Echo load, mounted channel handlers stayed unattached. The provider regression and subscription-registry regression failed before implementation and now pass.
- **Impact:** A transient chunk/network failure during SPA startup could leave active screens on HTTP catch-up indefinitely without restoring their authorized WebSocket subscriptions, even after the browser recovered. This affected the shared realtime layer.
- **Classification:** Confirmed frontend realtime recovery defect. Reproduced in controlled unit tests only; no two-browser or live Reverb outage scenario was run.
- **Disposition:** Delta 650 adds bounded exponential Echo initialization retries, immediate retries on online/focus/visibility, cleanup of the retry timer, and reopens active private registrations after Echo recovers. Focused provider and registry tests pass 8/8 and 5/5; targeted ESLint and a direct trailing-whitespace check pass. Treat this as a separate realtime foundation review unit. No cache, database, business behavior, or deployment changed.

## Overtime rejection dialog kept a stale request action enabled (fixed in delta 651)

- **Evidence:** A red-first component regression opened Reject for a pending Overtime request, entered a reason, replaced the active authorized list row with Approved and `available_actions.reject=false`, then observed that Confirm reject stayed enabled. The page suite now passes 2/2 after deriving the dialog target from its current query response. The approve dialog was updated using the same ID/current-action guard but was not independently exercised.
- **Impact:** A peer transition delivered through realtime invalidation could update the list while an already-open rejection confirmation continued to present and submit stale state. The server still enforced the state guard; this was a UI freshness defect.
- **Classification:** Confirmed client confirmation-snapshot defect; regression evidence covers rejection only and does not prove live event delivery.
- **Disposition:** Delta 651 stores approve/reject targets by ID, renders current available actions, blocks stale submission while the query is fetching/error/unavailable, and preserves the user's reject reason. Focused page tests pass 2/2 and targeted ESLint passes. No backend, cache, database or business rule changed. Keep this correction as a separate functional review unit.

## Customer Pricing withdrawal confirmation retained a stale action (fixed in delta 652)

- **Evidence:** A red-first component regression opened Withdraw for a pending-finance Price Request, changed the active authorized list result to Offered with `available_actions.can_withdraw=false`, and observed that Withdraw remained enabled. The isolated regression passes after the change.
- **Impact:** A viewer could continue to act from a stale confirmation after another transition removed the action. The API remains authoritative and revalidates state; this defect was in the rendered confirmation.
- **Classification:** Confirmed client stale-action defect. The regression covers query reconciliation only; no peer WebSocket delivery or API mutation was tested.
- **Disposition:** Delta 652 stores the active target ID, derives it from the current authorized list result, disables and guards Approve/Withdraw against fetch/error/missing-row/current-action state, and preserves notes. The focused test passes 1/1 and targeted ESLint passes. The broader combined review test file had 8 failures / 2 passes, several due duplicate responsive controls matched with singular queries; no pre-change baseline was run. Keep as a separate functional review unit.


## Bill payment rejection confirmation trusted a stale detail snapshot (fixed in UI slice delta 653)

- **Evidence:** A red-first Bill detail component regression opened Reject for a pending payment, replaced the active detail query row with Posted / `can_decide=false`, then observed Confirm reject still enabled before the fix. The isolated test passes after the guard was added.
- **Impact:** If another action updates the Bill while the dialog remains open, the user can continue to see a stale rejection button until the detail response reconciles. The server remains authoritative and revalidates the payment state.
- **Classification:** Confirmed frontend stale-action defect, separate from missing event delivery and cache invalidation.
- **Disposition:** Delta 653 stores Reject/Void targets by ID and re-derives current payment state from the authorized detail query. It guards current-state eligibility for approve/reject/void and preserves the reject reason. The focused rejection regression passes 1/1 and targeted ESLint passes. Approve/Void were not separately regression-tested; no API mutation or peer-browser event was tested. Keep this correction in its own review unit.


## Recovery operation identifier survived logout in tab storage (fixed in cache-scope slice delta 656)

- **Evidence:** A red-first auth-store regression wrote `ogami.recovery.operation_id`, logged out, and observed the key remained in sessionStorage.
- **Impact:** A later recovery session in the same tab could start by polling an earlier operation identifier. Every read still required the separate fresh recovery token, so this was stale UI state rather than an authorization bypass.
- **Classification:** Confirmed persisted client-state cleanup bug discovered during cache-scope review; track separately from the realtime foundation.
- **Disposition:** Delta 656 adds the exact key to shared auth-scope cleanup. The focused regression passes 1/1 and targeted ESLint passes. No recovery browser flow ran.


## Return Management split dialog retained stale received quantity (fixed in UI slice delta 659)

- **Evidence:** A red-first component regression opened Split quantity with a received total of 100, entered 2, then replaced the active authorized RMA detail query with a line whose current received total was 1. Before the fix, Split line remained enabled because the dialog retained a copied `ReturnRequestItem`.
- **Impact:** A viewer could continue seeing a valid split action after another receipt or line update made the entered quantity invalid. The API remains the authoritative state validator; no stale API mutation was attempted in this reproduction.
- **Classification:** Confirmed frontend stale-detail snapshot defect, separate from event delivery/cache invalidation.
- **Disposition:** Delta 659 stores the target line ID, reads current line/status/disposition from the active query, disables while refetching or unavailable, preserves typed input, blocks stale submission, and invalidates the central RMA query after errors. The red-first regression now passes; the complete focused page file passes 5/5 and targeted ESLint passes. No peer browser, WebSocket, API mutation, or cache behavior was exercised. Keep this correction in its own UI review unit.


## Admin Role Archive confirmation retained stale eligibility (fixed in UI slice delta 661)

- **Evidence:** A red-first component regression opened Archive for a custom role with `users_count=0`, then updated the active authorized list row to `users_count=1`. Before the fix, the dialog retained the copied Role and left Archive enabled; afterward it disables and prevents the API call.
- **Impact:** A manager could attempt to archive a role after another user assigned it, receiving a server rejection from a stale confirmation. The role service remains authoritative and protects assigned/system roles.
- **Classification:** Confirmed frontend stale-action snapshot defect, separate from peer event delivery.
- **Disposition:** Delta 661 uses the shared `useQueryRecordTarget` hook to derive Archive/Restore targets and freshness from the current query, then gates current row eligibility and guards submission. API errors invalidate through the central role map; successful 204 responses remove the actor row immediately and invalidate related role views. The regression passes 1/1 and targeted ESLint passes; Restore shares the guard but was not independently exercised. No API action, second browser, live event, cache backend, or latency sample ran. Keep this UI correction separate; matrix runtime outcomes remain `NOT_VERIFIED`.


## HR Training Archive confirmation retained a stale server action (fixed in UI slice delta 662)

- **Evidence:** A red-first component regression opened Archive while the Training API resource allowed `archive`, then changed the active authorized list row to an empty `available_actions` list. The open dialog remained enabled before the fix and is disabled afterward.
- **Impact:** A viewer could submit an archive attempt from a modal that no longer reflects the server's current action projection. The API remains authoritative and rechecks the transition.
- **Classification:** Confirmed frontend stale-action snapshot defect, independent of event delivery.
- **Disposition:** Delta 662 uses the shared `useQueryRecordTarget` hook to derive Archive/Restore targets and query freshness. Fetch/error/missing-target and current server actions gate and guard the dialog; successful 204 responses remove the actor row before central invalidation and errors invalidate affected Training views. The focused regression passes 1/1 and targeted ESLint passes. Restore shares the guard but was not separately exercised. No API mutation, peer browser/event, cache backend or latency was tested; matrix runtime cells remain `NOT_VERIFIED`. Keep this UI correction separate.


## Scheduled Export confirmations lacked live row/action projections (fixed in UI/API slice delta 663)

- **Evidence:** Red-first component regressions showed that Archive remained enabled both when the target disappeared and when the current row's action changed from Archive to Restore. Source tracing found `ScheduledExportResource` emitted no `available_actions`, so `trashed=with` rows could not carry their current state to the UI. The new API contract test initially failed because both active/archived records omitted the expected projection.
- **Impact:** Users could see obsolete Edit/Archive/Restore/Status controls, including an Archive attempt against a schedule already archived by another user.
- **Classification:** Confirmed missing server action projection plus detached frontend dialog target; distinct from WebSocket delivery and backend cache invalidation.
- **Disposition:** Delta 663 adds server-computed `update/archive/restore` flags; the SPA now renders controls from them and binds open confirmations to current query data and freshness. Successful bodyless actions remove a row from the current active/only filter immediately and invalidate via the central `scheduled_export` map; failures also invalidate. The API contract test passes (1 test/5 assertions), the focused SPA file passes 2/2, and targeted ESLint/PHP syntax checks pass. Pint flagged the resource's existing formatting convention; its broad reformat was reverted to avoid unrelated churn. No API transition, second user, WebSocket, backend cache, or latency run; runtime matrix cells remain `NOT_VERIFIED`.

- **Actor count follow-up (Delta 664):** A focused response-first regression held central refetch pending and found the row disappeared while the header still showed `1 schedules`. The 204 response is intentionally bodyless. `removeScheduledExport()` now updates both rows and paginator total together for Archive/Restore exits from the current filter. The page test passes 3/3 and targeted ESLint passes. No API request, other user, socket or latency was measured.


## Inventory dashboard summary threw after a stock movement (fixed, separate functional review unit)

- **Evidence:** While adding a focused regression for the inventory cache race, the first attempt to call `InventoryDashboardService::summary()` after committing a stock receipt failed with `Class "App\Modules\Inventory\Services\Str" not found` at the recent-movements label expression. `InventoryDashboardService.php` calls `Str::headline()` without importing `Illuminate\Support\Str`.
- **Impact:** Once at least one stock movement is present, the dashboard summary can fail while formatting its recent-movements section. The cache invalidation regression was narrowed to the same generation cache with a stock-value resolver so this independent behavior did not become part of the cache change.
- **Classification:** Confirmed runtime functional bug, separate from cache freshness and real-time delivery.
- **Disposition:** Added the missing `Illuminate\Support\Str` import as a separate one-line functional change. The existing `InventoryDashboardHashIdTest` failed before the import and passes afterward (1 test / 3 assertions). Keep this file-level change as its own review/PR unit when packaging; no dashboard endpoint/browser scenario was run.


## Stock Count response omitted viewer-specific available actions (fixed in Delta 689)

- **Evidence:** `StockCountSessionResource` and `StockCountItemResource` had no `available_actions`; the SPA derived workflow controls from local permission/status/variance checks. A red-first HTTP contract test observed a null action projection for a newly created session. Source inspection confirms stale state preconditions already return HTTP 409 through `StockCountStateConflictException`; the older matrix statement that they returned 422 was outdated.
- **Impact:** After a peer refresh, each user's controls were still reconstructed client-side instead of reflecting that user's server-computed permission, delegation and maker-checker eligibility. This is a confirmed action-contract gap, but it was not reproduced as a live two-user event-delivery failure.
- **Disposition:** Delta 689 adds viewer-scoped session/item actions and switches the SPA lifecycle/variance controls to them. The focused API test passes for a manager, independent checker and view-only user (1 test/14 assertions); the focused SPA regression passes 1/1 and targeted ESLint/Pint pass. No live transition or peer browser ran; matrix runtime cells remain `NOT_VERIFIED`.


## Stock Count submit was silently dropped during a realtime refetch (fixed in Delta 690; separate review unit)

- **Evidence:** A two-context browser trace showed a completed Record click, no count request, and the dialog still open while the active Stock Count detail query refreshed. Source tracing found the successful form callback returned when `sessionUnavailable` was true, including during `isFetching`. The red-first component regression reproduced the missing `recordCount` call with a pending detail refetch.
- **Impact:** A warehouse user could submit a count just as a reconnect/catch-up or event invalidation started a detail refetch; the UI silently discarded the request instead of letting the authoritative service decide whether the current state still allowed it.
- **Disposition:** Removed the redundant submit-time fetch return. The UI still disables the button while the record is stale; a submit already dispatched now reaches the existing locked server validation and reconciles from its response or displays the normal conflict error. The focused SPA file passes 2/2. One follow-up two-context Playwright scenario passes with a Warehouse Staff actor and System Administrator peer: API 200, private event, authorized peer GET returns Counted, and both detail rows/actions update without reload. One response-to-peer-render sample was 1,058 ms. No p95/commit-to-UI, socket-disabled browser, list/badge/dashboard/inbox or variance-approval evidence was collected. Package this functional correction as its own review unit.

## Isolated Inventory process runner received HTTP 200 for WSS handshake (cause unisolated)

- **Evidence:** The existing isolated Playwright result at `spa/test-results/process-results.json` records a browser connection to `wss://nginx/app/{app-key}` failing because the handshake response was HTTP 200. The test's route observation remained `/dashboard/warehouse`, and `PageDiagnostics.assertClean()` failed during the first Stock Adjustment create step before form entry or submission. The auth setup case passed. The E2E Nginx source does configure `/app/` to proxy to Reverb with upgrade headers, but the isolated containers had exited before their active config and logs could be inspected.
- **Impact:** This run could not establish WebSocket delivery or execute the intended Inventory transition. It provides no evidence that a record became stale after mutation.
- **Classification:** Confirmed one-run isolated-stack transport/test-harness failure; root cause unknown. It is not a confirmed product-level realtime miss and is not promoted as a root cause.
- **Disposition:** Recorded in `00-discovery.md` and `PROGRESS.md`. No retry was launched. Reproduce only if the isolated runner is needed again; capture the live Nginx `/app/` request, selected server/location, and Reverb response before container cleanup. The separate `ogamiph.dev` Purchase Request multi-user run passed, but does not prove this isolated topology works.

## PHP-FPM saturation delayed an authorized detail refetch on the dev stack (capacity raised; residual latency)

- **Evidence:** During one failed four-context Purchase Request approval E2E, Nginx's active query-free timing log recorded `upstream_connect_time` near zero and 2.3–5.0 second PHP-FPM upstream header/response times for detail GETs (up to 5.1 seconds for options), including aborted 499 requests. PHP-FPM logged that all 10 children were busy. Responses were private/no-store, Cloudflare DYNAMIC, and not served by the service worker. The prior 3.769-second browser detail GET had also occurred while FPM reported the 10-child cap.
- **Impact:** Other roles can receive the correct server record and action projection but render them late while the API worker pool is saturated, making realtime appear delayed.
- **Classification:** Confirmed dev-stack capacity bottleneck under the tested multi-context burst; exact per-request application work versus FPM queue wait remains unisolated. It does not establish stale-cache behavior.
- **Disposition:** Raised only the dev image's `pm.max_children` from 10 to 16; production's separate image remains unchanged. The current host snapshot had 9.9 GiB available and approximately 56–63 MiB RSS per active worker. `php-fpm -t` passed, services and `ogamiph.dev` remained available, and one post-change approval E2E passed with peer render at 1.821 seconds. Other upstream reads still reached 4.545 seconds, so capacity is only partially mitigated; no p95, memory peak under sustained load, or controlled A/B result is claimed. Track this capacity change separately from domain-module PRs.
- **Delta 883 follow-up:** Current FPM configuration still reports `pm.max_children=16`; the VPS reports 15 GiB total RAM (9.9 GiB available in the same live snapshot, no swap). The query-free Nginx timing log for the last 30 minutes contained only 7 API requests, 6 of them `/api/v1/health`; their request/upstream p95 was 159 ms. This is an idle-health sample, not evidence that detail, options, or mutation requests meet the 2-second target under concurrent load. The earlier 4.545-second upstream read remains the relevant unresolved burst observation. No load test or pool-size change was made.

## Purchase Request change pings were mislabeled as status transitions (corrected in Deltas 726–727)

- **Evidence:** The four-context approval scenario recorded three distinct, individually published event IDs for approval, conversion-pending, and manual-conversion-required. The last two writes leave the Purchase Request status `approved`; source trace showed `ChainBroadcaster::broadcastFor()` defaulted every call to `status_changed`. Focused regressions also found draft create/edit/delete/restore and the PO-conversion, RFQ-sourcing, stock-planning, auto-replenishment, and MRP-creation pings mislabeled.
- **Impact:** Consumers receive misleading action classifications for Purchase Request creates, edits, deletes/restores, and metadata changes. The events were not duplicate deliveries, and this classification issue did not cause the observed slow API refetch or prove cache staleness.
- **Disposition:** Deltas 726–727 add explicit action metadata and classify Purchase Request writes across service, model, listener, PO conversion, RFQ, Inventory replenishment, MRP, and stock-planning paths. Seven focused tests across the two deltas pass (7 tests / 87 assertions). No browser or full suite ran; route-level runtime outcomes are unchanged.
## Finding — Invoice payment review controls lacked server action flags (fixed, Delta 749)

`InvoicePaymentSubmissionResource` returned status without viewer-scoped Verify/Reject eligibility, and the Finance dialog inferred those controls from the local permission snapshot and Pending status. `InvoiceResource` likewise omitted a `review_payment` flag. This allowed stale controls to remain visible after a permission or record-state change, even though `InvoicePaymentSubmissionService::verify()` and `reject()` lock and revalidate the parent and submission before writing; no authorization bypass was established.

The resources now return `available_actions.verify`, `available_actions.reject`, and `available_actions.review_payment`; customer-portal principals receive false for the internal review actions. The acting dialog reconciles the API response immediately. Evidence: focused API test 1/24 assertions, focused SPA test 5/5, targeted ESLint and PHP syntax checks. No two-user browser run was performed, so route-level UI freshness remains `NOT_VERIFIED`.
## Finding — Purchase Order Short-close action was outside the server action map (fixed, Delta 751)

Purchase Order Submit/Approve/Send/Close/Cancel already used `PurchaseOrderAccessPolicy::actionsFor()`, but Short-close combined a state boolean in the API response with the SPA's permission snapshot. This left one primary status control outside the server action contract. The API now returns `actions.can_short_close` using the existing `purchasing.po.create` route permission and received-goods state predicate, and the SPA renders from that flag. The route and service keep all existing guards; no business rule changed. Red-first focused API regression passes 1 test / 3 assertions; Purchase Order detail component tests pass 3/3; targeted ESLint passes. No peer-browser or socket scenario was run; matrix UI cells remain `NOT_VERIFIED`.


## Finding — Purchase Order supplier-response controls used a client permission snapshot (fixed, Delta 753)

The Purchase Order detail page decided supplier Accept/Reject visibility from pending status and the SPA's local permission snapshot. A focused component test reproduced a stale actionable button when the server response explicitly denied both actions. The PO resource and response resource now return viewer-specific `available_actions.accept/reject`, computed from the existing permission and pending-state rule. Each control renders only from its corresponding flag; the actor's mutation response is reconciled into the embedded response immediately. Existing route/service permission, parent visibility, lock, and state checks are unchanged. API regression passes 1 test / 7 assertions, PO detail tests pass 4/4, and targeted lint/syntax checks pass. This is not a cross-user delivery reproduction; matrix runtime cells remain `NOT_VERIFIED`.


## Work Order detail TypeScript build was blocked by an undefined presentation flag (fixed, Delta 755)

The page's stock-work-order cancellation label/dialog referenced `stockProduction` after the server action-map migration, but no local value was defined. SPA-wide TypeScript compilation reproduced four TS2304 errors at the label and dialog guards. The page now derives the value from the API's `production_purpose === 'stock'`; cancellation eligibility still comes exclusively from the server action map, and existing server validation remains authoritative. The typecheck rerun emitted no diagnostics, focused stock cancellation/retry component cases pass 7/7, and targeted ESLint passes. No business rule changed. The same compile run found and fixed a narrow Invoice test fixture type; no runtime code changed for that issue.

## Sales Order primary actions trusted client state (action contract fixed; cross-user freshness remains unverified)

- **Evidence:** Red-first detail regressions supplied `available_actions.can_confirm=false` (and later `can_edit=false`, `can_cancel=false`) while local permissions/state allowed the controls; Confirm/Edit remained visible. Red-first Laravel API assertions found all three action fields missing from the SalesOrder resource. The controller permission check and `SalesOrderService` locked state checks remain authoritative.
- **Impact:** The detail page could expose stale primary controls when its local status/permission snapshot diverged from the server. No unauthorized write was demonstrated.
- **Disposition:** `SalesOrderResource` now returns viewer-scoped `available_actions.can_edit`, `can_cancel`, and `can_confirm`, using existing permissions and state predicates; the detail page renders those three controls only from the server flags. Focused API evidence passes (1 test / 12 assertions) and Sales Order detail component tests pass 9/9. Earlier targeted ESLint/PHP syntax and SPA typecheck passed for the confirmation-only version; the expanded change passes targeted ESLint, PHP syntax, Pint on the focused API test, and SPA-wide `npm run typecheck`. Pint on the focused test is clean; the resource was not reformatted wholesale. No peer browser, live event receipt, or two-user Sales Order transition was run, so matrix runtime cells remain `NOT_VERIFIED`.


## Asset Edit control used only the client permission snapshot (fixed; peer freshness remains unverified)

`AssetService::update()` rejects disposed assets under a row lock, but Asset detail still showed Edit for them when the local `assets.update` permission was present. The resource omitted server action eligibility. `AssetResource` now returns `available_actions.can_edit` from the existing permission and immutable-state rule, and the page uses that field. Red-first component and API regressions reproduced the mismatch. Focused SPA tests pass 7/7; focused API test passes 1 test / 4 assertions with a PHPUnit warning. Targeted ESLint, PHP syntax, and Pint pass. No two-user transition or event delivery was run; matrix runtime outcomes remain `NOT_VERIFIED`.

## Delegation changes left delegated-role bottlenecks stale until the widget timer (fixed in Delta 800)

`ChainBottleneckController` includes roles supplied by `ApprovalDelegation::actsForRoles()`. The after-commit `ApprovalDelegationChanged` event already targeted the changed user's exact private channel, but only the delegation page listened and the central signal map omitted `['chain-bottlenecks']`. This was a source-confirmed invalidation omission; no delegate dashboard browser failure was reproduced. The shared shell now subscribes on the same exact-user channel, and the central map refreshes both the delegation list and active bottleneck queries through each viewer's authorized API. Red-first map regression: failed before the map entry and passed afterward (1 selected test); targeted ESLint passes. The SPA-wide TypeScript process exited but its exit code was not captured, so no typecheck pass is claimed. No two-user delivery, API transition, cache hit, or latency measurement ran; route matrix outcomes remain `NOT_VERIFIED`.

## Rich dashboard widgets retained stale snapshots after source events (fixed in Delta 801)

`WidgetAnalyticsService` stores rich payloads under independent `widget-rich:{key}` generations, while `DashboardWidgetDataService` uses `widget-summary:{key}`. A focused Invoice → `finance.ar_aging` observer regression proved that source writes rotated the scalar generation but left the rich payload selectable. The observer now pairs summary invalidation with the corresponding rich generation for registered rich-widget keys, maps the previously source-only Executive/Quality rich widgets to their source models, and resolves dynamic `kpi.{code}` summary/rich generations on KPI definition and snapshot writes. `ApprovalDelegation` also explicitly retires both `approvals.pending` generations so its exact-user refresh cannot reuse a prior delegated approval count/list.

Verification: the focused Invoice, dynamic KPI and ApprovalDelegation regressions pass (3 tests / 6 assertions); both edited PHP files pass `php -l`; static source reconciliation found no unhandled literal rich widget key beyond dynamic KPI keys. The frontend delegation-signal invalidation regression passes (1 selected test; 93 skipped). PHPUnit prints the known missing `/var/www/.env` warning. No two-user browser, WebSocket delivery, cache-hit rate, or request-performance check was run. Matrix runtime cells remain `NOT_VERIFIED`.

## Delegation changes left the per-user approval widget cache stale (fixed in Delta 801)

`ApprovalBoardService` includes active delegated roles, and the private `ApprovalDelegationChanged` event refreshes the affected users. Before this slice, the event map refreshed the delegation list and chain bottleneck only, and `ApprovalDelegation` was not a dashboard-cache source. A refetch of the dashboard could keep the previous cached `approvals.pending` count/list through its 30-second TTL. The model now rotates the approval summary/rich generations before its existing event observer stages the after-commit outbox ping, and the frontend signal map invalidates active dashboard widget data for the affected user. Red-first backend generation and SPA invalidation checks failed before their respective mappings and pass after (3 focused API tests / 6 assertions total across Delta 801, one selected SPA test). No cross-user browser/render or socket delivery was run; dashboard and approval-inbox matrix cells remain `NOT_VERIFIED`.

## ScheduledExport was mislabeled in the record inventory (corrected in Delta 803)

The row sourced from `api/app/Common/Models/ScheduledExport.php` was labeled `to`, which made the inventory inaccurate even though its source file and observer registration were present. A model-path comparison found no other missing model-source paths. The catalog now names the record `ScheduledExport`; no application behavior changed.

## Purchase Request stale actions returned 403/422 instead of entering conflict recovery (fixed, Delta 809)

The locked status checks already prevented stale Submit/Approve/Reject/Cancel writes, but returned generic business-rule errors. Submit and Cancel also used policy methods that folded current status into actor scope before the lock check. Repeating Submit after a successful submit reproduced HTTP 403; the action never reached the shared SPA 409 refresh path. The policy now checks ownership/visibility independently, and locked stale-state checks return HTTP 409 with `purchase_request_state_conflict`. The shared `useOptimisticStatusAction` already rolls back and invalidates the detail/list/related queries on 409. Focused API regression passes (1 test / 4 assertions, PHPUnit warning status); PHP syntax checks pass. Pint passes for the new exception, policy, and controller, with one existing style issue each in the service and test file. No browser/two-user run, full module suite, cache change, or matrix outcome promotion was performed; peer delivery and UI freshness remain `NOT_VERIFIED`.

## Purchase Order stale status actions did not refresh the detail view (fixed, Delta 810)

The Submit service guarded the locked row but the precheck combined Draft state with actor ownership, so repeating Submit after success reproduced HTTP 403 before the lock. Approve/Reject/Cancel locked-state failures were generic 422s, and the detail page only toasted mutation errors without refetching. A focused regression also reproduced a stale Submit button remaining in an open confirmation dialog after its record refreshed. The policy now checks buyer permission/ownership independently; stale locked transitions return HTTP 409 with `purchase_order_state_conflict`. Purchase Order status-action errors use the central invalidation map and close stale dialogs. Focused API endpoint regression passes (1 test / 3 assertions), cross-buyer ownership regression passes (1 / 3), and the full focused detail component file passes 5/5; the two API tests carry the existing missing `/var/www/.env` warning. PHP syntax, targeted ESLint, Pint for six boundary files, and `git diff --check` pass. No two-user/socket run, full suite, cache change, or matrix promotion was done.

## Bill posting used a validation-style response and left the stale action visible (fixed, Delta 811)

BillService already locked and rechecked state before posting, but a Bill that had left Draft caused generic 422 and Bill detail only showed a toast. The stale post/override dialog and cached action map therefore remained until refresh. The locked state guard now returns HTTP 409 with `bill_state_conflict`; the SPA closes the dialog and invalidates the authorized Bill resource through the central map. Focused API regression passes (1 test / 2 assertions, PHPUnit warning-marked by missing `/var/www/.env`); selected component regression passes (1 test, 8 filtered out). PHP syntax and targeted ESLint pass. Pint passes for the new exception and reports a pre-existing style issue in BillController. No full suite, two-user delivery, cache change, or matrix outcome promotion was done.

## Sales Order confirmation returned a generic stale-state error without refreshing actions (fixed, Delta 812)

The confirm service locked and rechecked the order, but a repeated confirmation after state advanced produced HTTP 422. The Sales Order detail handler only displayed the error and retained its stale confirmation state. The API now returns typed HTTP 409 `sales_order_state_conflict`, and the detail page closes the stale confirmation dialog and refetches the authorized order. Focused API (1 test / 3 assertions) and selected SPA component (1 pass; 9 skipped) regressions pass; the API test is warning-marked by the known missing `/var/www/.env` container read. This evidence covers the stale action response/recovery only, not cross-user WebSocket delivery; matrix cells remain `NOT_VERIFIED`.

## Shared SPA query defaults allowed cached API snapshots to appear fresh for 30 seconds (fixed, Delta 813)

The shared QueryClient used `staleTime: 30_000` for API-backed queries without an override. The focused red-first regression observed the 30-second setting. This meant a remounted page could reuse an old snapshot without an immediate authorized API revalidation if its event invalidation had been missed. The default is now zero while retaining the in-memory cache; explicit query lifetimes remain. The focused regression passes 1/1. Network request-rate impact was not measured, and this code-level finding is not two-user WebSocket evidence.

## Dashboard attention and badge counts retained stale snapshots on remount (fixed, Delta 814)

The Action Center used an explicit 30-second TanStack freshness window, and sidebar badges used 15 seconds, overriding the zero shared default. Red-first component/hook regressions showed a freshly cached Action Center snapshot skipped the API on remount and the badge query retained 15 seconds. Both now revalidate on remount while retaining the in-memory cache and existing event invalidations. Focused tests pass; two-user event delivery and request-rate impact remain unmeasured.

## Customer portal invoice signal counts reused a 30-second cached snapshot on remount (fixed, Delta 815)

`PortalLayout` explicitly kept customer invoice-signal counts fresh for 30 seconds. A red-first regression seeded the scoped cache and showed the portal skipped an authorized API revalidation on mount. The override is now zero; in-memory caching and the existing private refresh path remain. Focused portal/dashboard/badge/query-client tests pass 13/13. Two-user portal event delivery and API request-rate impact remain unmeasured.
# 2026-11-04 — Dev SPA startup prevented audit-log browser reproduction

- **Observation:** A headless browser authenticated against `ogamiph.dev` (sign-in HTTP 200) and received HTTP 200 for `/admin/audit-logs`, but the SPA did not render its page heading/list before a 90-second Playwright timeout. Vite module responses in the captured trace took approximately 60–72 seconds. The two-context scenario never reached the second login.
- **Impact:** The dev deployment's initial SPA startup currently blocks this browser-based realtime verification. No event-delivery conclusion can be drawn from the run.
- **Classification:** Reproduced dev startup/asset-serving latency; underlying source not yet isolated. Keep this separate from realtime/cache functional fixes until measured against Vite, Nginx/proxy and browser timing.
- **Disposition:** Revisit under a separate dev-serving performance change. Do not count this failed attempt as a two-user result or promote audit-log matrix cells.

## Portal notification refresh could be lost after the database commit (fixed in Delta 863)

- **Reproduction:** A focused feature regression called the portal notification read endpoint and found no matching durable `event_outbox` row; before the fix it failed at the outbox assertion.
- **Cause:** Portal create/read/delete signals were dispatched directly from post-commit callbacks. The business row was durable, but the realtime ping had no recovery record if the PHP process exited before publishing.
- **Fix:** Added `PortalNotificationChanged` to the allowlisted outbox codec and staged create/read/read-all/delete/clear-read pings in the same transaction as the inbox mutation. Publication now uses the existing after-commit broadcast dispatcher, retry lane, and scheduler recovery.
- **Verification:** Five selected PortalNotificationRealtimeTest cases pass (27 assertions); PHP syntax checks pass. The exact private portal-user channel and refresh-only payload are preserved. No multi-user browser delivery or latency sample was run; route matrix cells remain `NOT_VERIFIED`.

## Decision Center snoozed detail referenced a removed local variable (fixed in Delta 912)

- **Reproduction:** The focused existing `RecommendationDetails.test.tsx` case for a future snooze failed while rendering with `ReferenceError: snoozeReturn is not defined`; the SPA-wide TypeScript check also reported TS2304 at the same reference.
- **Cause:** The Decision Center action projection migration removed the local `snoozeReturn` date variable after action eligibility moved to server-provided `available_actions`, but retained the snoozed-until display condition that referenced it.
- **Fix:** The display guard now checks the existing `recommendation.snoozed_until` API field directly. No workflow/action behavior changed.
- **Verification:** The same focused test passes (1 selected, 9 skipped), and a fresh `npm run typecheck -- --pretty false` exits 0. This was a render/compile defect, not evidence of WebSocket freshness or cross-user delivery.

## Return Management kept Cancel actionable after physical receipt (fixed in Delta 915)

- **Reproduction:** A red-first component regression rendered the Cancel button for an approved RMA whose API projection said cancellation was unavailable. The API regression also failed because `available_actions.can_cancel` was missing. The service's existing locked guard rejects cancellation after `received_at` or receipt/stock effects.
- **Cause:** The detail page derived Cancel visibility from the `approved` status and local `return_management.manage` permission only; it did not use the service's physical-effects precondition.
- **Fix:** The resource now projects viewer-scoped cancellation eligibility from the same state/physical-effects predicate used by the cancellation service. The page renders Cancel only when `available_actions.can_cancel` is true. A shared controller resource helper loads approval records before returning every mutation response so the acting user's action map is current.
- **Verification:** The final selected API run passes three approval/action methods (36 assertions; known missing `/var/www/.env` and no-eligible-approval-notification warnings), and two selected SPA component regressions pass. Changed PHP syntax, Pint, ESLint and diff checks pass. No two-user socket or live workflow scenario was run; the coverage matrix remains `NOT_VERIFIED`.

## Return Management workflow buttons were derived from client status/permissions (main controls migrated in Delta 916)

- **Reproduction:** An approved RMA whose response explicitly set `available_actions.can_receive=false` still rendered Record Receipt because the detail page rebuilt action visibility from status and the local permission snapshot.
- **Cause:** Most workflow buttons and the Edit/Quality-retry controls did not use a server action projection; action eligibility could diverge across stale client data and cross-role permissions.
- **Fix:** The resource now returns viewer-scoped flags for Submit, Edit, Receive, Inspect, Quality retry, Dispose, Complete, and request-level Split; the detail reads these flags. The Complete projection shares the existing service MRB predicate, and Dispose is suppressed when known return-inspection evidence is missing or nonterminal. Mutation services continue to enforce their existing locked rules.
- **Verification:** Red-first component regression reproduced the button; the focused detail component file passes 8/8 and the selected API projection test passes 1/7 assertions. PHP syntax/Pint, targeted ESLint, diff check and live site/API health pass.
- **Limit:** No multi-user/socket run was performed. The request-level Split flag does not represent all line-specific receipt/allocation/quality constraints, and disposition-specific Quality eligibility remains enforced by the service. Keep matrix cells `NOT_VERIFIED` and review this as a bounded RMA action-contract unit.

## RMA stale state actions returned 422 and left stale controls visible (fixed in Delta 917)

- **Reproduction:** Repeating Submit on an RMA after it had entered `pending_approval` returned HTTP 422. The page's error handler only showed a toast and did not refetch the status/actions.
- **Cause:** Locked status mismatches used the generic `BusinessRuleException`/422 path; the actor had no 409 recovery behavior if a refresh event was missed.
- **Fix:** RMA state conflicts now return typed HTTP 409 `return_request_state_conflict`. Detail actions refresh through the central invalidation map. Rejection/disposition form values remain while stale submission is disabled; the edit view subscribes and preserves dirty fields when new server data arrives.
- **Verification:** The repeated-submit API test passes (1 / 3 assertions, known test-container/approval-notification warning); focused RMA detail and disposition component tests pass 11/11; PHP syntax/Pint, ESLint, and diff checks pass.
- **Limit:** No two-user browser/WebSocket trace, full API suite, full SPA typecheck, or latency measurement was run. Matrix rows remain `NOT_VERIFIED`.



## Supplier RFQ stale writes returned 422 and left portal controls stale (fixed in Delta 920)

- **Reproduction:** The red-first focused API case loaded an invited supplier's open RFQ, closed the RFQ in the fixture, then submitted a valid quote draft. The API returned 422 (`This RFQ is no longer accepting quotations.`), not a state conflict.
- **Cause:** The service correctly locks and revalidates the RFQ, but represents stale lifecycle state as a generic `BusinessRuleException`; the portal controller maps such failures to 422. Supplier quote Save, Submit, and Withdraw error handlers only toasted the response and did not invalidate the vendor-scoped RFQ family.
- **Fix:** Stale RFQ/quote state checks now use `SupplierQuoteStateConflictException`, rendered as HTTP 409 `supplier_quote_state_conflict`. The portal invalidates the central `supplier_rfqs_changed` query families on 409 and disables actions during the authorized refetch. Invitation checks remain ahead of state disclosure; invitation denials and quote-content/PDF validation keep their existing 422 behavior.
- **Verification:** The red-first API check failed on 422 as expected; after the fix the selected test passes with 1 warning / 3 assertions (the warning is the test container missing `/var/www/.env`). Targeted ESLint, PHP syntax, Pint, and `git diff --check` pass. No two-context portal/WebSocket run was performed; supplier RFQ matrix runtime cells remain `NOT_VERIFIED`.


## Buyer RFQ publish from stale detail returned 422 (reproduced; fixed in Delta 921)

- **Reproduction:** A focused red-first API regression created a Draft RFQ, changed the persisted status to Open after the buyer's detail snapshot, then posted `publish`. The endpoint returned 422 instead of the expected 409.
- **Cause:** The service's row lock/state revalidation was correct, but represented this stale state as generic `BusinessRuleException`; the controller mapped it to 422. The detail page only toasted mutation errors and did not invalidate its authorized RFQ queries on 409.
- **Fix:** Typed `RequestForQuoteStateConflictException` now returns HTTP 409 `request_for_quote_state_conflict`. Create/edit, detail, comparison, and manual-quote pages refresh their authorized RFQ families through the central map. Edit/manual-quote inputs stay mounted after the record becomes non-editable, and full RFQ success responses reconcile into the actor's detail cache before related families invalidate. Authorization and business validation behavior remain unchanged.
- **Verification:** The red-first API case first failed on 422, then passed after the fix (1 test / 5 assertions, one existing missing-container-`.env` warning). Targeted Pint/PHP syntax, ESLint, Vite module transforms, live HTTP checks, and `git diff --check` pass.
- **Limit:** No edit-form UI regression test, two-browser delivery, peer socket trace, list/badge/dashboard verification, or latency sample was captured. Matrix outcomes remain unchanged.

### Delta 922 — DSS stale recommendation actions surfaced generic 422; fixed with version conflict recovery

The focused two-identity API reproduction found a stale Accept request returned 422 because the service revalidated business state but accepted no expected version. The actor/detail UI's error path only toasted the response, so it could preserve stale status/actions when the refresh signal was missed. The API now returns a typed 409 for stale `expected_version`, and the SPA invalidates DSS queries on that conflict. The version field is optional in requests for existing-client compatibility. Focused API (1 test/5 assertions) and component (11/11) checks pass. Root cause #409 records evidence and limits; no two-browser or WebSocket result is claimed.

### Delta 923 — Return line Split button used client-derived eligibility

- **Reproduction:** A red-first component fixture gave the parent `available_actions.can_split=true` and the line `available_actions.can_split=false`. Before the fix, the Split button still rendered because the page checked local disposition and received quantity instead of a line action flag.
- **Cause:** The API projected Split only on the parent return; a row action tied to a specific line lacked an authoritative server action field.
- **Fix:** Each return line now carries `available_actions.can_split`, based on the viewer's parent action and persisted line eligibility. The row control and open split dialog use that flag. The locked service continues to check receipt, allocation, Quality evidence, and state before changing quantities.
- **Verification:** Focused SPA checks pass (3 selected / 7 skipped); the real detail endpoint projection regression passes (3 assertions, with the existing missing-container-`.env` warning). No two-browser delivery, socket trace, latency sample, or matrix promotion occurred.

### 411. Mapped option queries were excluded from realtime remount freshness (reproduced; fixed in Delta 924)

`isRealtimeManagedQueryKey()` checked the generic static-reference heuristic before the central invalidation prefixes. The heuristic classifies any key segment containing an `options` boundary as static, so a key can be both event-mapped and classified as non-live. The Return Case `['return-cases','source-options', ...]` regression reproduced that mismatch: the map included the source-options prefix, but the freshness predicate returned false. Purchase Order options provide a positive-`staleTime` consumer (five minutes) and are also in the settings invalidation map, so this ordering could suppress the intended `refetchOnMount: 'always'` after an inactive interval.

Delta 924 makes the central realtime map authoritative for remount freshness; only keys without a live invalidation prefix fall back to reference/options TTL classification. The Return Case and segmented Forecast query-key regressions pass (2 selected / 97 skipped), the source freshness audit passes with no unresolved/unmapped explicit positive `staleTime` keys, and targeted ESLint passes. No multi-user/browser/socket trace or cache/performance metric was collected; route-matrix outcomes remain unchanged.

## Return Case source picker could retain stale eligible quantities (refresh wiring added in Delta 926)

- **Evidence:** Source inspection found `cases/create.tsx` loaded Return Case source searches/options without mounting a realtime hook. Delivery and Purchase Order chain refreshes did not target the existing Return Case source channels; portal invalidation signals also omitted the source query prefixes. Before fixes, focused regressions failed respectively for source-family invalidation, the missing hook, and missing backend channel fan-out. The API rechecks eligibility/quantities at submission, so no business-rule bypass was observed.
- **Impact:** A warehouse or service user could keep seeing old source status/quantity in an open intake form until refetch or navigation, even though API submission remains authoritative.
- **Disposition:** The form now uses the shared internal/customer/supplier realtime subscription hook, centrally invalidates source search/options from Return Case, RMA, PO, receipt and chain events, and preserves draft field values while source query data updates. Delivery/PO chain pings target existing private Return Case channels authorized by `return_management.view`. Focused SPA checks (7 selected across map/hook/page) and one backend event check (6 assertions) pass, plus changed-file ESLint, PHP syntax and Pint. No two-browser/WebSocket observation, latency measurement, or matrix promotion was performed.

## Purchase Order refresh must not reach customer-only Return Management viewers (caught and fixed in Delta 926)

- **Evidence:** The source API explicitly returns 404 when a customer-service-only user requests supplier sources, but the first Delivery/PO source-refresh implementation targeted both `entity.return_case.customer` and `entity.return_case.all` for Purchase Order changes. A red-first unit regression failed because the Purchase Order refresh included the customer-only channel.
- **Impact:** The shared refresh envelope would have disclosed an opaque Purchase Order reference and change metadata to a scope that cannot fetch that supplier source. The API data itself remained protected; this was a channel-audience boundary defect caught before leaving the worktree.
- **Disposition:** Purchase Order chain changes now target only `entity.return_case.all`; Delivery changes target the customer and all-return audiences because both can fetch eligible Delivery sources. The focused regression passes (6 assertions), and the existing Return Case channel authorization test passes (12 assertions with a PHPUnit warning). No business data changed.


### 413. Purchase Request bulk approval left the actor list stale while refetch was pending (reproduced; actor cache reconciliation added in Delta 927)

`purchaseRequestsApi.bulkApprove()` returns a status for each PR, but the list mutation's success callback previously used those statuses only for its toast; the updated list depended on the invalidation refetch. A focused component regression held that GET open and reproduced `Pending` remaining on the actor's screen after the API result was `approved`. Delta 927 reconciles successful IDs across cached PR list filters immediately, updates status labels for retained rows, removes successes from pending/approval-inbox filtered lists, and clears the stale selected bulk-action toolbar; authorized refetch still reconciles the full resource. The regression passes after failing for the missing `Approved` label before the change, and verifies that the status-derived Convert to PO control updates while the selected approval toolbar clears. Targeted ESLint for the PR page/test and whitespace checks pass. Delta 929 subsequently closed the separate server-action projection gap on the paginated list (Finding 414). This remains mocked-API actor-component evidence only: no real endpoint execution, second browser, peer event, or latency sample was captured. Peer, badges, dashboard and approval-inbox behavior remains unverified.


### 414. Purchase Request list action visibility was inferred from client status (server projections added in Delta 929)

The paginated `PurchaseRequestResource` previously omitted `available_actions`, and the SPA inferred Convert to PO from status/source plus its local permission snapshot. The same page allowed bulk selection of every row, so a status/action change could leave a selected PR eligible for “Approve selected”. Delta 929 adds list `available_actions.can_convert` with the existing PO/RFQ eligibility rules and `can_approve` from the viewer's current pending approval step, using set-based query projections rather than per-row PO/RFQ/delegation checks. The SPA fails closed for both controls; DataTable now prunes only selections whose server action changed, preserving other eligible selections. The focused endpoint tests cover direct PO/live PO/active RFQ/handed-back RFQ, the current finance step, self-submission, and legacy list `actions: null` compatibility (2 tests / 9 assertions, existing container `.env` warning). The SPA file passes 3/3, including a selected-row eligibility change. Targeted Pint, PHP syntax, ESLint, and diff checks pass. This is API/component contract evidence, not two-user browser or WebSocket evidence; list-query latency and other list action projections remain unverified.

### 415. Credit Note stale controls and finalize conflict recovery

- **Reproduction:** A red-first SPA test supplied a finalized Credit Note with `available_actions.can_apply=false`; the page still rendered Apply because it inferred eligibility from local permission, status, and balance. A red-first API test finalized a draft and repeated finalize; the second request returned HTTP 422 instead of 409.
- **Cause:** Credit Note responses omitted viewer-scoped action flags, stale state-lock failures used a generic business-rule response, and mutation errors only toasted without refreshing.
- **Fix:** `CreditNoteResource` now returns `permissions.manage` and `available_actions.can_finalize` / `can_apply`. The page renders from these flags, fails closed, and makes an open Apply dialog non-actionable when the server revokes eligibility. Finalize/apply/void lifecycle conflicts retain locked state validation and return typed `credit_note_state_conflict` HTTP 409; the page invalidates its authorized detail/list family on conflict.
- **Verification:** Focused API checks pass (2 tests / 11 assertions) and the Credit Note detail file passes 7/7, including a 409 refetch while the stale finalize control remains pending. Targeted ESLint, PHP syntax, Pint, and `git diff --check` pass. PHPUnit reports the known missing-container-`.env` warning.
- **Limit:** No two-browser/socket delivery, latency, or cache-performance measurement was made. Action contract evidence is component/API level only; peer and dependent matrix surfaces remain `NOT_VERIFIED`.

### 416. HR profile-update review controls were inferred from cached status and local permissions

- **Reproduction:** A red-first SPA response fixture marked a pending Finance request with all current-viewer actions disabled; the page still rendered Finance Approve and Reject based on status and the local permission snapshot. A red-first API case had one reviewer approve a pending request, then repeated the stale approval; it returned 422 rather than a conflict response.
- **Cause:** `ProfileUpdateRequestResource` omitted viewer permissions and available actions, and the page kept a copied record in its confirmation dialog. Stale statuses were rejected, but the client treated them as ordinary errors and did not refresh the authorized queue.
- **Fix:** The resource now projects `permissions.review_hr` / `review_finance` and separate `can_review_hr`, `can_approve_finance`, and `can_reject_finance` flags. The page renders each action from the API, keeps only the request ID in confirmation state, disables confirmation while data refreshes or the current action is revoked, and refreshes the authorized queue after a typed `profile_update_request_state_conflict` HTTP 409. Service row-lock checks remain in place, and the existing rule that Finance approval must be performed by a different reviewer from HR approval is preserved.
- **Verification:** The focused SPA file passes 5/5; two API cases pass 9 assertions with the known missing-container-`.env` warning. Targeted ESLint, Pint, PHP syntax, and diff checks pass. The two route rows are `PARTIAL` for buttons/actions only.
- **Cache review:** The page query key is not explicitly user-prefixed. `authStore.applyUser()` clears the shared query client when identity or authorization scope changes; logout/401 and cross-tab session changes also clear it. This is source-traced; no multi-tab switch test was run.
- **Limit:** No two-browser WebSocket delivery, peer/list/badge timing, latency sample, or cache-performance measurement was collected. No cache code or keys changed; peer and dependent matrix surfaces remain `NOT_VERIFIED`.


## Maintenance Work Order completion race returned 422 and left a stale confirmation (fixed in Delta 932)

- **Reproduction:** A focused API regression completed an in-progress work order, then repeated the stale completion request. Before the fix, the locked state-machine validator returned 422 (`Completed` to `Completed`) instead of a stale-state conflict.
- **Cause:** The service reloaded and locked the current row, but delegated stale-state handling to the generic lifecycle validator; the desktop and tablet completion mutation handlers did not refetch on that 422.
- **Fix:** Completion now checks the locked state first and raises `MaintenanceWorkOrderStateConflictException`, which the controller renders as HTTP 409 `maintenance_work_order_state_conflict`. Both detail UIs close the stale confirmation and refetch the authorized detail on 409; open confirmations are disabled when a refetch marks completion unavailable. Successful actor responses still reconcile from the returned resource.
- **Verification:** The initial focused API test failed on 422; the focused rerun passes (3 assertions, with the known container `/var/www/.env` warning). Desktop/tablet component tests pass 8/8 and cover refetch after a mocked 409. No full suite, two-browser session, WebSocket trace, or latency measurement was run. The completion buttons/actions cell is `PARTIAL`; all other matrix surfaces remain `NOT_VERIFIED`. No cache or business data changed.


## Bill payment stale approve/reject/void actions returned generic 422 (typed 409 added in Delta 936)

- **Reproduction:** A focused Accounting API regression created a pending bill payment, approved it, then repeated the same approval from the stale snapshot. Before the fix, the service raised a generic `BusinessRuleException` without a conflict code.
- **Cause:** `BillService` rechecked the locked payment status correctly, but stale approve/reject/void states shared the generic 422 path. Bill detail already refetched its authorized detail after these mutation failures, so the missing contract was a typed conflict response.
- **Fix:** Added `BillPaymentStateConflictException` (`bill_payment_state_conflict`) and mapped stale approval, rejection, and void states to HTTP 409 in `BillController`. Existing authorization and business validation remain unchanged; bill detail's existing error path refetches current status/actions.
- **Verification:** The red-first focused test failed because the old exception had no conflict code. The targeted rerun passes 1 test / 5 assertions, including a repeated POST returning HTTP 409 with the stable error code/message. PHPUnit reports the known test-container `/var/www/.env` warning. No full suite or browser session was run.
- **Limit:** This verifies one stale approve endpoint through the API and source-maps the parallel reject/void branches; it does not verify WebSocket delivery, another role's live view, or latency. All three route matrix runtime cells remain `NOT_VERIFIED`; no cache or business data changed.


## MRB double release prevented duplicate stock movement but returned stale-state 422 (typed 409 and dialog recovery added in Delta 937)

- **Reproduction:** The existing `MrbDoubleReleaseRaceTest` retains two MRB model snapshots, commits the first release, then tries the second. Before the fix, the locked service guard correctly prevented a duplicate stock movement but returned a generic business-rule error with no stale-state code. A red-first focused assertion failed on the missing `material_review_record_state_conflict` code.
- **Cause:** The MRB service had correct lock/re-read protection, but stale status used the same 422 path as disposition validation. The SPA release dialog only applied field validation errors and did not refetch; the observer and authorized private channels were already present.
- **Fix:** Added `MaterialReviewRecordStateConflictException`; both the preflight and locked MRB status guards now report the stable code, and `MrbController` maps it to HTTP 409. On that code, the dialog refetches the MRB and related mapped queries, preserves entered release details, and disables submission while data is refreshing or the server removes `can_release`.
- **Verification:** The red-first test failed on the absent code. The targeted rerun passes 1 test / 5 assertions, including an authenticated release retry returning HTTP 409. Existing fixture logging warns that account 5010 is unavailable for movement GL posting; this test only verifies duplicate release prevention and the conflict response. No full suite or browser pair ran.
- **Limit:** No component test or two-user WebSocket/rendered update was run; the MRB route matrix remains `NOT_VERIFIED`. No cache or business data changed.


## CAPA effectiveness actions trusted local state and stale verdicts returned generic 422 (server projection and 409 recovery added in Delta 938)

- **Reproduction:** A red-first API regression fetched a closed NCR and found no `available_actions.can_verify` in its corrective action resource. After an effective verdict was recorded, a stale second verdict returned HTTP 422 with no stable conflict code. The service already row-locks and re-reads the action and NCR.
- **Cause:** The CAPA detail UI combined a local `quality.ncr.manage` check with action status instead of using a server projection. A key containing the action status also remounted the panel when realtime refetched, which could discard typed verification notes. The generic state-machine error did not distinguish a stale terminal action from ordinary invalid input.
- **Fix:** `NcrActionResource` returns viewer-scoped permission/action flags, the NCR API loads parent state for the projection, and the detail displays `Record verdict` only when `available_actions.can_verify` is true. The stable action ID preserves panel/form state through prop updates. The service reports final verdict/NCR state conflicts as `ncr_action_state_conflict` HTTP 409; that code invalidates the authorized NCR query family after a stale submit.
- **Verification:** The focused API regression failed first on missing `can_verify`, then passes 6 assertions covering availability before verification, removal after a terminal verdict, and the repeated endpoint returning HTTP 409. PHP syntax, targeted ESLint, `git diff --check`, Vite transformation, and `/api/v1/health` pass. The PHPUnit container emits its existing fixture warning. No browser pair or live peer WebSocket/rendered update was run; all verify-route matrix cells remain `NOT_VERIFIED`. No cache or business data changed.


## Realtime observer registration guard did not check for a lifecycle hook or signal call (guard strengthened in Delta 939)

- **Reproduction/evidence:** The contract test only checked that each `*RealtimeObserver` class appeared in an application service provider. A source scan found 105 such classes; all currently contain Eloquent lifecycle methods and a signaling/invalidation call, but the test would have accepted an empty or disconnected class.
- **Fix:** The contract now requires a recognized lifecycle hook and a named signal/cache-invalidation call for every discovered observer, in addition to registration. The guard still does not prove every route reaches a producer or that the event is delivered to another user's UI.
- **Verification:** `RealtimeMutationCoverageContractTest.php` passes 2 tests / 4,020 assertions with two existing container file warnings. No runtime service or business data was changed, and no matrix outcome was promoted.


## NCR close/cancel/disposition stale terminal actions returned generic 422 (typed 409 recovery added in Delta 940)

- **Reproduction:** A red-first authenticated API regression closed a valid NCR, then retried Close using the stale screen state. The locked service guard prevented a second transition but returned HTTP 422 with `NCR is already closed.` The same terminal guard applied to Cancel and Set Disposition.
- **Cause:** Terminal-state checks correctly protected the record, but their generic business-rule response was indistinguishable from invalid input. The NCR detail error handlers only displayed a toast; an open stale Close/Cancel confirmation could remain actionable until another refetch.
- **Fix:** Close, Cancel, and Set Disposition terminal guards now throw `NcrStateConflictException`; their controller paths return `ncr_state_conflict` HTTP 409. The SPA invalidates the authorized NCR query family on that code, disables confirmations while fetching or when the server removes the action, and keeps the cancel reason in the open dialog.
- **Verification:** The focused race/API regression passes 1 test / 6 assertions, exercising stale Close, Set Disposition, and Cancel requests against a closed NCR. Pint, PHP syntax, targeted ESLint, `git diff --check`, Vite transform, and `/api/v1/health` pass. No two-user browser or rendered peer update was run; the three route matrix rows remain `NOT_VERIFIED`. No cache or business data changed.


## Purchase Request create confirmation timeout was not reproduced in a follow-up diagnostic

- **Evidence:** One four-context Playwright attempt clicked “Submit for approval,” then timed out waiting for the confirmation dialog; its trace shows no Purchase Request POST. A separate single-context, no-write browser diagnostic filled the same valid form and opened the confirmation dialog. Department, item, and Purchase Request option lookups returned HTTP 200, there were no invalid fields or browser errors, and the diagnostic intentionally did not confirm. The original run artifact remains at `/tmp/ogami-pr-final-approval/purchase-request-final-app-66ec5-l-and-removes-stale-actions-real-chromium/error-context.md` with `trace.zip` beside it.
- **Realtime observation:** That page made 26 broadcast-auth requests for 26 distinct private channels; no duplicate channel authorization was observed in this one page load. The SPA registry is ref-counted, but this sample does not prove there are no duplicate registrations during other route/remount sequences.
- **Disposition:** The initial timeout's cause remains undetermined and is not treated as a confirmed product bug or realtime failure. No Purchase Request mutation was sent, so this adds no actor, peer, or latency evidence and does not change matrix status. No local business record or cache entry changed.


## The shared broadcaster did not restrict event payload fields (transport allowlist added in Delta 945)

- **Reproduction:** A red-first unit test sent a refresh event carrying fake `customer_email`, `amount`, and `status` fields. The mocked Pusher transport received all three fields because the common broadcaster normalized metadata but retained arbitrary payload keys.
- **Cause:** Refresh normalization was not a payload security boundary. A future or legacy broadcast event could accidentally include record fields, even though clients are intended to refetch through their authorized API.
- **Fix:** `StandardizedPusherBroadcaster` now filters normalized payloads through a shared allowlist. It preserves opaque entity/actor/related identifiers and legacy aliases, notification `id`/`type`, cache `group`, restore metadata, and the Pusher socket exclusion token; arbitrary record fields are dropped. Existing frontend event handlers use only allowed metadata. A source scan found 128 direct broadcast event classes, all with explicit `broadcastWith()` methods and no unallowlisted literal fields. E-mail snapshots and RFQ lifecycle snapshots belong to non-broadcast events.
- **Verification:** The test failed before the fix, then the focused transport contract file passed 7 tests / 17 assertions in 3.49 seconds (7 PHPUnit warnings). It verifies sensitive fields are omitted, the socket exclusion is preserved as Pusher's `socket_id`, and an oversized allowed ID is still rejected. This is a mocked transport boundary check; no real sensitive event was observed on Reverb and no route-matrix cell changed.


## Reconnect/focus catch-up refetched active unmapped reference data (scoped to mapped freshness families in Delta 946)

- **Evidence:** Source inspection showed `catchUpRealtimeQueries()` invalidated all active queries for focus, reconnect, and degraded-mode recovery, including reference queries not present in the realtime invalidation map. The focused regression covers an Inspection detail, a dynamic sales-order fulfillment query family, and a Production downtime-category lookup; the expected catch-up refreshes the first two and leaves the third cached.
- **Cause:** The shared catch-up path did not use the central query-family map, so stale recovery treated static/unmapped cache entries like transactional live data.
- **Fix:** Catch-up invalidation and both overlapping-read retry paths now target only entity/signal/cache-group prefixes registered in the central map, including derived ID-free prefixes for dynamic related-detail keys. Query freshness classification calls the same prefix check.
- **Verification:** Four selected catch-up/refetch-race tests pass; targeted ESLint passes on the three modified SPA files. No request-rate benchmark, browser-pair delivery, or matrix outcome change is claimed. Existing cache entries were not removed.


## Completed HR Finance approvals remained in the actor's actionable inbox until refetch (fixed in Delta 966)

- **Reproduction:** A focused component test loaded the Finance `awaiting_my_action` queue, returned an approved record with all server actions revoked from the mocked mutation API, and held the subsequent list GET pending. The completed employee row and stale request count remained visible during that interval.
- **Cause:** The shared mutation success callback replaced the matching row in every cached profile-update list without reconciling the row against that query's action/status filter. The event-driven API refetch eventually corrected the queue, but a delayed read left the acting user looking at a request they could no longer act on.
- **Fix:** The callback now removes a row from `awaiting_my_action` when the API response has no available actions for this viewer, and removes it from a status-filtered list when its new status no longer matches. It adjusts the visible total immediately and retains the authorized background refetch. The unfiltered list continues to show the returned server record.
- **Verification:** The red-first focused Vitest case failed because the row remained visible; after the fix it passes (1 selected test). Targeted ESLint and `git diff --check` pass. Only the mocked Finance actor component path is verified. No API write, second browser, peer WebSocket delivery, HR-review endpoint execution, or latency sample was run. This finding is fixed in the current working change; no separate backend/business-rule change was made.


## Scheduler move response was dropped from the actor's cached Gantt row (fixed in Delta 986)

- **Reproduction:** A focused SPA component test mocked a successful move response and held the invalidated scheduler snapshot GET pending. Before the fix, the actor continued to see the prior schedule date.
- **Cause:** The response updater replaced the moved bar but decided whether to keep the new row by comparing old and new bar counts. Replacing one item preserves the count, so it returned the original row and discarded the server response.
- **Fix:** The updater tracks whether the target schedule is in each row and retains that row when it changes. The API client unwraps the typed response and the actor renders the returned schedule without waiting for WebSocket/refetch.
- **Verification:** The selected regression passes, proving only the mocked-API actor page path while the subsequent read is pending; changed-file ESLint passes. The entire existing schedule page test file produced 3 passes and 4 failures in unrelated query/DOM expectations; no baseline comparison was made. No endpoint execution, second browser, or WebSocket delivery was tested.
