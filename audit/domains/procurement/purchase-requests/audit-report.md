# M036 Audit Report — Purchase Requests

Session: 2026-08-24  
Status at handoff: 📋 Plan Ready  
Audit passes: discovery, hardening, polish

## Scope and evidence

Audited the wired purchase-request API, persistence, approval/conversion flow, MRP and reorder callers (read-only dependency context), SPA list/create/detail surfaces, migrations, tests, and the documented process/design rules. No files outside this module's audit folder were changed.

The focused PHP feature tests could not start because the configured PostgreSQL host `db` did not resolve. The focused SPA test could not start because the existing root-owned `spa/node_modules/.vite-temp` directory denied the current user write access. PHP syntax checks for the core service/model/controller passed. Findings below are therefore source-evidence findings pending database/browser verification.

## Discovery

- The API and SPA routes are wired for create, draft update/delete, submit, budget acknowledgement, approve/reject/cancel, bulk approval, PDF, and PO conversion (`api/app/Modules/Purchasing/routes.php:20-38`; `spa/src/routes/purchasingRoutes.tsx:29-34`).
- The purchase-request template API and SPA routes are deliberately hidden, but `template_id` remains accepted and stored (`api/app/Modules/Purchasing/routes.php:40-54`; `api/app/Modules/Purchasing/Requests/StorePurchaseRequestRequest.php:24-30`). See F-010.
- The approval workflow is a four-role chain with a string money threshold at the final step (`api/database/seeders/WorkflowSeeder.php:58-65`).
- Existing tests cover basic creation, submission, role rejection, conversion, and hash-id decoding, but do not cover row-level department access, direct show/PDF access, money boundaries, generated-PR budget routing, concurrent submission, or the suggested-vendor API/UI contract (`api/tests/Feature/Purchasing/PurchaseRequestTest.php:21-34`; `api/tests/Feature/Purchasing/PurchaseRequestTemplateHashIdTest.php:40-69`).

## Findings

### F-001 — Department row-level authorization is missing

Classification: **Broken**  
Severity: P1  
Session recommendation: separate-recommended

`PurchaseRequestService::list()` gives every user with `purchasing.pr.approve` the unfiltered global list (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:63-74`). The role seeder describes department heads as approving PRs “for their department” and explicitly says list/show remain department-scoped (`api/database/seeders/RolePermissionSeeder.php:670-683`). `show()` and PDF only require the coarse `purchasing.view` permission and do not receive the authenticated user for a row check (`api/app/Modules/Purchasing/Controllers/PurchaseRequestController.php:37-45,67-70`; `api/app/Modules/Purchasing/routes.php:25-27`). Approve/reject, update/delete, submit, cancel, and conversion similarly rely on route permission plus status/approval-role checks, without a department/ownership policy (`api/app/Modules/Purchasing/Controllers/PurchaseRequestController.php:78-134`; `api/app/Modules/Purchasing/Services/PurchaseRequestService.php:162-214,346-447`).

Impact: a department head can enumerate, open, print, and attempt to act on another department's PR. `pendingCount()` is also global for the current role and has no department filter (`api/app/Modules/Purchasing/Controllers/PurchaseRequestController.php:170-182`). Keep explicit cross-department authority for system administrators/purchasing officers if intended, but enforce the policy server-side on every row-based read and action.

### F-002 — Purchase-request totals reintroduce float money math into approval routing

Classification: **Broken**  
Severity: P1  
Session recommendation: separate-recommended

`PurchaseRequest::totalEstimatedAmount()` casts the database sum to float (`api/app/Modules/Purchasing/Models/PurchaseRequest.php:101-107`), and each line's `estimated_total` accessor does the same (`api/app/Modules/Purchasing/Models/PurchaseRequestItem.php:43-48`). The service passes that total into budget assessment and approval submission, compares it to the auto-approval threshold as a numeric value, and the urgent path reads a float cap (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:216-252,288-295`). This violates the repository money contract: `Money` requires decimal strings and says never to use floats (`api/app/Common/Support/Money.php:7-12`), while `ApprovalService` documents that its amount is a decimal string because threshold boundaries are precision-sensitive (`api/app/Common/Services/ApprovalService.php:20-36`).

Impact: a PR near the ₱50,000 approval boundary, auto-approval threshold, or budget limit can be routed to the wrong approval path. Replace the source total and all threshold comparisons with exact decimal/centavo arithmetic and add boundary tests.

### F-003 — MRP and reorder-generated PRs can bypass budget enforcement

Classification: **Broken**  
Severity: P1  
Session recommendation: separate-recommended

Both internal PR producers write `department_id => null`: MRP comments that the department will be resolved at submit time (`api/app/Modules/MRP/Services/MrpEngineService.php:307-316`), and reorder automation does the same (`api/app/Modules/Inventory/Services/AutoReplenishmentService.php:93-103`). `PurchaseRequestService::submit()` calls `BudgetEnforcementService::assess()` only when the PR already has a department (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:216-220`). There is no resolution step before this guard.

Impact: an auto-generated PR can enter approval without budget availability/warning/acknowledgement being evaluated. Resolve a source department before submit, or route an unowned generated PR to an explicit manual-review state; never silently skip the budget gate. Test both MRP and reorder sources.

### F-004 — Manual PR lines may submit with a zero-valued estimate

Classification: **Incomplete**  
Severity: P1 if estimates are required; otherwise P2  
Session recommendation: separate-recommended

The store and update requests allow `estimated_unit_price` to be nullable and zero (`api/app/Modules/Purchasing/Requests/StorePurchaseRequestRequest.php:41-47`; `api/app/Modules/Purchasing/Requests/UpdatePurchaseRequestRequest.php:33-39`). The total treats null as zero (`api/app/Modules/Purchasing/Models/PurchaseRequest.php:101-107`), so the approval and budget amount gates can see a zero-valued request. The documented manual flow says users select estimated prices (`docs/PROCESS-FLOWS.md:476-480`), while the automatic converter rejects missing/zero prices only after approval and leaves the PR for manual conversion (`api/app/Modules/Purchasing/Listeners/ConsolidatePurchaseOrders.php:95-114`).

Decision needed: if an unknown price is valid, add an explicit “estimate pending/manual review” state and prevent approval/automatic conversion from treating it as zero. If it is not valid, require a positive estimate for every line and test the API, UI, and conversion path.

### F-005 — Submit is not lock-then-guarded and is not idempotent under concurrency

Classification: **Broken**  
Severity: P1  
Session recommendation: separate-recommended

`submit()` checks `Draft` before opening its transaction and never reloads the authoritative PR with `lockForUpdate()` (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:210-240`). Two concurrent requests can both pass the stale draft check and both run supplier prefill plus `ApprovalService::submit()`, which clears pending/skipped records and recreates the workflow records (`api/app/Common/Services/ApprovalService.php:38-68`). Approve and cancel already use the safer lock-then-guard pattern (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:346-357,427-437`), so submit is inconsistent with the rest of this state machine.

Impact: concurrent clicks/retries can rewrite approval history or produce nondeterministic submission state. Lock and re-check the PR inside the transaction, then add a concurrency/idempotency regression test.

### F-006 — Priority and urgent approval semantics are disconnected

Classification: **Incomplete**  
Severity: P2  
Session recommendation: separate-recommended

The submit branch skips the department-head step only when `is_urgent` is true (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:225-235`), but the public store rules do not accept `is_urgent` or `urgency_reason` (`api/app/Modules/Purchasing/Requests/StorePurchaseRequestRequest.php:33-47`). The SPA sends `priority` only (`spa/src/pages/purchasing/purchase-requests/create.tsx:112-129`) and tells users that Critical priority bypasses steps and notifies the VP (`spa/src/pages/purchasing/purchase-requests/create.tsx:371-387`). The two automated producers set `priority` to urgent/critical but do not set `is_urgent` (`api/app/Modules/MRP/Services/MrpEngineService.php:307-316`; `api/app/Modules/Inventory/Services/AutoReplenishmentService.php:93-103`).

Impact: the UI and automated priority labels do not produce the stated approval behavior. Decide whether `priority` or an explicit urgency flag is authoritative, then align validation, automation, workflow transitions, copy, and tests.

### F-007 — Persisted preferred suppliers are not returned to the conversion UI

Classification: **Incomplete**  
Severity: P2  
Session recommendation: same-session-ok for the isolated API/UI change

Creation and submit prefill `suggested_vendor_id` (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:127-154,323-337`), and the item resource exposes it only when the relation is loaded (`api/app/Modules/Purchasing/Resources/PurchaseRequestItemResource.php:28-31`). Neither list nor show eager-loads `items.suggestedVendor` (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:40-45,81-85`). The SPA expects that field to seed the vendor map (`spa/src/pages/purchasing/purchase-requests/index.tsx:125-131,238-252`).

Impact: manual conversion loses the persisted preferred-supplier default and forces re-selection. The queued converter reads the foreign-key column directly, so this finding affects the API/UI recovery path rather than that listener's own lookup. Eager-load the relation and add a resource/conversion regression test.

### F-008 — Approval queue counts and action visibility are too coarse for the role model

Classification: **Incomplete**  
Severity: P2  
Session recommendation: separate-recommended

The pending badge counts every pending record matching the user's role, without applying the department scope, self-submission exclusion, or active delegation logic used by `ApprovalService` (`api/app/Modules/Purchasing/Controllers/PurchaseRequestController.php:170-182`; `api/app/Common/Services/ApprovalService.php:82-106,172-205`). On the detail page, Approve/Reject are shown to anyone with `purchasing.pr.approve`, and Cancel is shown for every draft/pending PR without checking `purchasing.pr.create` (`spa/src/pages/purchasing/purchase-requests/detail.tsx:111-127`). The backend eventually rejects wrong-step/self actions, but the UI presents invalid actions and misleading counts.

Use a server-provided current-user action model (or the same policy query) for the badge and buttons. Keep backend authorization authoritative.

### F-009 — Automation/template references lack database integrity constraints

Classification: **Missing**  
Severity: P2  
Session recommendation: separate-recommended

The automation migration adds `purchase_requests.template_id` and `purchase_request_items.suggested_vendor_id` as bare unsigned integers with no foreign keys or indexes (`api/database/migrations/0152_add_pr_automation_fields_and_templates.php:13-24`). The models define relations to templates and vendors (`api/app/Modules/Purchasing/Models/PurchaseRequest.php:88-90`; `api/app/Modules/Purchasing/Models/PurchaseRequestItem.php:28-31`), so deleted/orphaned references can silently degrade resource output and automatic conversion. Add constraints/indexes after checking existing data and choose deliberate delete behavior.

### F-010 — The template contract is partially live but not implemented end-to-end

Classification: **Incomplete**  
Severity: P3  
Session recommendation: separate-recommended

Template routes/pages are hidden as a scope cut, but the request validator still decodes/accepts `template_id` and the service only stores it; it does not load the template or apply its department/items (`api/app/Modules/Purchasing/routes.php:40-54`; `api/app/Modules/Purchasing/Requests/StorePurchaseRequestRequest.php:24-30`; `api/app/Modules/Purchasing/Services/PurchaseRequestService.php:97-154`). The hash-id test supplies the template's items manually and asserts only the stored foreign key (`api/tests/Feature/Purchasing/PurchaseRequestTemplateHashIdTest.php:40-69`).

Product decision needed: remove the public template field and dead client contract, or re-enable a properly authorized template application flow with tests. Do not leave callers believing `template_id` applies a template when it does not.

### F-011 — Catalog-item financial fields are trusted from the client

Classification: **Incomplete**  
Severity: P2  
Session recommendation: separate-recommended

The SPA marks catalog description, unit, and standard cost read-only (`spa/src/pages/purchasing/purchase-requests/create.tsx:94-108,253-306`), but the service accepts client-supplied values whenever present and only falls back to the item master when omitted (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:122-151,178-200`). A direct API caller can therefore alter a catalog line's estimate/description, including lowering the amount used by budget and approval routing. Confirm whether catalog prices are editable estimates; if not, enforce the source-of-truth rule server-side and test both create and update.

### F-012 — Wide line-item tables have no responsive overflow treatment

Classification: **Polish**  
Severity: P3  
Session recommendation: same-session-ok

The create and detail pages render seven-column line-item tables directly without an `overflow-x-auto` wrapper (`spa/src/pages/purchasing/purchase-requests/create.tsx:221-341`; `spa/src/pages/purchasing/purchase-requests/detail.tsx:243-268`). This is likely to clip or force page-level horizontal scrolling at narrow widths. Confirm in a browser at mobile/tablet widths and add the module's standard responsive table wrapper if needed; retain the existing dense table tokens and accessible headers.

## Handoff decision

The majority of the plan is separate-recommended because it changes financial calculations, approval state transitions, row-level RBAC, or cross-module automation. No production fixes were applied in this session. The module is released as **📋 Plan Ready** after the action plan is recorded.
