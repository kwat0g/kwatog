# Fix Log — Quality / NCR + CAPA (M057)

## Session 2026-08-25

### Before

- Module status: `🔲 Not Started`; claim acquired with `./audit/scripts/claim-module.sh quality ncr-capa`.
- Application source was inspected read-only.
- Registry indicated this was the first dependency-ready Not Started module after all earlier modules were Plan Ready.

### Decision

No source fixes applied. The majority of findings require separate decisions or coordinated changes across state transitions, database constraints, notifications, RBAC, production work orders, and a new SPA CAPA workflow. Applying only a local patch would leave the module in a misleading partially-fixed state.

### After

- Audit report and ordered action plan written in this module directory.
- No application source or dependency files outside `audit/domains/quality/ncr-capa/` were modified; the mandated `audit/00-MODULE-REGISTRY.md` was regenerated.
- Verification completed:
  - PHP lint: passed.
  - Quality route registration: passed.
  - SPA TypeScript: passed.
  - Targeted SPA ESLint: passed.
  - 31 focused backend tests attempted; all stopped at database setup because host `db` could not resolve, with 0 assertions.

### Revisit trigger

Reopen after the state/notification/RBAC decisions are confirmed and a reachable test PostgreSQL service is available. Start with F-001/F-002 because escalation correctness affects every open NCR.

## 2026-08-25 interrupted-session recovery

The worker context ended after the audit artifacts were written but before the lock was released. No application source fixes were pending; the Plan Ready status and action plan are preserved for the dedicated implementation session.

## 2026-08-25 plan execution

This session resumed the existing action plan after claiming the module. The audit report and action plan were not rewritten; this section records the implementation delta.

### F-001 / F-002 — escalation state and delivery

- Before: escalation considered only open NCRs and advanced escalation_level before recipient resolution/notification.
- After: open and in_progress non-terminal NCRs without a Corrective action are row-locked; each tier has a durable idempotency/delivery row, empty audiences remain pending, and the tier advances only after the standard notification insert succeeds. Configuration errors are surfaced instead of swallowed.
- Evidence: api/app/Modules/Quality/Services/NcrEscalationService.php:41-57,60-218; api/database/migrations/0478_harden_ncr_capa_contracts.php:64-83; api/tests/Feature/Quality/NcrEscalationTest.php:70-88.

### F-003 — inspection-linked NCR idempotency

- Before: inspection failure used a read-then-create path without a database uniqueness guarantee.
- After: inspection_id has a nullable unique constraint; a uniqueness race is recognized for PostgreSQL and SQLite and returns the winning NCR.
- Evidence: api/database/migrations/0478_harden_ncr_capa_contracts.php:23-44; api/app/Modules/Quality/Services/NcrService.php:149-191,455-461.

### F-004 / F-005 — recurrence signature and retry workflow

- Before: recurrence used a truncated description prefix, and scan/notification failures were only logged.
- After: new NCRs persist a SHA-256 canonical signature derived from failed inspection measurements or the normalized full manual description. Legacy unsigned NCRs are recomputed from their linked inspection/description. A durable post-commit scan job owns retry state, separates lineage from notification, records an outbox event idempotently, and commits the notification-sent marker with the inbox rows.
- Evidence: api/app/Modules/Quality/Services/NcrRecurrenceDetector.php:32-75,83-153,163-221; api/app/Modules/Quality/Jobs/ProcessNcrRecurrenceScan.php:30-119; api/app/Modules/Quality/Models/NcrRecurrenceScan.php:16-27; api/app/Modules/Quality/Services/NcrService.php:101-141; api/tests/Feature/Quality/NcrRecurrenceTest.php:33-133.

### F-006 — CAPA verification transitions

- Before: any enum verdict could overwrite any action, including containment and open/cancelled NCRs.
- After: verification locks the action and NCR, requires a closed NCR and corrective/preventive action, requires notes, and enforces the explicit pending/ineffective-to-verdict transition table with terminal protection.
- Evidence: api/app/Modules/Quality/Support/NcrEffectivenessStateMachine.php:17-37; api/app/Modules/Quality/Services/EffectivenessService.php:78-126; api/app/Modules/Quality/Controllers/EffectivenessController.php:28-44; api/tests/Unit/Quality/NcrEffectivenessStateMachineTest.php:13-54; api/tests/Feature/Quality/NcrCapaEffectivenessTest.php:91-130.

### F-007 / F-014 / F-015 — CAPA scheduling, ownership, and coverage

- Before: due reminders repeated on every scheduler run without a ledger; action owner/due-date assignment was unavailable; no CAPA regression suite existed.
- After: due and overdue notifications use action/recipient/type/due-date idempotency rows and actionable NCR links; active owners and due dates are validated and exposed in the API/UI; coverage now includes scheduling, terminal/containment rejection, rollup, deduplication, in-progress escalation, and structured recurrence cases.
- Evidence: api/app/Modules/Quality/Services/EffectivenessService.php:181-309; api/database/migrations/0478_harden_ncr_capa_contracts.php:85-103; api/app/Modules/Quality/Requests/AddNcrActionRequest.php:13-39; api/app/Modules/Quality/Controllers/NcrController.php:71-89; spa/src/pages/quality/ncrs/detail.tsx:321-361,414-470; api/tests/Feature/Quality/NcrCapaEffectivenessTest.php:70-179.

### F-008 / F-010 — SPA CAPA and bulk close flows

- Before: the API had no normal SPA path for due checks, verification, or bulk close.
- After: the Quality SPA has a permission-aware CAPA due queue, detail-page verdict panels with stale-error handling, and selectable bulk close with confirmation, partial-result rendering, and cache invalidation.
- Evidence: api/app/Modules/Quality/routes.php:107-132; spa/src/api/quality/ncrs.ts:34-63; spa/src/routes/qualityRoutes.tsx:56-63; spa/src/pages/quality/ncrs/effectiveness.tsx:14-93; spa/src/pages/quality/ncrs/index.tsx:248-280.

### F-009 — return-to-supplier notification contract

- Before: the close path sent a legacy anonymous payload to inactive and active role users alike.
- After: it filters active recipients and uses the standard typed title/message/link/entity envelope inside the close transaction, so inbox rows roll back with a failed close and broadcast/email waits for commit.
- Evidence: api/app/Modules/Quality/Services/NcrService.php:430-452.

### F-011 / F-012 — request and frontend contract validation

- Before: list filters were passed through unvalidated, and the SPA source enum/CAPA fields drifted from the backend.
- After: list query/hash IDs/page sizing are validated in ListNcrRequest; frontend source and CAPA types match the emitted Quality resources.
- Evidence: api/app/Modules/Quality/Requests/ListNcrRequest.php:17-46; api/app/Modules/Quality/Controllers/NcrController.php:56-59; spa/src/types/quality.ts:151-219; api/app/Modules/Quality/Resources/NcrResource.php:68-76; api/app/Modules/Quality/Resources/NcrActionResource.php:25-44.

### F-016 — required production work orders

- Before: a missing Production service or failed work-order creation was swallowed, allowing a scrap/rework NCR to close without its traceability link.
- After: required scrap/rework work-order creation fails with a business error and the enclosing close transaction rolls the NCR back to non-terminal; the regression test now asserts that failure behavior.
- Evidence: api/app/Modules/Quality/Services/NcrService.php:304-368,394-428; api/tests/Feature/Quality/InspectionNcrTest.php:588-649.

### F-013 — deferred product decision

Production managers remain view-only for NCR/CAPA. The implementation keeps them as alert recipients but does not change RBAC outside this module. A product owner must decide whether that role should verify/manage CAPA or remain an observer; this is the remaining human-input item.

### Verification

- Passed: PHP syntax lint for the Quality module/tests; Quality NCR route registration; targeted NCR SPA ESLint; four unit state-machine assertions.
- Blocked: focused feature tests could not reach setup because PostgreSQL host db does not resolve (SQLSTATE[08006], 0 feature assertions).
- Not attributable to this module: full SPA typecheck still reports spa/src/pages/assets/detail.tsx missing qrcode types and duplicate JSX attributes in spa/src/pages/return-management/detail.tsx.

### Remaining items for re-audit

- F-013 requires the RBAC/product decision.
- F-015's database-backed delivery-failure, uniqueness-race, and CAPA assertions need to run against reachable PostgreSQL; the tests are present but were not verified in this environment.
